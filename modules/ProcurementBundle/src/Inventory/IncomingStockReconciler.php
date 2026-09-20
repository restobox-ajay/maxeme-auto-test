<?php

declare(strict_types=1);

namespace ProcurementBundle\Inventory;

use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Repository\BundleStatusRepository;
use App\Service\Inventory\InventoryOperationContext;
use App\Service\QuantityScale;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Enum\PurchaseOrderStatus;

/**
 * Keeps `product_inventory.incoming_quantity` equal to what the open purchase orders still expect
 * (#564, given its writer by #583).
 *
 * ## What `incoming` means, and why it is not in availability
 *
 * On a purchase order, not yet arrived. It is a **forecast, not stock**, and it is absent from
 * `ProductInventory::getAvailableQuantity()` in every bundle state for that reason — the positive
 * twin of `backordered`, which is the same distinction on the sell side: one says "these units are
 * owed and do not exist yet", this says "these units are owed to us and do not exist here yet".
 * Adding it to the sum would let a purchase order sell units nobody has.
 *
 * That is also why this class is the only thing that can be right about the number: nothing
 * downstream would contradict a wrong value, because nothing else knows what was ordered.
 *
 * What it buys beyond the number itself is that a warehouse can be asked "is more of this already
 * on its way" without opening the purchase orders — the question #597's reorder points exist to
 * answer, and the reason this is a cached column on the inventory row rather than a query on the
 * arrivals screen. An open order also carries `purchase_order.expected_date`, so a derived arrival
 * date is available to whoever wants one; nothing derives one today, and `sales_order_line`'s
 * restock ETA is still a date an admin types.
 *
 * ## Derived, not incremented
 *
 * Every call recomputes the whole figure from the purchase order lines. That costs one query per
 * (product, warehouse) touched and buys the property that matters for a cached number: it is
 * **self-healing**. An increment/decrement pair drifts the first time an edit, a cancellation and a
 * receipt interleave in an order nobody anticipated, and a drifted forecast is invisible.
 *
 * The same choice `TransferOrderService::recomputeTransferBuckets()` makes for `transfer_out` and
 * `transfer_in` (#584), for the same reason and with the same consequence: there is deliberately no
 * `adjustIncoming()` to call, here or on the entity.
 *
 * ## It does not have to remember to log
 *
 * Nor could it. `App\EventSubscriber\InventoryBucketChangeLogger` writes
 * `inventory_bucket_change_log` for every bucket column that moves, straight off the Doctrine
 * changeset, so the write below is `setIncomingQuantity()` and nothing else (#582). What a caller
 * supplies instead is a NAME for what is happening, which is why the recompute and its flush both
 * sit inside one `InventoryOperationContext::run()` — the logger reads the ambient operation at
 * FLUSH time, not at write time, and an operation that closed before the flush is not there to be
 * read.
 *
 * `group_id` is deliberately left null. NULL means "no physical operation caused this", which is
 * exactly right for a forecast: no stock moved, a purchase order merely started or stopped
 * expecting some. The receipt that ran alongside it has its own movement group and its own rows.
 *
 * ## Gated on the bundle that writes it
 *
 * Inactive ProcurementBundle, no writes — the same self-check `CartHoldService` makes before it
 * touches `cart_hold`. Nothing is zeroed on the way out and nothing is recomputed on the way back
 * in: the rows sit untouched in between, exactly as `BundleBucketAvailabilityGate` leaves
 * `received`, whose flag — `positiveBucketsCount` — is this same bundle's.
 *
 * `incoming` is not a term in the availability sum in EITHER state, so there is no term for that
 * gate to add or remove for it. What the bundle's status decides for this column is whether the
 * forecast is still being maintained, and a reader that needs to know has `positiveBucketsCount()`
 * on the row to ask — the same flag, the same bundle, already stamped on every load.
 *
 * ## Which orders count
 *
 * `Issued`, `Partially Received` and `Received` — {@see PurchaseOrderStatus::acceptsReceipts()},
 * the same set the arrivals screen lists. The three that are excluded are excluded for reasons that
 * do not generalise:
 *
 *  - **Draft** has been sent to nobody, so nothing is expected from anybody.
 *  - **Cancelled** says nothing was ever ordered.
 *  - **Closed** is the interesting one: the remainder was deliberately written off, so it is no
 *    longer expected even though it was never delivered. A late delivery against it is still
 *    *recordable* — that is why `refusesReceipts()` is a narrower set than this — but it is not
 *    something to plan around, and leaving it in the forecast would keep a written-off shortfall
 *    quietly promising stock forever.
 *
 * ## Whole units
 *
 * Purchase quantities are `NUMERIC(14, 4)` to match `sales_order_line.quantity` (#645 widened both);
 * the inventory buckets are whole units — their columns widened with everything else, but the PHP
 * layer above them still counts in whole units until #646. A fractional outstanding is floored,
 * matching what `ReceivingService`
 * will actually be able to book in — it refuses a fractional line outright, so rounding UP here
 * would forecast a unit that can never arrive.
 */
final class IncomingStockReconciler
{
    /**
     * The ambient operation name every `incoming` change is recorded under (#582).
     *
     * One name for all three call sites — issue, receipt, close/cancel — because the log row already
     * says which way the number went and `inventory_bucket_change_log.triggered_by` already says who
     * moved it. What a name per action would add is a second place describing a purchase order's
     * lifecycle, and the order's own timeline is the first.
     */
    public const CHANGE_ACTION = 'purchase_order_incoming';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BundleStatusRepository $bundleStatuses,
        private readonly InventoryOperationContext $operations,
    ) {
    }

    /**
     * Recomputes `incoming` for every product this order names, in this order's warehouse.
     *
     * Takes the order rather than a product because that is what every caller has, and because the
     * set of rows an order can have changed is exactly its own lines.
     *
     * Flushes the caller's pending changes on the way in, for the reason below; it opens no
     * transaction, so a caller that has one still owns the outcome.
     */
    public function reconcileForOrder(PurchaseOrder $order): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        // Load-bearing, and for the reason StockMovementService and ReceivingService both flush
        // mid-transaction: the query below reads the DATABASE, and a lookup cannot see a change
        // that is only in the identity map. Every caller reaches here having just changed the very
        // thing being recounted — issue() has moved the status, a receipt has credited the lines —
        // so without this the recount would read the state from before the action and write back
        // the figure it was called to change.
        //
        // Outside the operation opened below, deliberately: whatever the caller left pending is the
        // caller's, and flushing it under the name `purchase_order_incoming` would be this class
        // taking the credit for somebody else's bucket change.
        $this->em->flush();

        $warehouse = $order->getWarehouse();

        $products = [];
        foreach ($order->getLines() as $line) {
            $product = $line->getProduct();
            // A free-text line — no product_id — is real paperwork and buys something the catalogue
            // does not carry. There is no inventory row for it to be incoming to.
            if ($product instanceof ProductCore && $product->getId() !== null) {
                $products[$product->getId()] = $product;
            }
        }

        $this->operations->run(self::CHANGE_ACTION, function () use ($products, $warehouse): void {
            foreach ($products as $product) {
                $this->recompute($product, $warehouse);
            }

            // Inside the operation, because the logger reads it at flush time rather than at write
            // time. See InventoryOperationContext: "an operation must contain the flush it is
            // describing".
            $this->em->flush();
        });
    }

    /**
     * Recomputes `incoming` for one product in one warehouse, and flushes it.
     *
     * The single-row entry point, for a caller holding a product rather than an order.
     */
    public function reconcile(ProductCore $product, Warehouse $warehouse): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->operations->run(self::CHANGE_ACTION, function () use ($product, $warehouse): void {
            $this->recompute($product, $warehouse);
            $this->em->flush();
        });
    }

    /**
     * Inactive means nothing writes this column. It does not mean the column is wrong.
     *
     * The rows keep whatever the last active period left on them, the way every other bundle-owned
     * bucket does, so switching procurement back on needs no recount and no re-import: the next
     * issue, receipt or close recomputes from the orders, which are still there.
     */
    private function isEnabled(): bool
    {
        return $this->bundleStatuses->isActiveForInstance($this);
    }

    /** The write itself. Does not flush — the operation around it does. */
    private function recompute(ProductCore $product, Warehouse $warehouse): void
    {
        $outstanding = $this->outstandingUnits($product, $warehouse);

        // Floored at zero: over-receipt makes a line's received exceed its ordered, and "we are owed
        // minus three" is not a forecast anyone can act on. The over-receipt itself is already
        // recorded on the line and surfaced by the three-way match.
        $incoming = QuantityScale::compare($outstanding, 0) > 0 ? $outstanding : QuantityScale::canonical(0);

        $row = $this->em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $product,
            'warehouse' => $warehouse,
        ]);

        if (!$row instanceof ProductInventory) {
            // Nothing expected and no row: a purchase order for a product never stocked here that
            // has already been fully received or written off. Creating a row of zeroes to say
            // nothing would be a row this app has to explain later.
            if (QuantityScale::compare($outstanding, 0) <= 0) {
                return;
            }

            $row = (new ProductInventory())->setProduct($product)->setWarehouse($warehouse);
            $this->em->persist($row);
        }

        // Nothing written when nothing moved, which is what keeps `inventory_bucket_change_log`
        // readable: no changeset entry, no log row. A recompute that agrees with the column is the
        // common case — every action on an order touches every product on it, including the ones
        // that action did not change.
        if (QuantityScale::compare($row->getIncomingQuantity(), $incoming) !== 0) {
            $row->setIncomingQuantity($incoming)->touch();
        }
    }

    /** What is still outstanding here, across every open purchase order. */
    private function outstandingUnits(ProductCore $product, Warehouse $warehouse): string
    {
        $statuses = array_values(array_filter(
            PurchaseOrderStatus::cases(),
            static fn (PurchaseOrderStatus $status): bool => $status->acceptsReceipts(),
        ));

        /** @var list<PurchaseOrderLine> $lines */
        $lines = $this->em->getRepository(PurchaseOrderLine::class)->createQueryBuilder('l')
            ->innerJoin('l.purchaseOrder', 'po')
            ->where('l.product = :product')->setParameter('product', $product)
            ->andWhere('po.warehouse = :warehouse')->setParameter('warehouse', $warehouse)
            ->andWhere('po.status IN (:statuses)')->setParameter('statuses', $statuses)
            ->getQuery()
            ->getResult();

        $total = QuantityScale::canonical(0);
        foreach ($lines as $line) {
            // Per line, not on the sum: an over-received line must not eat another line's genuine
            // shortfall. getQuantityOutstanding() already floors at zero for the same reason.
            $total = QuantityScale::add($total, $line->getQuantityOutstanding());
        }

        return $total;
    }
}
