<?php

namespace App\EventSubscriber;

use App\Entity\ProductCore;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Records an error_log row for every ProductCore that is *committed* without a sync_source.
 *
 * product_core.sync_source is not bookkeeping, it is what stops the import feeds trampling each
 * other. Number1RimImportBundle\Service\RimApiImportService scopes its own operations to
 * syncSource = SYNC_SOURCE — inactivateMissing() deactivates only the products the rim API owns,
 * precisely so a rim sync can never touch a tire or a CSV-imported product. A product whose source
 * is null is invisible to that scoping: nothing claims it, so nothing protects it. It can be
 * clobbered by a feed, or clobber one, and the only trace afterwards is a product that silently
 * changed owner.
 *
 * The check lives on a Doctrine listener rather than at each call site because a per-site check is
 * defeated by exactly the thing it guards against — a new creation path that forgets. #477 stamped
 * the four paths that existed when it was written; this covers the fifth, which nobody has written
 * yet. Every persist goes through here, including the ones cascaded from another entity.
 *
 * It only ever reads the product. Detecting an unstamped product and quietly stamping one are
 * different things, and only the first is wanted: a default invented here would be a guess written
 * into the column whose whole purpose is to be trustworthy, and it would hide the very call path
 * this exists to name.
 *
 * The column stays nullable and the save is allowed through. NOT NULL would abort a mid-run import
 * over a metadata field, and losing a nine-hundred-row import because someone forgot a constant is
 * a worse outcome than the missing value.
 *
 * ## What a row asserts, and why it is written late (#490)
 *
 * The rule is exactly:
 *
 *   a product committed with no sync_source  -> one row
 *   anything else                            -> nothing
 *
 * There is no "abandoned attempt" variant, no outcome column and no status wording, because the
 * row's existence *is* the assertion. A product that was rolled back cannot have a missing-source
 * problem: it does not exist, nobody created it, and whatever went wrong in that transaction is a
 * different fault with its own diagnostics. A row describing a product that is not in the database
 * is noise about an unrelated failure, pointing at code that did nothing wrong.
 *
 * The first version of this guard (#479) wrote the row from prePersist, on a connection of its own
 * so the insert would escape the caller's transaction. That logged intent rather than outcome — a
 * rolled-back product still left a row — and the second connection was a lock hazard besides:
 * SQLite takes one writer, so whenever a caller was already writing inside an explicit transaction
 * (Admin\OrderController does beginTransaction() ... flush()) the guard's connection sat out
 * SqliteWalMiddleware's 500ms busy_timeout, failed, and fell back to the caller's connection — a
 * stall per unstamped persist, and a fallback row that died with the rollback anyway.
 *
 * So it now buffers instead, and writes on the caller's own connection once no transaction is
 * active. Waiting for the outcome is what makes the row true; it also deletes the whole locking
 * story, since there is never a second writer to contend with.
 *
 * ## Why three hooks
 *
 * - prePersist captures. The trace is the point of these rows, and it can only be taken where
 *   persist() was called; by flush time that stack is gone. Nothing is written here.
 * - postFlush promotes what the flush actually inserted, then drains if the connection is free.
 *   postFlush fires after the ORM commits its own transaction, so in the ordinary case (no explicit
 *   transaction around the flush) the rows are on disk within microseconds of the products.
 * - kernel.terminate / console.terminate are the backstop for the case postFlush cannot serve: a
 *   caller holding an explicit transaction open across the flush, where postFlush still runs inside
 *   it. This is the first kernel.terminate listener in the application. It is the right place
 *   regardless: the response has already been sent, so a diagnostic write costs the user nothing,
 *   and it is the last moment at which the request's transactions are guaranteed finished. The
 *   console twin exists because imports — the paths most likely to trip this guard — run there and
 *   never touch the HTTP kernel at all.
 *
 * Precedent for the buffering half is InventoryReconciliationSubscriber, which already does
 * onFlush -> collect -> postFlush -> act.
 *
 * Entity-scoped (#[AsEntityListener]) for the capture rather than the #[AsDoctrineListener] style
 * used by the onFlush/postFlush subscribers next door: the capture cares about one entity class,
 * and an entity listener is only invoked for that class instead of on every persist of everything.
 * The drain half has no entity to hang off, so it is a plain Doctrine listener on the same service.
 */
#[AsEntityListener(event: Events::prePersist, entity: ProductCore::class)]
#[AsDoctrineListener(event: Events::postFlush)]
#[AsEventListener(event: KernelEvents::TERMINATE, method: 'onTerminate')]
#[AsEventListener(event: ConsoleEvents::TERMINATE, method: 'onTerminate')]
final class ProductSyncSourceGuard
{
    /**
     * error_log.area for these rows, so they filter as a group at /admin/error-log.
     *
     * Dotted identifier to match the convention AppSettings::logUnresolvedSender() set with
     * "mailer.from"; the request-scoped rows ErrorLogSubscriber writes use route names, which
     * cannot collide with this.
     */
    public const AREA = 'product.sync_source';

    /**
     * "warning", not "error": the product saved, and the request that triggered this did not fail.
     * What is broken is an invariant that will bite later, during some future import.
     */
    private const LEVEL = 'warning';

    /**
     * Frames whose class matches these prefixes sit between the persist() call and this listener —
     * the ORM's own plumbing plus this class. They are skipped when working out who to blame.
     */
    private const INTERNAL_CLASS_PREFIXES = ['Doctrine\\', self::class];

    /**
     * Frames kept in the recorded trace, counting from the persist() call outwards.
     *
     * Not unbounded: this writes one row per unstamped product, and a broken import can produce
     * hundreds in a run. Forty frames reaches the front controller or the console entry point from
     * anywhere in this application with room to spare, so the cap only ever bites on deep recursion
     * — where the frames past it are repeats of the ones already recorded.
     */
    public const MAX_TRACE_FRAMES = 40;

    /**
     * Hard ceiling on the rendered trace, matching the one ErrorLogSubscriber applies to exception
     * traces so /admin/error-log is never handed something larger than it already renders. Frame
     * count alone is not a size bound — a single frame carries a file path of unknown length.
     */
    public const MAX_TRACE_BYTES = 20000;

    /**
     * Ceiling on how many rows may be held in memory at once, awaiting the end of a transaction.
     *
     * Buffering means the guard now carries its rows for as long as the caller's transaction lasts,
     * so it needs a bound: a full test run persists 334 unstamped products, and an import inside one
     * long explicit transaction could persist more than that without ever giving the drain a chance
     * to run. A thousand entries covers the nine-hundred-row imports this application actually
     * performs, and costs a few megabytes at worst — each entry holds a trace capped at
     * MAX_TRACE_BYTES, though a realistic one is a tenth of that.
     *
     * On overflow the guard stops capturing and counts what it dropped; see drain() for what gets
     * written instead. Dropping the newest is deliberate: past the first thousand, the rows are
     * repeats of a path already named, and the alternative — evicting the oldest — would spend the
     * whole budget re-describing the same loop.
     *
     * Note that the cap is only reachable while something holds a transaction open. A caller that
     * flushes normally drains at each postFlush, so the buffer empties batch by batch.
     */
    public const MAX_BUFFERED_ROWS = 1000;

    /**
     * Ids per existence query at drain time, well under SQLite's default limit on bound variables.
     */
    private const ID_LOOKUP_CHUNK = 500;

    /**
     * Read directly rather than through the ORM metadata because the drain runs at moments when the
     * EntityManager may be unusable — a failed flush closes it, and a rollback is exactly the case
     * this check exists to answer.
     */
    private const PRODUCT_TABLE = 'product_core';

    /**
     * Captured at persist(), not yet known to have reached the database.
     *
     * Keyed by spl_object_id, and holding only a WeakReference to the product: an import that
     * persists in batches and clears the EntityManager between them must not have its products kept
     * alive by a diagnostic. A reference that dies before the insert belongs to a product that was
     * discarded before it was ever written, which is nothing to report.
     *
     * @var array<int, array{product: \WeakReference<ProductCore>, sku: string, row: array<string, mixed>}>
     */
    private array $captured = [];

    /**
     * Rows whose product has been inserted, waiting for the outermost transaction to finish so the
     * insert can be confirmed or discarded. The product itself is no longer referenced here.
     *
     * @var list<array{id: int, sku: string, row: array<string, mixed>}>
     */
    private array $inserted = [];

    /**
     * Unstamped persists seen while the buffer was full, and therefore never captured.
     */
    private int $dropped = 0;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Captures the row and the stack behind it. Reads the product; never writes to it, and never
     * touches the database.
     */
    public function prePersist(ProductCore $product, PrePersistEventArgs $event): void
    {
        $source = $product->getSyncSource();

        if ($source !== null && trim($source) !== '') {
            return;
        }

        try {
            if (count($this->captured) + count($this->inserted) >= self::MAX_BUFFERED_ROWS) {
                ++$this->dropped;

                return;
            }

            $sku = $product->getSku();

            $this->captured[spl_object_id($product)] = [
                'product' => \WeakReference::create($product),
                'sku' => $sku,
                'row' => $this->buildRow($product, $sku),
            ];
        } catch (\Throwable) {
            // A diagnostic must never be the reason a product fails to save. Nothing recorded here
            // is worth more to anyone than the persist that was in progress.
        }
    }

    /**
     * The flush has run its inserts and committed its own transaction. Anything captured earlier
     * that now carries an id reached the database, at least provisionally — provisionally because a
     * transaction the caller opened is still open, and can still take it away again.
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        try {
            $this->promote();
        } catch (\Throwable) {
        }

        $this->drain();
    }

    /**
     * Last call, on both kernels. Whatever transactions the request or command had are over, so
     * anything still buffered can be settled now.
     *
     * The capture buffer is emptied afterwards rather than carried forward: a product that was
     * persisted and never flushed before the process finished with the request was not saved, and
     * under a worker runtime (where this object outlives the request) keeping it would leak one
     * request's diagnostics into the next.
     */
    public function onTerminate(): void
    {
        $this->drain();

        $this->captured = [];
    }

    /**
     * Moves captures whose product has an id into the confirmable buffer, and forgets those whose
     * product has been garbage collected without ever being written.
     */
    private function promote(): void
    {
        foreach ($this->captured as $key => $entry) {
            $product = $entry['product']->get();

            if ($product === null) {
                unset($this->captured[$key]);

                continue;
            }

            $id = $product->getId();

            if ($id === null) {
                // Persisted, but not part of any flush yet. Still a candidate.
                continue;
            }

            $this->inserted[] = ['id' => $id, 'sku' => $entry['sku'], 'row' => $entry['row']];
            unset($this->captured[$key]);
        }
    }

    /**
     * Writes the buffered rows, on the caller's own connection and outside any transaction.
     *
     * Two things have to hold before anything is written, and both are the point of the rework:
     *
     * 1. No transaction is active. While one is, the outcome is undecided and an insert would join
     *    it — to be committed or rolled back with work that has nothing to do with this. Waiting is
     *    also what removes the need for a second connection: there is no lock to contend for once
     *    the writer has finished.
     *
     * 2. The product is still there. A commit is not observable from here, but its result is: a
     *    plain existence check on the caller's connection, after the transaction has closed, is the
     *    definitive answer to "was this product created?". Rows whose product is gone are dropped
     *    silently — that is the whole rule, applied at the only moment it can be evaluated.
     *
     * The check matches id *and* sku. Alone, a rowid is not a durable identity in SQLite: a
     * rolled-back insert releases its rowid, and a later insert can be handed it back. Pairing it
     * with the sku the guard recorded makes a false confirmation require a second product to have
     * taken both.
     *
     * Still never persist() + flush(): a flush inside somebody else's unit of work commits their
     * unfinished changes as a side effect, and postFlush is squarely inside one.
     * AppSettings::logUnresolvedSender() writes this same table this same way, for this reason.
     */
    private function drain(): void
    {
        if ($this->inserted === [] && $this->dropped === 0) {
            return;
        }

        try {
            if ($this->connection->isTransactionActive()) {
                return;
            }
        } catch (\Throwable) {
            return;
        }

        // Taken out of the buffer before the first write, not after the last: a database that is
        // failing must not leave the guard holding rows it will retry forever.
        $rows = $this->inserted;
        $dropped = $this->dropped;
        $this->inserted = [];
        $this->dropped = 0;

        try {
            $survivors = $this->skusStillOnDisk($rows);
        } catch (\Throwable) {
            return;
        }

        foreach ($rows as $entry) {
            if (($survivors[$entry['id']] ?? null) !== $entry['sku']) {
                continue;
            }

            $this->write($entry['row']);
        }

        if ($dropped > 0) {
            $this->write($this->overflowRow($dropped));
        }
    }

    /**
     * A logging failure never affects the caller, and one unwritable row never costs the rest.
     *
     * @param array<string, mixed> $row
     */
    private function write(array $row): void
    {
        try {
            $this->connection->insert('error_log', $row);
        } catch (\Throwable) {
        }
    }

    /**
     * @param list<array{id: int, sku: string, row: array<string, mixed>}> $rows
     *
     * @return array<int, string> id => sku, for the products that are actually in the table
     */
    private function skusStillOnDisk(array $rows): array
    {
        $ids = array_values(array_unique(array_column($rows, 'id')));
        $found = [];

        foreach (array_chunk($ids, self::ID_LOOKUP_CHUNK) as $chunk) {
            $result = $this->connection->fetchAllNumeric(
                'SELECT id, sku FROM ' . self::PRODUCT_TABLE . ' WHERE id IN (?)',
                [$chunk],
                [ArrayParameterType::INTEGER],
            );

            foreach ($result as [$id, $sku]) {
                $found[(int) $id] = (string) $sku;
            }
        }

        return $found;
    }

    /**
     * Builds the row. Reads the product; never writes to it.
     *
     * created_at is the moment of the persist, not of the eventual write: the drain can be a whole
     * request later, and the useful timestamp is the one that lines up with everything else the
     * offending code path did.
     *
     * @return array<string, mixed>
     */
    private function buildRow(ProductCore $product, string $sku): array
    {
        $origin = $this->callSite();

        $summary = sprintf(
            'ProductCore "%s" was persisted with no sync_source, by %s at %s:%d. A product with no '
                . 'recorded source is invisible to the syncSource scoping that keeps the import feeds '
                . "off each other's rows, so it can be overwritten or deactivated by a feed that does "
                . 'not own it. Stamp a source in that path.',
            $sku !== '' ? $sku : '(blank SKU)',
            $origin['caller'],
            $origin['file'] !== '' ? $origin['file'] : '(unknown file)',
            $origin['line'],
        );

        $payload = [
            'message' => $summary,
            'sku' => $sku,
            'name' => $product->getName(),
            'persistedBy' => $origin['caller'],
            'file' => $origin['file'],
            'line' => $origin['line'],
            'trace' => $origin['trace'],
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'level' => self::LEVEL,
            'area' => self::AREA,
            // The whole payload goes in error_log.message — the trace included, there being no
            // trace column and none being added for this. JSON rather than one flat string because
            // /admin/error-log already decodes this column and lifts "message", "file", "line" and
            // "trace" into their own fields, the last of which it renders as a <pre> block; a flat
            // string still displays, just as an undifferentiated blob. The human-readable summary
            // leads with the responsible application frame so it is not something you have to go
            // looking for in the trace.
            'message' => $encoded !== false
                ? $encoded
                : $summary . "\n\nStack trace:\n" . $origin['trace'],
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * The one row that is not about a product: it says how many unstamped persists went unrecorded
     * because MAX_BUFFERED_ROWS was reached, so an overflow is visible rather than silent.
     *
     * It carries no SKU and makes no claim that any particular product exists — which is why it is
     * not subject to the existence check the product rows are. Some of the persists it counts may
     * have been rolled back; what it asserts is only that the guard stopped looking.
     *
     * @return array<string, mixed>
     */
    private function overflowRow(int $dropped): array
    {
        $summary = sprintf(
            'ProductSyncSourceGuard stopped recording after %d buffered rows: %d further ProductCore '
                . 'persists with no sync_source were seen and not logged. The buffer only fills while a '
                . 'transaction is held open across many persists, so look for a long-running import or '
                . 'batch that never commits, and expect the rows that were recorded to name it.',
            self::MAX_BUFFERED_ROWS,
            $dropped,
        );

        $encoded = json_encode(
            ['message' => $summary, 'dropped' => $dropped, 'limit' => self::MAX_BUFFERED_ROWS],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return [
            'level' => self::LEVEL,
            'area' => self::AREA,
            'message' => $encoded !== false ? $encoded : $summary,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Names the code that called persist() and records the stack behind it, so the row identifies
     * the offending path outright instead of leaving someone to reproduce it.
     *
     * debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) rather than (new \Exception())->getTraceAsString():
     * getTraceAsString() renders call arguments — scalars inline, everything else as Object(Class) —
     * and the arguments here are entities, request payloads and whatever an import row happened to
     * contain. Those would land in a table rendered at /admin/error-log, for no diagnostic gain: the
     * question this answers is which code path ran, not what it was holding. IGNORE_ARGS drops them
     * at capture, so there is nothing to redact afterwards.
     *
     * The first frame belonging to neither Doctrine nor this class is the caller; everything nearer
     * is ORM dispatch. The file and line come from the frame just inside it, which is where
     * persist() was actually written — a frame's file/line describe its call site, not its body —
     * and the trace is rendered from that same frame outwards, so it starts at the persist() call
     * and keeps every frame behind it.
     *
     * @return array{caller: string, file: string, line: int, trace: string}
     */
    private function callSite(): array
    {
        // No frame limit: the cap belongs on what gets recorded, not on what gets looked at, or a
        // deep enough stack would hide the caller behind the ORM frames and report "unknown".
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        foreach ($frames as $index => $frame) {
            if ($this->isInternalFrame($frame)) {
                continue;
            }

            $class = (string) ($frame['class'] ?? '');
            $function = (string) ($frame['function'] ?? '');
            $callSite = $frames[$index - 1] ?? $frame;

            return [
                'caller' => $class !== '' ? $class . '::' . $function : $function,
                'file' => (string) ($callSite['file'] ?? ''),
                'line' => (int) ($callSite['line'] ?? 0),
                'trace' => $this->renderTrace(array_slice($frames, max(0, $index - 1))),
            ];
        }

        return [
            'caller' => 'unknown',
            'file' => '',
            'line' => 0,
            'trace' => $this->renderTrace($frames),
        ];
    }

    /**
     * @param array<string, mixed> $frame
     */
    private function isInternalFrame(array $frame): bool
    {
        $class = (string) ($frame['class'] ?? '');

        if ($class === '') {
            // A closure or plain function this far down is still ORM plumbing (or this class's own
            // helpers) rather than a meaningful culprit, so it is not treated as the caller.
            return true;
        }

        foreach (self::INTERNAL_CLASS_PREFIXES as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }

        // The container hands out a lazy ghost subclass of EntityManager whose generated name is
        // neither "Doctrine\..." nor stable across builds, so persist() would otherwise look like
        // the culprit. Anything that is an ObjectManager is plumbing whatever it is called.
        return is_a($class, ObjectManager::class, true);
    }

    /**
     * Renders the stack in PHP's own trace format, minus the arguments, so it reads the way an
     * exception trace at /admin/error-log already reads.
     *
     * Both caps announce themselves in the output. A trace that silently stops is worse than a
     * short one: the reader cannot tell a shallow call path from a truncated record of a deep one.
     *
     * @param list<array<string, mixed>> $frames
     */
    private function renderTrace(array $frames): string
    {
        $lines = [];
        $kept = array_slice($frames, 0, self::MAX_TRACE_FRAMES);

        foreach ($kept as $position => $frame) {
            $class = (string) ($frame['class'] ?? '');
            $type = (string) ($frame['type'] ?? '');
            $function = (string) ($frame['function'] ?? '{closure}');
            $file = (string) ($frame['file'] ?? '[internal function]');
            $line = (int) ($frame['line'] ?? 0);

            $lines[] = sprintf(
                '#%d %s%s: %s%s%s()',
                $position,
                $file,
                $line > 0 ? '(' . $line . ')' : '',
                $class,
                $type,
                $function,
            );
        }

        $dropped = count($frames) - count($kept);

        if ($dropped > 0) {
            $lines[] = sprintf('[trace truncated after %d frames; %d more]', self::MAX_TRACE_FRAMES, $dropped);
        }

        $trace = implode("\n", $lines);

        if (strlen($trace) > self::MAX_TRACE_BYTES) {
            $trace = substr($trace, 0, self::MAX_TRACE_BYTES) . "\n[trace truncated at " . self::MAX_TRACE_BYTES . ' bytes]';
        }

        return $trace;
    }
}
