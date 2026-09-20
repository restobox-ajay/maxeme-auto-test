<?php

declare(strict_types=1);

namespace WooCommerceBundle\EventSubscriber;

use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use WooCommerceBundle\Entity\WooCommerceConnection;
use WooCommerceBundle\Repository\WooCommerceConnectionRepository;
use WooCommerceBundle\Repository\WooCommercePushQueueItemRepository;

/**
 * Marks a (connection, product) pair dirty in the Push Queue the moment a stock bucket changes
 * (#739) — the event-driven trigger `WooCommercePushQueueItem`'s own docblock describes. Same
 * mechanism and same reasoning as `App\EventSubscriber\InventoryBucketChangeLogger` (onFlush, not
 * postUpdate, so the changeset hands over old/new for free and a new row can still be persisted
 * into the flush already running) — this is that listener's sibling for a different consumer of the
 * same changeset, not a modification of it: core has no reason to know WooCommerce exists, so this
 * lives in the bundle and reads the changeset independently.
 *
 * ## What "changed" means here
 *
 * `ProductInventory::getAvailableQuantity()`'s own formula, not `InventoryBucketChangeLogger::
 * BUCKET_FIELDS` — the two lists are close but not identical. `quantity` is IN the availability
 * formula but deliberately excluded from that logger's bucket list (it documents `quantity` as "the
 * client's own imported figure, not a bucket"); `incomingQuantity` is a PO forecast and is IN that
 * logger's list but NOT in the availability formula. This class watches what actually moves the
 * number pushed to Woo, which is neither list verbatim.
 *
 * ## Scope: only a product Woo already knows about
 *
 * This queues a push only when a WooCommerceProductMapping already exists for (connection, product)
 * — i.e. only for an item that connection has resolved before, normally because an order for it
 * already came through. A product newly listed only in this app's own catalogue, never yet ordered
 * via that connection, is NOT queued here: there is no Woo-side product id to push a quantity to
 * (see WooCommerceProductMapping::pushTargetId()). Keeping a connection's whole live catalogue in
 * sync ahead of any order — the "Product Sync" direction — is a different, larger feature this one
 * does not attempt; this is a genuine, disclosed scope boundary, not an oversight.
 *
 * ## Region, not warehouse, decides which connections care
 *
 * A connection's `defaultWarehouse` is resolved to a `FulfillmentRegion` via
 * `WarehouseFulfillmentRegionService::regionForWarehouse()` and compared against the changed
 * inventory row's OWN warehouse resolved the same way, rather than comparing warehouse ids directly
 * — `WarehouseFulfillmentRegion`'s own docblock states the warehouse-per-region mapping is enforced
 * 1:1 today by a unique index that could be relaxed later; going through the resolver both times
 * stays correct if that ever changes, where a direct warehouse-id comparison would not.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class WooCommerceInventoryChangeSubscriber
{
    /** Every ProductInventory field that feeds getAvailableQuantity() — see this class's own docblock for why this differs from InventoryBucketChangeLogger::BUCKET_FIELDS. */
    private const WATCHED_FIELDS = [
        'quantity',
        'receivedQuantity',
        'transferInQuantity',
        'transferOutQuantity',
        'writeOffQuantity',
        'quarantineQuantity',
        'cartHoldQuantity',
        'salesHoldQuantity',
        'pendingQuantity',
        'approvedQuantity',
        'shippedQuantity',
        'backorderedQuantity',
    ];

    public function __construct(
        private readonly WooCommerceConnectionRepository $connections,
        private readonly WooCommercePushQueueItemRepository $pushQueue,
        private readonly WarehouseFulfillmentRegionService $warehouseRegions,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();

        $dirty = [];

        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof ProductInventory && $this->hasWatchedValue($entity)) {
                $dirty[] = $entity;
            }
        }

        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof ProductInventory) {
                continue;
            }

            $changeSet = $unitOfWork->getEntityChangeSet($entity);
            foreach (self::WATCHED_FIELDS as $field) {
                if (isset($changeSet[$field])) {
                    $dirty[] = $entity;
                    break;
                }
            }
        }

        if ($dirty === []) {
            return;
        }

        // Active connections read fresh every flush rather than memoized on this listener: a
        // connection can be added or switched off mid-request-lifetime (an admin editing one in a
        // different tab, a test activating one), and this is a low-cardinality query — a handful of
        // rows, not thousands.
        $activeConnections = $this->connections->findAllActive();
        if ($activeConnections === []) {
            return;
        }

        $dbal = $entityManager->getConnection();

        foreach ($dirty as $inventory) {
            $product = $inventory->getProduct();
            $warehouse = $inventory->getWarehouse();
            if ($product === null || $product->getId() === null || !$warehouse instanceof Warehouse) {
                continue;
            }

            $region = $this->warehouseRegions->regionForWarehouse($warehouse);
            if ($region === null) {
                continue;
            }

            foreach ($activeConnections as $connection) {
                if (!$this->connectionServesRegion($connection, $region->getId())) {
                    continue;
                }

                if (!$this->hasMapping($dbal, $connection, $product->getId())) {
                    continue;
                }

                $this->pushQueue->markDirty($entityManager, (int) $connection->getId(), (int) $product->getId());
            }
        }
    }

    private function hasWatchedValue(ProductInventory $inventory): bool
    {
        foreach (self::WATCHED_FIELDS as $field) {
            $property = new \ReflectionProperty(ProductInventory::class, $field);
            if ((int) $property->getValue($inventory) !== 0) {
                return true;
            }
        }

        return false;
    }

    private function connectionServesRegion(WooCommerceConnection $connection, ?int $regionId): bool
    {
        if ($regionId === null) {
            return false;
        }

        $connectionRegion = $this->warehouseRegions->regionForWarehouse($connection->getDefaultWarehouse());

        return $connectionRegion !== null && $connectionRegion->getId() === $regionId;
    }

    /**
     * Raw DBAL rather than the ORM repository: this runs inside onFlush, where issuing a fresh ORM
     * query risks Doctrine trying to flush the in-progress UnitOfWork to keep the query consistent
     * — the same hazard ProductPrivacyLinker's own docblock describes for a write in this position,
     * here avoided for a read instead.
     */
    private function hasMapping(\Doctrine\DBAL\Connection $dbal, WooCommerceConnection $connection, int $productId): bool
    {
        return (bool) $dbal->fetchOne(
            'SELECT COUNT(*) FROM woocommerce_product_mapping WHERE connection_id = ? AND product_id = ?',
            [$connection->getId(), $productId],
        );
    }
}
