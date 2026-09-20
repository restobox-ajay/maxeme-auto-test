<?php

declare(strict_types=1);

namespace ProcurementBundle\Receiving;

use App\Service\QuantityScale;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\GoodsReceipt;
use ProcurementBundle\Status\PurchaseOrderStatusDeriver;

/**
 * Withdrawing a receipt that should never have been booked (#613).
 *
 * ## Why this exists and an adjustment does not do it
 *
 * A receipt booked against the wrong purchase order, or into the wrong warehouse, is the one
 * receiving mistake with permanent consequences. The stock half is fixable — count it, adjust it,
 * move it — but `purchase_order_line.quantity_received` stays credited to a line nothing arrived
 * against, the derived PO status stays wrong because it is computed from those figures, and the
 * three-way match compares a bill to a delivery that did not happen. No adjustment reaches any of
 * that: an adjustment is about stock, and this is about paperwork.
 *
 * ## A void is a second fact, never an unwrite
 *
 * The discipline is GoodsReceipt's own and it is not relaxed here:
 *
 *  - the receipt's lines are UNTOUCHED. It still says what was on the dock;
 *  - the original movement group is UNTOUCHED. It still says what reached stock;
 *  - the stock comes back out through a NEW MovementRequest, applied by the same
 *    StockMovementService every other stock change in this application goes through. This class
 *    writes no `inventory_detail` row and touches no `product_inventory` column, exactly as
 *    ReceivingService does not;
 *  - the receipt gains three stamps and keeps its number, the same bargain a cancelled purchase
 *    order and a voided vendor bill already strike.
 *
 * ## Every dimension comes off the receipt line, nothing is retyped
 *
 * The reversal's source key is built from the receipt line's own `location_id`, `lot_id`, `serial`
 * and the receipt's `warehouse_id` — the identical five fields ReceivingService handed to
 * `MovementRequest::receive()` on the way in. That is ReversalPlanner's rule applied to a document
 * rather than to a movement entry, and for its reason: an operator naming the destination by hand
 * can take the units out of the wrong bin, off the wrong batch, or from under a serial belonging to
 * a different physical unit.
 *
 * ## What it refuses, and what it deliberately does not
 *
 * **Refuses a second void.** GoodsReceipt::markVoided() throws on one, because putting the stock
 * back twice would take units off the shelf that this receipt never put there.
 *
 * **Refuses when the stock is no longer where the receipt put it.** Not by checking — by asking.
 * StockMovementService::assertSourcesCanCover() already answers "does that row still hold this
 * much", so a pallet that has been sold, transferred or written off since produces an
 * InsufficientStockException naming the row, and the whole void rolls back. That is the honest
 * outcome: the goods are gone, the receipt is the only record of them arriving, and unwinding it
 * would leave the ledger short with nothing saying why. Count the stock and adjust it instead.
 *
 * **Does NOT refuse on a matched or approved vendor bill.** A bill approved against a delivery that
 * did not happen is precisely the situation this exists to correct, and refusing would leave the
 * only wrong document as the only immovable one. The three-way match recomputes from
 * `quantity_received` and will report the bill as unmatched the moment this returns, which is the
 * report somebody needs to see.
 *
 * **Does NOT reopen a Closed or Cancelled purchase order.** PurchaseOrderStatusDeriver refuses to
 * move into or out of a decided state, and that refusal is left exactly where it is.
 */
final class ReceiptVoidService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockMovementService $movements,
        private readonly PurchaseOrderStatusDeriver $poStatus,
    ) {
    }

    /**
     * Withdraws $receipt: puts its stock back out, un-credits its PO lines, stamps the row.
     *
     * Whole or not at all — one transaction, and the movement is applied inside it, so a refusal
     * from the stock check leaves the receipt exactly as it was rather than half-voided.
     *
     * @throws ReceivingException                                              when the receipt cannot be withdrawn as described
     * @throws \InventoryDepthBundle\Movement\InsufficientStockException       when the units are no longer where the receipt put them
     */
    public function void(GoodsReceipt $receipt, string $reason, ?string $actor = null, ?string $operationId = null): GoodsReceipt
    {
        if ($receipt->isVoided()) {
            throw new ReceivingException(sprintf(
                '%s was already voided on %s. Voiding it again would take units off the shelf that this receipt never put there.',
                $receipt->getReceiptNumber(),
                $receipt->getVoidedAt()?->format('Y-m-d H:i') ?? '',
            ));
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new ReceivingException('Voiding a receipt needs a reason. It withdraws a document that moved stock, and a decision with nothing on it is unauditable a fortnight later.');
        }

        return $this->em->wrapInTransaction(function () use ($receipt, $reason, $actor, $operationId): GoodsReceipt {
            $request = MovementRequest::of(
                InventoryMovementGroup::TYPE_RECEIPT,
                $operationId === null ? null : $operationId . '-void',
                sprintf('Receipt %s voided: %s', $receipt->getReceiptNumber(), $reason),
                $actor,
                // The receipt number again, so #550's movement history shows the arrival and its
                // withdrawal under one reference without anybody needing this bundle to read it.
                $receipt->getReceiptNumber(),
            );

            foreach ($receipt->getLines() as $line) {
                $quantity = QuantityScale::canonical($line->getQuantity());

                // Un-credit the PO line whether or not the line ever reached stock. A `simple`
                // product's line moved no inventory and still credited quantity_received — that is
                // the paperwork half, and it is the half this exists for.
                $this->uncreditPurchaseOrderLine($line->getPurchaseOrderLine(), $quantity);

                $product = $line->getProduct();
                if ($product === null) {
                    continue;
                }

                if (QuantityScale::compare($quantity, 0) <= 0) {
                    continue;
                }

                // EVERY line is handed back to the movement, not just the ones that reached a
                // NAMED bin — the mirror of the gate ReceivingService applies on the way in. A
                // `simple` line credited `received` when it arrived, so it has to debit `received`
                // when the receipt is withdrawn; skipping it here is what left the credit on the row
                // forever, over-reporting availability by the whole delivery.
                //
                // Keyed on what the RECEIPT actually did, not on what the product's mode says
                // today. `movementApplied` is the stored record of whether this line named a
                // specific bin/lot/serial, so a product switched between modes — or a bundle
                // switched off — after the goods arrived still has exactly its own arrival reversed:
                //
                //  - applied: the same five identity fields ReceivingService used on the way in,
                //    read back off the line rather than retyped. `expectResolution` is not one of
                //    them — it is a flag on the row, not part of the row's identity — so this
                //    resolves to the row the receipt created or added to, never a second one
                //    beside it;
                //  - not applied: the bare warehouse key the receipt used, which carries no
                //    bin/lot/serial because none of that identity ever meant anything for the line
                //    — the same warehouse's unspecified row StockMovementService credited on the way
                //    in, so this always resolves to a real, existing row.
                $request->remove(
                    $product,
                    $line->isMovementApplied()
                        ? new DetailKey(
                            $receipt->getWarehouse(),
                            $line->getLocation(),
                            $line->getLot(),
                            $line->getSerial(),
                            InventoryDetail::STATUS_AVAILABLE,
                        )
                        : new DetailKey($receipt->getWarehouse()),
                    $quantity,
                );
            }

            // Applied inside the transaction, and its own stock check is the refusal: a row that no
            // longer holds what the receipt put there throws, and the whole void rolls back.
            //
            // Gated on the REQUEST having lines, for the same reason ReceivingService gates on
            // `!$movement->isEmpty()`: a receipt of nothing but `simple` products moves no detail
            // rows and still has buckets to put back.
            $group = !$request->isEmpty() ? $this->movements->apply($request) : null;

            $receipt->markVoided($actor, $reason, $group);

            $order = $receipt->getPurchaseOrder();
            if ($order instanceof PurchaseOrder) {
                $this->recordOnPurchaseOrder($order, $receipt, $reason, $actor);
                // After the un-crediting above, because the deriver reads the lines' own maintained
                // figures. Refuses to move a Draft, Closed or Cancelled order, which is left alone
                // deliberately — see the class docblock.
                $this->poStatus->recalculate($order);
            }

            $this->em->flush();

            return $receipt;
        });
    }

    /**
     * Takes what this receipt credited back off the PO line's running received total.
     *
     * Floored at zero rather than allowed to go negative. A negative `quantity_received` is not a
     * state any reader of this column is written to handle — `isFullyReceived()` and the outstanding
     * figure both assume it counts goods — and the only way to reach one is a figure that was
     * already wrong before this ran. Clamping states the truth available ("nothing of this line has
     * arrived") instead of propagating an impossible number into the derived status.
     */
    private function uncreditPurchaseOrderLine(?PurchaseOrderLine $line, string $quantity): void
    {
        if (!$line instanceof PurchaseOrderLine || QuantityScale::compare($quantity, 0) <= 0) {
            return;
        }

        $remaining = QuantityScale::sub($line->getQuantityReceived(), $quantity);
        $line->setQuantityReceived(QuantityScale::compare($remaining, 0) > 0 ? $remaining : QuantityScale::canonical(0));
    }

    private function recordOnPurchaseOrder(PurchaseOrder $order, GoodsReceipt $receipt, string $reason, ?string $actor): void
    {
        $order->queueActivityLogEntry()
            ->setUserName($actor ?? 'System')
            ->setComment(sprintf(
                'Receipt %s voided: %s. %s unit(s) came back off this order\'s received totals.',
                $receipt->getReceiptNumber(),
                $reason,
                $receipt->getTotalQuantity(),
            ))
            ->setType('System');
    }
}
