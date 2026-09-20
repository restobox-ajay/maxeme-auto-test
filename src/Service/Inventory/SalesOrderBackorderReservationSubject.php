<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\AbstractSalesDocument;
use App\Entity\InventoryReservation;
use App\Entity\OrderInventoryReservation;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\Warehouse;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The same sales order, seen from the other half of what it owes (#548): the units it has promised
 * that the warehouse does not have.
 *
 * ## Why a second subject and not a third ledger
 *
 * `InventoryReservationReconciler` already diffs a computed target against a durable ledger, and
 * #539 stage 3 put everything document-specific behind `InventoryReservationSubject` precisely so a
 * second thing needing that diff would not need a second copy of it. Backorder is a third thing
 * needing it, and the shape fits without a change to the reconciler beyond one arm on
 * applyBucketDelta(): the ledger rows are ordinary OrderInventoryReservation rows in a new bucket,
 * so nothing new is stored, nothing new is queried, and there is exactly one implementation of
 * "compare what a document should hold against what it does".
 *
 * The alternative — summing two buckets inside one subject and looping the reconciler twice — was
 * what an earlier draft of the plan described, written before the subject interface existed. It
 * would have put a second notion of "the target" inside the reconciler, which is the thing the
 * interface exists to keep out of it.
 *
 * ## The split is a split, not an addition
 *
 * SalesOrderReservationSubject nets the same numbers out of `sales_hold`, so the two subjects
 * together hold exactly the uninvoiced remainder the single subject used to hold on its own. That
 * is what lets ProductInventory::getAvailableQuantity() subtract the new bucket without changing
 * any existing number.
 */
final class SalesOrderBackorderReservationSubject implements InventoryReservationSubject
{
    public function __construct(private readonly SalesOrder $order)
    {
    }

    /**
     * The backorder bucket for exactly the statuses that hold stock, and none otherwise.
     *
     * Gated on the stock resolver rather than on a rule of its own: a Draft reserves nothing and
     * promises nothing, and a voided order has said the goods are no longer owed — including the
     * ones that never arrived. Two rules here could disagree; one cannot.
     */
    public function targetBucket(): ?string
    {
        return OrderInventoryBucketResolver::bucketForStatus($this->order->getStatus()) === null
            ? null
            : OrderInventoryReservation::BUCKET_BACKORDERED;
    }

    /**
     * The line's backordered quantity, whole and un-netted against invoicing.
     *
     * Deliberately NOT the uninvoiced part of it. A checkout order is invoiced in full the instant
     * it exists, so netting would drop every ecom backorder to zero the moment it was created —
     * and the promise is still outstanding whether or not the customer has been billed for it. The
     * invoice side is what gives way instead: see InvoiceReservationSubject::stockedQuantityFor().
     */
    public function heldLines(): iterable
    {
        foreach ($this->order->getLines() as $line) {
            $product = $line->getProduct();
            if (!$product instanceof ProductCore) {
                continue;
            }

            yield [
                'product' => $product,
                'location' => $line->getLocation(),
                'quantity' => $line->getBackorderedUnits(),
                // A backordered line has no stock behind it to pick a lot from, so this is null on
                // every line until BackorderReleaseService moves the units into sales_hold and a
                // human picks one — see section 4 of the 2026-09-14 lot/serial/expiry plan. Passed
                // through unconditionally rather than hardcoded null: nothing here decides that, the
                // line simply has nothing to report yet.
                'lot' => $line->getLotId(),
                'serial' => $line->getSerial(),
            ];
        }
    }

    public function fallbackRegionName(): ?string
    {
        return $this->order->getFulfillmentRegion();
    }

    /**
     * Scoped to this bucket, which is what makes two subjects over one table safe: each sees only
     * its own rows, so neither can remove or overwrite the other's. Every row written before #548
     * is `sales_hold`, so the other subject's view is unchanged by the scoping.
     */
    public function existingReservations(EntityManagerInterface $entityManager): array
    {
        return array_values($entityManager->getRepository(OrderInventoryReservation::class)->findBy([
            'order' => $this->order,
            'bucket' => OrderInventoryReservation::BUCKET_BACKORDERED,
        ]));
    }

    public function newReservation(ProductCore $product, Warehouse $warehouse): InventoryReservation
    {
        return (new OrderInventoryReservation())
            ->setOrder($this->order)
            ->setProduct($product)
            ->setWarehouse($warehouse)
            ->setBucket(OrderInventoryReservation::BUCKET_BACKORDERED);
    }

    /** Distinct from 'order_reconciled' so a change history says which of the order's two holds moved. */
    public function changeAction(): string
    {
        return 'order_backorder_reconciled';
    }

    public function document(): AbstractSalesDocument
    {
        return $this->order;
    }

    public function reference(): string
    {
        return $this->order->getOrderNumber();
    }
}
