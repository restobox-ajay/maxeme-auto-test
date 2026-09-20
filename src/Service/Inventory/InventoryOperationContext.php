<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The ambient "what is going on right now" for inventory bucket changes (#582).
 *
 * App\EventSubscriber\InventoryBucketChangeLogger writes an `inventory_bucket_change_log` row for
 * every bucket column that actually moved, straight off the Doctrine changeset. The changeset gives
 * it the product, the warehouse, the bucket and both numbers. It cannot give it `action`,
 * `triggered_by` or `group_id`, because those are facts about the OPERATION and no operation is
 * visible from inside a flush. This is where the operation puts them, once, for everything that
 * happens inside it.
 *
 * The point is that a caller changing a bucket does not know a log exists and cannot skip it. What
 * a caller does instead is say what it is doing:
 *
 *     $this->operations->run('import_approved_reset', function () use ($row, $em): void {
 *         $row->setApprovedQuantity(0);
 *         $em->flush();
 *     });
 *
 * ## THE ONE RULE: an operation must contain the flush it is describing
 *
 * This is the constraint the whole design turns on, and getting it wrong is silent. Doctrine cannot
 * tell anyone that a property was written; the earliest anything can observe a bucket change is
 * `onFlush`, when the UnitOfWork computes changesets. So the listener reads this context AT FLUSH
 * TIME, not at write time — and an operation that has already closed by then is not there to be
 * read. Its bucket changes fall back to `unattributed`, which is honest but useless.
 *
 * Concretely: `run()` must wrap the `flush()`, not just the setter. Where the flush belongs to a
 * caller further out, the operation goes further out too. Where two DIFFERENT actions have to be
 * distinguishable — the product importer's approved reset and its received reset are the live case
 * — each gets its own `run()` with its own flush inside it, rather than one operation covering both
 * and having to pick a name.
 *
 * The rejected alternative was to have ProductInventory's setters stamp the current operation onto
 * the entity as transient state, which would capture at write time and make flush placement
 * irrelevant. It was rejected because the entity would need the container to reach this service, so
 * it would have to be a static or a global — and a global mutable stack that survives between
 * requests in a Messenger worker is precisely the bug class this replaces.
 *
 * ## Nesting
 *
 * A stack, not a single slot. TransferOrderService::receive() opens `transfer_received` and calls
 * StockMovementService::apply(), which opens `movement_transfer` inside it; both are legitimately
 * open at once and describe different scopes of the same job.
 *
 *  - `action` and `triggeredBy` come from the INNERMOST open operation. The nearest description of
 *    what is happening is the most specific one.
 *  - `group_id` is searched from the innermost outwards, taking the first operation that has one.
 *    A nested operation with no group of its own inherits its parent's, which is what makes
 *    TransferOrderService::recomputeTransferBuckets() — run after apply() returned, in the outer
 *    operation — land on the same group as the movements that caused it.
 *
 * `run()` pops in a `finally`, so a throwing operation cannot leak a frame into whatever the
 * process does next. That matters more here than in a request-per-process world: a Messenger
 * consumer or a long import loop would otherwise attribute every later change to an operation that
 * died hours ago.
 *
 * ## The default when nothing opened an operation
 *
 * Deliberately not a crash and deliberately not an empty string. A bucket write with no operation
 * around it is a real change that really happened, and dropping it would put the log straight back
 * to being incomplete — which is the whole complaint #582 exists about. It is recorded as
 * `unattributed`, credited to the logged-in user if there is one, to the console command if this is
 * a CLI run, and to 'System' otherwise. `unattributed` is a findable string: a query for it is a
 * list of the bucket writers nobody has described yet.
 */
final class InventoryOperationContext
{
    /**
     * The action recorded when a bucket moves with no operation open.
     *
     * Not 'system' and not '' — this has to be greppable. `SELECT DISTINCT triggered_by FROM
     * inventory_bucket_change_log WHERE action = 'unattributed'` is the report that says which code
     * paths still write buckets without saying why.
     */
    public const UNATTRIBUTED = 'unattributed';

    /** Innermost last. @var list<InventoryOperation> */
    private array $stack = [];

    /**
     * The console command currently executing, if any.
     *
     * Read rather than guessed from $argv. `$argv[1]` is the command name under bin/console and is
     * a test-runner flag or a file path under PHPUnit and Codeception, so guessing would stamp
     * things like 'console:run' onto every log row written by the test suite — brittle, and a lie.
     * The event never fires outside a real Symfony command, so tests fall through to 'System',
     * which is exactly what InventoryBucketAuditLogger recorded before this existed.
     */
    private ?string $consoleCommand = null;

    public function __construct(private readonly Security $security)
    {
    }

    /**
     * Runs `$work` with `$action` as the ambient operation, and returns whatever it returns.
     *
     * `$work` receives the InventoryOperation so it can attach a movement group once it has one.
     * Most callers ignore the argument.
     *
     * Remember THE ONE RULE above: whatever `$work` does has to include the flush that writes the
     * bucket columns, or the operation will have closed before anything can read it.
     *
     * @template T
     * @param callable(InventoryOperation): T $work
     * @return T
     */
    public function run(string $action, callable $work, ?string $triggeredBy = null): mixed
    {
        $operation = new InventoryOperation($action, $triggeredBy);
        $this->stack[] = $operation;

        try {
            return $work($operation);
        } finally {
            array_pop($this->stack);
        }
    }

    /** The innermost open operation, or null when nothing opened one. */
    public function current(): ?InventoryOperation
    {
        return $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];
    }

    /** The innermost open operation's action, or self::UNATTRIBUTED. */
    public function currentAction(): string
    {
        return $this->current()?->action() ?? self::UNATTRIBUTED;
    }

    /**
     * The nearest attached movement group's id, searched innermost outwards; null when no open
     * operation has one.
     *
     * NULL is the meaningful answer for every sell-side change — a hold, a reconcile, an import
     * rebaseline. Those never open an operation with a group because no stock physically moved, so
     * the column is null by construction rather than by somebody forgetting to set it.
     */
    public function currentGroupId(): ?int
    {
        foreach (array_reverse($this->stack) as $operation) {
            $id = $operation->group()?->getId();
            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Who to credit. The innermost operation's own actor if it declared one, otherwise the
     * logged-in user, otherwise the console command, otherwise 'System'.
     *
     * Truncated to the column's 190 characters here rather than at the listener, so every caller
     * gets the same answer and no INSERT can fail on a long identifier.
     */
    public function currentTriggeredBy(): string
    {
        $declared = $this->current()?->triggeredBy();
        if (\is_string($declared) && trim($declared) !== '') {
            return mb_substr(trim($declared), 0, 190);
        }

        $user = $this->security->getUser()?->getUserIdentifier();
        if (\is_string($user) && $user !== '') {
            return mb_substr($user, 0, 190);
        }

        if ($this->consoleCommand !== null) {
            return mb_substr('console:' . $this->consoleCommand, 0, 190);
        }

        return 'System';
    }

    /**
     * Attributed to the command rather than to nobody, which is the honest answer for the recalc
     * crons: they run with no security token at all, and 'System' cannot tell the hourly inventory
     * recalc apart from a webhook.
     */
    #[AsEventListener(event: ConsoleEvents::COMMAND)]
    public function rememberConsoleCommand(ConsoleCommandEvent $event): void
    {
        $this->consoleCommand = $event->getCommand()?->getName();
    }

    /**
     * Forgotten again on the way out, so a command that internally runs another one does not leave
     * the second name standing for whatever follows.
     */
    #[AsEventListener(event: ConsoleEvents::TERMINATE)]
    public function forgetConsoleCommand(ConsoleTerminateEvent $event): void
    {
        $this->consoleCommand = null;
    }
}
