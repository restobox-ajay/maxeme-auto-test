<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\AbstractSalesDocument;
use App\Entity\InventoryReservation;
use App\Entity\OrderInventoryReservation;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\Warehouse;
use App\Service\QuantityScale;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A sales order, as the reconciler sees it (#539 stage 3): it holds `sales_hold` on what it has NOT
 * yet billed.
 *
 * The quantity per line is the order's UNINVOICED remainder, not its ordered quantity — the two
 * halves of the answer the plan's status→bucket table gives, and the reason an abandoned ecom
 * checkout holds nothing without a special case. Such an order is fully invoiced from the instant it
 * exists, so every line's remainder is zero and the order holds nothing; its `On Hold` invoice holds
 * nothing either, and the stock is free. Draft invoices deliberately do not count as invoiced, so
 * drafting one leaves the quantity sitting here rather than escaping into neither ledger.
 *
 * The remainder is read from SalesOrder::uninvoicedQuantityFor(), derived on every read from the
 * invoice lines — never recomputed here. A second implementation of it would be a second answer to a
 * question the invoice lines already answer.
 */
final class SalesOrderReservationSubject implements InventoryReservationSubject
{
    public function __construct(private readonly SalesOrder $order)
    {
    }

    public function targetBucket(): ?string
    {
        return OrderInventoryBucketResolver::bucketForStatus($this->order->getStatus());
    }

    public function heldLines(): iterable
    {
        foreach ($this->order->getLines() as $line) {
            $product = $line->getProduct();
            if (!$product instanceof ProductCore) {
                continue;
            }

            // The STOCKED part of the remainder (#548). What a line has backordered is owed too,
            // but by SalesOrderBackorderReservationSubject and in its own bucket — subtracting it
            // here is what keeps the two a split of one quantity rather than two holds on the same
            // units. A line with nothing backordered subtracts zero, which is every line until a
            // SKU is opted in.
            // An exact decimal string, not `(int) round((float) …)`: an uninvoiced remainder of 0.4
            // used to hold nothing and one of 1.5 used to hold two whole units of real stock.
            $uninvoiced = $this->order->uninvoicedQuantityFor($line);
            $stocked = QuantityScale::sub($uninvoiced, $line->getBackorderedUnits());

            yield [
                'product' => $product,
                'location' => $line->getLocation(),
                'quantity' => QuantityScale::compare($stocked, 0) > 0 ? $stocked : QuantityScale::canonical(0),
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
     * Scoped to `sales_hold` since #548, when the order gained a second bucket on the same table.
     * Without the scope this subject would see the backorder rows as its own previous state and
     * delete them on the next reconcile. Every row written before #548 is `sales_hold`, so the
     * scope changes nothing about what this reads today.
     */
    public function existingReservations(EntityManagerInterface $entityManager): array
    {
        return array_values($entityManager->getRepository(OrderInventoryReservation::class)->findBy([
            'order' => $this->order,
            'bucket' => OrderInventoryReservation::BUCKET_SALES_HOLD,
        ]));
    }

    public function newReservation(ProductCore $product, Warehouse $warehouse): InventoryReservation
    {
        return (new OrderInventoryReservation())
            ->setOrder($this->order)
            ->setProduct($product)
            ->setWarehouse($warehouse)
            ->setBucket(OrderInventoryReservation::BUCKET_SALES_HOLD);
    }

    public function changeAction(): string
    {
        return 'order_reconciled';
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
