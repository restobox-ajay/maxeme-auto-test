<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\BackorderFulfillmentEntry;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\Warehouse;
use App\Repository\BackorderFulfillmentEntryRepository;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Opens and closes the durable queue rows behind /admin/backorders (#548).
 *
 * ## Why this is derived rather than called
 *
 * Six code paths can leave a line backordered — admin create, admin edit, checkout, quote
 * conversion, a manual release, an automatic one — and every one of them ends in a flush that
 * InventoryReconciliationSubscriber already watches. Asking each of them to also open or close a
 * queue row is six places to forget; reading the lines once per flush is none.
 *
 * So this does not track transitions. It states the rule — a line that is short and on an order
 * that owes goods has exactly one Open entry, and every other line has none — and makes the table
 * say that. A crashed request, a direct edit, a status change nobody routed through a release: all
 * of them converge on the next write to the order, the same way the bucket reconciliation does.
 *
 * ## Completing is not deleting
 *
 * An episode that ends is marked Complete, never removed, because the queue's whole purpose is to
 * show resolved episodes — an automatically-released one especially, which no human ever saw open.
 * Re-shortening a line later opens a NEW episode rather than reopening the old one: they are two
 * things that happened, and collapsing them would lose the first one's dates.
 */
final class BackorderQueueSynchronizer
{
    public function __construct(
        private readonly BackorderFulfillmentEntryRepository $entries,
        private readonly WarehouseFulfillmentRegionService $warehouses,
    ) {
    }

    /**
     * Brings the entries for one order in line with its lines. Flushes only when something moved.
     */
    public function sync(SalesOrder $order, EntityManagerInterface $entityManager): void
    {
        // An order that holds no stock owes no goods either — a Draft has promised nothing yet, and
        // voiding is the deliberate act that says the goods are no longer owed, missing ones
        // included. Same gate SalesOrderBackorderReservationSubject uses, for the same reason.
        $owesGoods = OrderInventoryBucketResolver::bucketForStatus($order->getStatus()) !== null;

        $changed = false;

        // The overwhelmingly common case, and the reason it is answered first: an order with
        // nothing short needs one query to confirm it has no open episodes, not one per line. This
        // runs on EVERY order write in the application, so a per-line lookup here would be a
        // per-line query on every order save in exchange for nothing.
        if (!$owesGoods || !$this->anyLineIsShort($order)) {
            foreach ($this->entries->openForOrder($order) as $open) {
                $entityManager->persist($open->complete());
                $changed = true;
            }

            if ($changed) {
                $entityManager->flush();
            }

            return;
        }

        $warehousesByLowerRegionName = null;

        foreach ($order->getLines() as $line) {
            $lineId = $line->getId();
            if ($lineId === null) {
                // Not yet written, so nothing can reference it. The flush that writes it queues the
                // order again, and this runs against the persisted row then.
                continue;
            }

            $open = $this->entries->openForLine($lineId);
            $shouldBeOpen = $owesGoods && $line->isBackordered();

            if ($shouldBeOpen && $open === null) {
                $product = $line->getProduct();
                if (!$product instanceof ProductCore) {
                    continue;
                }

                $warehousesByLowerRegionName ??= $this->warehouses->warehousesByLowerRegionName();
                $warehouse = OrderInventoryBucketResolver::resolveLineWarehouse(
                    $line->getLocation(),
                    $order->getFulfillmentRegion(),
                    $warehousesByLowerRegionName,
                );

                $entityManager->persist(
                    (new BackorderFulfillmentEntry())
                        ->setOrder($order)
                        ->setLine($line)
                        ->setProduct($product)
                        ->setWarehouse($warehouse instanceof Warehouse ? $warehouse : null),
                );
                $changed = true;

                continue;
            }

            if (!$shouldBeOpen && $open !== null) {
                $entityManager->persist($open->complete());
                $changed = true;
            }
        }

        if ($changed) {
            $entityManager->flush();
        }
    }

    /** Read off the lines already in memory — no query, which is the point of asking it first. */
    private function anyLineIsShort(SalesOrder $order): bool
    {
        foreach ($order->getLines() as $line) {
            if ($line->isBackordered()) {
                return true;
            }
        }

        return false;
    }
}
