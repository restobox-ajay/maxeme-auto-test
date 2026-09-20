<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Shipment;

use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Service\Inventory\OrderInventoryBucketResolver;
use App\Service\QuantityScale;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\Shipment;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use InventoryDepthBundle\Repository\InventoryLotRepository;

/**
 * Withdraws a shipment that should never have gone out
 * (`docs/plans/2026-09-14-shipment-dispatch.md`, "Void").
 *
 * Same discipline as `ProcurementBundle\Receiving\ReceiptVoidService` on the way in, mirrored for
 * the way out:
 *
 *  - the shipment and its `ShipmentLine` rows are UNTOUCHED — they still say what left, and when.
 *    `ShipmentService::shippedSoFar()` (the oversell guard) excludes a voided shipment's lines by
 *    reading `Shipment::$voidedAt`, not by deleting or flagging the lines themselves;
 *  - the stock comes back through a NEW `MovementRequest`, applied by the same
 *    `StockMovementService` every other stock change in this application goes through — this class
 *    writes no `inventory_detail` row and touches no `product_inventory` column directly, exactly
 *    as `ShipmentService` does not;
 *  - the shipment gains three stamps and keeps its number.
 *
 * Only a question-2 line (`movementApplied = true`) has any stock to put back — a question-1-only
 * line never reached `StockMovementService` when it shipped, so there is nothing here for it either.
 *
 * ## Where the reversal lands
 *
 * `ship()` may have drawn a lot's stock from more than one bin
 * (`InventoryDetailRepository::availableRowsForLot()`), and `ShipmentLine` never recorded which
 * ones. The reversal therefore returns to an unbinned `available` row at the same
 * warehouse/lot/serial — on the shelf, findable, and exactly as re-binnable by a normal put-away as
 * any other unbinned arrival — rather than guessing at a bin the shipment line never named.
 *
 * ## What it refuses, and what it deliberately does not
 *
 * **Refuses a second void**, for the same reason `GoodsReceipt::markVoided()` does: putting stock
 * back twice would put units on the shelf this shipment never took off it.
 *
 * **Refuses when the stock is no longer where the shipment left it** — not by checking, by asking.
 * `StockMovementService::assertSourcesCanCover()` already answers "does the sold row still hold
 * this much", so a lot that has moved again since (a later shipment, a write-off) produces an
 * `InsufficientStockException` and the whole void rolls back.
 *
 * ## Never touches a reservation or a bucket column
 *
 * Same reasoning as `ShipmentService::ship()`: `approved`/`shipped` are not this class's concern in
 * either direction — this app has no mechanism that hands an invoice's hold back on a void any more
 * than it does on a ship.
 *
 * ## What goes back is exactly what came out, and nothing is rounded
 *
 * The quantity is read through `App\Service\QuantityScale`, the same exact-integer arithmetic
 * `ShipmentService` and core's `InvoiceShippingStatusDeriver` use, instead of the
 * `(int) round((float) …)` that used to sit here. On this side rounding was never merely imprecise:
 * it decided how many PHYSICAL units went back on the shelf, so a `1.6` line put back 2 — one more
 * than could have left under a rule that refuses to oversell a 1.6 invoice line.
 *
 * For a while this then REFUSED a fractional line outright, because the movement layer took an
 * `int` and there was no way to put 0.6 back. That refusal was the worst place in the stack for one
 * to sit: a line that had shipped could not be reversed. The movement layer is decimal now, so a
 * void simply returns what the line took, whatever its shape, and the only whole-number rule left
 * anywhere is the serial one — which `ShipmentService` enforces on the way out, so a serial line
 * reaching here is already one unit.
 */
final class ShipmentVoidService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockMovementService $movements,
        private readonly InventoryLotRepository $lots,
        private readonly WarehouseFulfillmentRegionService $regions,
    ) {
    }

    /**
     * @throws ShipmentException                                          when the shipment cannot be withdrawn as described
     * @throws \InventoryDepthBundle\Movement\InsufficientStockException  when the units are no longer where the shipment put them
     */
    public function void(Shipment $shipment, string $reason, ?string $actor = null, ?string $operationId = null): Shipment
    {
        if ($shipment->isVoided()) {
            throw new ShipmentException(sprintf(
                '%s was already voided on %s. Voiding it again would put units back on the shelf that this shipment never took off it.',
                $shipment->getShipmentNumber(),
                $shipment->getVoidedAt()?->format('Y-m-d H:i') ?? '',
            ));
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new ShipmentException('Voiding a shipment needs a reason. It withdraws a document that moved stock, and a decision with nothing on it is unauditable a fortnight later.');
        }

        return $this->em->wrapInTransaction(function () use ($shipment, $reason, $actor, $operationId): Shipment {
            $movement = MovementRequest::of(
                InventoryMovementGroup::TYPE_SHIP,
                $operationId === null ? null : $operationId . '-void',
                sprintf('Shipment %s voided: %s', $shipment->getShipmentNumber(), $reason),
                $actor,
                // The shipment number again, so #550's movement history shows the departure and its
                // reversal under one reference without anybody needing this bundle to read it.
                $shipment->getShipmentNumber(),
            );

            /** @var array<string, Warehouse>|null $warehousesByRegion built lazily, only if a question-2 line needs it */
            $warehousesByRegion = null;

            foreach ($shipment->getLines() as $line) {
                if (!$line->isMovementApplied()) {
                    continue;
                }

                $product = $line->getProduct();
                $invoiceLine = $line->getInvoiceLine();
                if (!$product instanceof ProductCore || $invoiceLine === null) {
                    throw new ShipmentException(sprintf(
                        '%s: cannot determine which warehouse to return this line\'s stock to — the invoice line it shipped against is gone.',
                        $line->getName(),
                    ));
                }

                $quantity = QuantityScale::canonical($line->getQuantity());
                if (QuantityScale::compare($quantity, 0) <= 0) {
                    continue;
                }

                // The whole-number refusal that used to stand here has gone with the one it
                // mirrored in `ShipmentService`. It existed because the movement layer took an
                // `int`, so a fractional shipment line could not be reversed at all — which meant a
                // line that somehow shipped 0.6 could never be voided, the worst possible place for
                // a limitation like that to sit. The movement layer is decimal now: a void puts
                // back exactly what the line took out, whatever its shape.

                $warehousesByRegion ??= $this->regions->warehousesByLowerRegionName();
                $warehouse = OrderInventoryBucketResolver::resolveLineWarehouse(
                    $invoiceLine->getLocation(),
                    $invoiceLine->getInvoice()->getFulfillmentRegion(),
                    $warehousesByRegion,
                );
                if (!$warehouse instanceof Warehouse) {
                    throw new ShipmentException(sprintf(
                        '%s: no warehouse serves the fulfillment region named on this invoice line.',
                        $line->getName(),
                    ));
                }

                $lotId = $line->getLotId();
                if ($lotId !== null) {
                    $lot = $this->lots->find($lotId);
                    if (!$lot instanceof InventoryLot) {
                        throw new ShipmentException(sprintf('%s: lot #%d no longer exists.', $line->getName(), $lotId));
                    }

                    $from = new DetailKey($warehouse, null, $lot, null, InventoryDetail::STATUS_SOLD);
                    $movement->move($product, $from, $from->forStatus(InventoryDetail::STATUS_AVAILABLE), $quantity);

                    continue;
                }

                $serial = $line->getSerial();
                if ($serial !== null && $serial !== '') {
                    $from = new DetailKey($warehouse, null, null, $serial, InventoryDetail::STATUS_SOLD);
                    $movement->move($product, $from, $from->forStatus(InventoryDetail::STATUS_AVAILABLE), $quantity);
                }
            }

            if (!$movement->isEmpty()) {
                $this->movements->apply($movement);
            }

            $shipment->markVoided($actor, $reason);
            $this->em->flush();

            return $shipment;
        });
    }
}
