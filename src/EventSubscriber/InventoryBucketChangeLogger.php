<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\InventoryBucketChangeLog;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Service\Inventory\InventoryOperationContext;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Writes `inventory_bucket_change_log` for every bucket column on `product_inventory` that actually
 * changes, whoever changed it and whether or not they know this table exists (#582).
 *
 * ## What was wrong with asking callers
 *
 * Logging used to be a call site's job: `InventoryBucketAuditLogger::logChange()`, invoked next to
 * the write. Six buckets had callers that remembered — the reconcilers, the cart hold sync, the
 * product importer. The warehouse-side ones did not: StockMovementService::syncCoreTotal() writes
 * `received`, `write_off` and `quarantine` and logged none of them, and TransferOrderService writes
 * `transfer_out` and `transfer_in` and logged neither. On the development database that came to
 * 9,907 log rows, every one of them sell-side, and ZERO for any warehouse bucket: `received` only
 * ever reached the log when an import zeroed it, never when a receipt, transfer, adjustment or short
 * pick moved it.
 *
 * Adding the missing calls would have fixed those and nothing else, because the next person to write
 * a bucket would forget in the same way — and that is not a hypothetical either. This class was
 * first written against a branch without `write_off` or `transfer_in`, and shipped a list of nine
 * that was two short the moment it was rebased. So the logging moved INSIDE the operation, and the
 * list is now checked against the entity by a test rather than by whoever last edited it. A caller
 * changing a bucket cannot skip it, and does not have to know it happens.
 *
 * ## Why a listener has total coverage here
 *
 * Because nothing writes `product_inventory` outside the ORM at runtime — checked rather than
 * assumed. Every native `UPDATE product_inventory` in this repository is inside a migration, and the
 * one `executeStatement()` in InventoryDetailRepository::decrement() targets `inventory_detail`. So
 * the UnitOfWork sees every runtime change to this table, and this listener sees the UnitOfWork.
 *
 * ## Why onFlush and not preUpdate
 *
 * Two reasons, both structural rather than stylistic:
 *
 *  - the changeset hands over `previous -> new` for each bucket column for free, which is exactly
 *    the pair the log row wants. Deriving it any other way means remembering the old value
 *    somewhere, which is the bookkeeping this replaces;
 *  - the log rows are new entities that have to be INSERTed in this same flush. `onFlush` is the
 *    one event where an entity can still be persisted and change-set-computed into the flush that
 *    is already running. Under `preUpdate` they would have to wait for a second flush that nobody
 *    is obliged to perform.
 *
 * BundleBucketAvailabilityGate already listens on this exact entity and is the pattern followed
 * here — an entity-level decision made once, in one place, rather than at twenty-seven call sites.
 *
 * ## No changeset entry, no row
 *
 * A recompute that writes back the value already there produces no changeset entry, so it produces
 * no log row. That falls out of using the changeset rather than being coded for, and it is the
 * property that keeps this table readable: InventoryRecalcCommand rewrites every hold bucket on
 * every hourly run, and a log full of "5 → 5" would drown the changes worth finding.
 *
 * ## Every bucket, including cart_hold
 *
 * All twelve bucket columns are logged unconditionally. `cart_hold` is by far the noisiest — holds
 * expire after 300 seconds, and it was already 55% of the table before this listener existed — and
 * a per-bucket opt-out was considered for exactly that reason and deliberately NOT built. The
 * decision was to log everything; a configuration hook nobody is going to switch is speculative
 * generality, and retention is a retention problem, not a "decide what to record" problem.
 *
 * `incoming` has no writer in the application today. It is listed anyway because the cost of listing
 * a bucket nothing writes is zero rows, and the cost of omitting one that acquires a writer later is
 * another invisible gap of the kind this class exists to close — which is not hypothetical:
 * `quarantine` was in exactly that position one issue ago and StockMovementService::syncCoreTotal()
 * now recomputes it, alongside `write_off` (#581).
 *
 * The list below is enforced rather than trusted. InventoryBucketChangeLoggerTest reads the buckets
 * out of ProductInventory::getAvailableQuantity() by reflection and fails if any of them is missing
 * here, so a twelfth bucket added to that formula cannot go unlogged the way `write_off` and
 * `transfer_in` did while this listener was being written against an older base.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class InventoryBucketChangeLogger
{
    /**
     * Entity field => the bucket name stored in `inventory_bucket_change_log.bucket`.
     *
     * Every bucket column on ProductInventory has to appear here; one left out is silent, which is
     * the failure mode of the whole feature. Deliberately NOT derived from the metadata by matching
     * `*Quantity` — that would sweep in `quantity` and `reservedQuantity`, which are not buckets:
     * `quantity` is the client's own imported figure, and a log row saying it moved would be
     * recording the external system's opinion as though this application had a hand in it.
     */
    private const BUCKET_FIELDS = [
        'cartHoldQuantity' => InventoryBucketChangeLog::BUCKET_CART_HOLD,
        'salesHoldQuantity' => InventoryBucketChangeLog::BUCKET_SALES_HOLD,
        'pendingQuantity' => InventoryBucketChangeLog::BUCKET_PENDING,
        'approvedQuantity' => InventoryBucketChangeLog::BUCKET_APPROVED,
        'shippedQuantity' => InventoryBucketChangeLog::BUCKET_SHIPPED,
        'backorderedQuantity' => InventoryBucketChangeLog::BUCKET_BACKORDERED,
        'receivedQuantity' => InventoryBucketChangeLog::BUCKET_RECEIVED,
        'incomingQuantity' => InventoryBucketChangeLog::BUCKET_INCOMING,
        'quarantineQuantity' => InventoryBucketChangeLog::BUCKET_QUARANTINE,
        'transferOutQuantity' => InventoryBucketChangeLog::BUCKET_TRANSFER_OUT,
        'transferInQuantity' => InventoryBucketChangeLog::BUCKET_TRANSFER_IN,
        'writeOffQuantity' => InventoryBucketChangeLog::BUCKET_WRITE_OFF,
    ];

    /**
     * Memoized reflection for the bucket fields, built once per process.
     *
     * Only insertions need it — an update reads both numbers straight off the changeset — but an
     * import creating ten thousand ProductInventory rows would otherwise construct a hundred and ten
     * thousand ReflectionProperty objects for twelve getters' worth of work.
     *
     * @var array<string, \ReflectionProperty>
     */
    private array $properties = [];

    public function __construct(private readonly InventoryOperationContext $operations)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();

        /** @var list<InventoryBucketChangeLog> $entries */
        $entries = [];

        // A brand-new ProductInventory row counts as a change from nothing. The reconcilers create
        // the row and fill a bucket in the same breath — `findOneBy(...) ?? new ProductInventory()`
        // — so treating insertions as "not a change" would lose the first hold on every product/
        // warehouse pair, which is precisely the one somebody investigating drift goes looking for.
        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof ProductInventory) {
                $entries = [...$entries, ...$this->entriesForInsertion($entity)];
            }
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof ProductInventory) {
                $entries = [...$entries, ...$this->entriesForUpdate($entity, $unitOfWork->getEntityChangeSet($entity))];
            }
        }

        if ($entries === []) {
            return;
        }

        // Persisted AFTER both loops, never during them: persisting inside the iteration would be
        // modifying the very collections being walked. computeChangeSet() is what schedules an
        // entity persisted this late for INSERT in the flush that is already in progress — without
        // it the rows sit in the UnitOfWork until something else happens to flush, and a request
        // that ends here would drop them entirely.
        $metadata = $entityManager->getClassMetadata(InventoryBucketChangeLog::class);
        foreach ($entries as $entry) {
            $entityManager->persist($entry);
            $unitOfWork->computeChangeSet($metadata, $entry);
        }
    }

    /** @return list<InventoryBucketChangeLog> */
    private function entriesForInsertion(ProductInventory $inventory): array
    {
        $entries = [];

        foreach (self::BUCKET_FIELDS as $field => $bucket) {
            $newQuantity = $this->read($inventory, $field);

            // A new row's buckets are 0 unless somebody filled one, and 0 → 0 is not a change.
            if ($newQuantity !== 0) {
                $entry = $this->entry($inventory, $bucket, 0, $newQuantity);
                if ($entry instanceof InventoryBucketChangeLog) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     * @return list<InventoryBucketChangeLog>
     */
    private function entriesForUpdate(ProductInventory $inventory, array $changeSet): array
    {
        $entries = [];

        foreach (self::BUCKET_FIELDS as $field => $bucket) {
            if (!isset($changeSet[$field])) {
                continue;
            }

            [$previous, $new] = $changeSet[$field];
            $previousQuantity = (int) $previous;
            $newQuantity = (int) $new;

            // Doctrine only puts a field in the changeset when it differs, so this is belt and
            // braces — but the cast above can collapse 0 and null onto the same number, and a
            // "0 → 0" row would be exactly the noise the no-changeset-no-row property exists to
            // keep out.
            if ($previousQuantity === $newQuantity) {
                continue;
            }

            $entry = $this->entry($inventory, $bucket, $previousQuantity, $newQuantity);
            if ($entry instanceof InventoryBucketChangeLog) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Builds one row, or null when the change cannot be attributed to a warehouse.
     *
     * ProductInventory::$warehouse is nullable and this log's is not, which is not an oversight on
     * either side: a stock row with no warehouse is a legacy shape from before #546 split the
     * building out of the sales territory, and a change history entry that cannot say WHERE the
     * stock is answers none of the questions this table exists for. Skipping is the honest
     * response; throwing would take down an unrelated flush over a row nobody can interpret anyway.
     */
    private function entry(ProductInventory $inventory, string $bucket, int $previousQuantity, int $newQuantity): ?InventoryBucketChangeLog
    {
        $warehouse = $inventory->getWarehouse();
        if (!$warehouse instanceof Warehouse) {
            return null;
        }

        return (new InventoryBucketChangeLog())
            ->setProduct($inventory->getProduct())
            ->setWarehouse($warehouse)
            ->setBucket($bucket)
            ->setPreviousQuantity($previousQuantity)
            ->setNewQuantity($newQuantity)
            ->setAction($this->operations->currentAction())
            ->setTriggeredBy($this->operations->currentTriggeredBy())
            ->setGroupId($this->operations->currentGroupId());
    }

    /**
     * The bucket columns all have plain getters, but going through them by name would mean a match
     * arm per bucket duplicating the table above — two lists to keep in step, and the reason four
     * buckets went unlogged in the first place was a list somebody forgot to extend.
     */
    private function read(ProductInventory $inventory, string $field): int
    {
        $property = $this->properties[$field] ??= new \ReflectionProperty(ProductInventory::class, $field);

        return (int) $property->getValue($inventory);
    }
}
