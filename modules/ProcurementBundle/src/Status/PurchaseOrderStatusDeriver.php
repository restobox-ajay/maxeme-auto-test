<?php

declare(strict_types=1);

namespace ProcurementBundle\Status;

use App\Service\DocumentActor;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Enum\PurchaseOrderStatus;

/**
 * Recomputes a purchase order's status from what has actually arrived (#555).
 *
 * The buy-side counterpart of `App\Service\SalesOrderStatusDeriver`, and for the same reason. A
 * received-state somebody can type in is a second answer to a question the receipt lines already
 * answer, and it disagrees with them the first time goods are booked in by anything that forgot to
 * update it — which is how an order sits at "Received" with half of it still on a truck.
 *
 * This is now ORCHESTRATION only — deciding WHEN to run. The computation itself lives on
 * {@see PurchaseOrder::deriveStatus()}, and the write and the timeline row on
 * {@see PurchaseOrder::setStatus()} (`HasStatus::applyDerivedStatus()`, inherited from
 * `AbstractPurchaseDocument`), now that this document is on the status seam. ReceivingService calls
 * `recalculate()` in the same transaction as every receipt.
 *
 * ## The rules
 *
 * | Status              | When                                                        |
 * |---------------------|-------------------------------------------------------------|
 * | Received            | every line has had its full ordered quantity, or more        |
 * | Partially Received  | something has arrived, but not all of it                      |
 * | Issued              | nothing has arrived yet                                       |
 *
 * ## What it deliberately will not do
 *
 * **It never closes a short shipment.** 210 of 240 arrived and the rest never will is a *decision* —
 * chase it, cancel the remainder, write it off — and a system that closed the PO on its own would
 * be hiding money. That is `PurchaseOrder::closeShort()`, which takes an actor and demands a reason.
 *
 * **It never touches Draft, Closed or Cancelled.** Those are decided states, not projections;
 * `PurchaseOrder::deriveStatus()` returns null for all three, so a PO closed short does not spring
 * back to Partially Received the next time somebody looks at it, and a late delivery against a
 * closed order is recorded without silently reopening it.
 *
 * **It reads the lines' own maintained figures**, not a live sum over receipt rows, so the answer
 * does not depend on which side of a flush it is asked on. `quantityReceived` is written by
 * ReceivingService in the same transaction as the receipt that changed it, which is the same
 * bargain `product_inventory.quantity` strikes with `inventory_detail` one layer down.
 */
final class PurchaseOrderStatusDeriver
{
    /**
     * Puts $order into the status its lines imply. Returns true when the order was moved, so the
     * caller knows whether a flush is owed.
     */
    public function recalculate(PurchaseOrder $order): bool
    {
        return $order->applyDerivedStatus(DocumentActor::system());
    }
}
