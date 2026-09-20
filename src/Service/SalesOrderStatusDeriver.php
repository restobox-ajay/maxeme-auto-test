<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SalesOrder;

/**
 * Recomputes a sales order's status from its invoices (#539 stage 2).
 *
 * Approved and Void are the only statuses a human sets, by naming them at SalesOrder::setStatus() —
 * the one gate. Everything else follows from the invoice set, so it is computed here and
 * nowhere else — SalesOrderDerivedStatusSubscriber calls this on every write to an order or to any
 * of its invoices, which is what stops the two from disagreeing.
 *
 * ## The rules
 *
 * | Status              | When                                                                |
 * |---------------------|---------------------------------------------------------------------|
 * | Draft               | not approved (whatever its invoices say)                            |
 * | Approved            | approved, and nothing counts as invoiced                            |
 * | Partially Invoiced  | some but not all ordered quantity invoiced                          |
 * | Invoiced            | all quantity invoiced, one or more of those invoices not fully paid |
 * | Closed              | all quantity invoiced and every one of those invoices fully paid    |
 * | Void                | never derived — only a caller naming it at the gate writes it        |
 *
 * "Counts as invoiced" is Invoice::countsTowardInvoicedQuantity(): false for Draft and Cancelled,
 * true for the rest. So a draft invoice leaves the order exactly where it was, and cancelling every
 * invoice on an order returns it to Approved with its full quantity owed again — the goods are
 * still owed, which is precisely why cancelling an invoice does not void its order.
 *
 * ## Where "was it approved?" comes from
 *
 * From the status itself: everything from Approved onward counts as approved
 * (SalesOrderStatus::isApprovedOrLater()). A separate approved flag would be a second answer that
 * could disagree with the first, and there is no question this deriver asks that the status cannot
 * already answer.
 *
 * A status this enum does not know — the quote-era strings, the pre-#539 fulfilment cases on a
 * database that has not run the stage 2 migration — reads as not approved, i.e. Draft. That is the
 * same absorption getStatusEnum() already performs, and it is deliberately not an exception: a
 * legacy row must still load and still save.
 */
final class SalesOrderStatusDeriver
{
    /**
     * Puts $order into the status its invoices imply.
     *
     * Returns true when the order was moved, so the caller knows whether a flush is owed. A voided
     * order is never moved: Void is a judgement about the order that nothing about its invoices may
     * undo.
     *
     * This is now ORCHESTRATION only — deciding WHEN to run. The computation itself lives on
     * {@see SalesOrder::deriveStatus()}, and the timeline row on {@see SalesOrder::setStatus()}.
     */
    public function recalculate(SalesOrder $order): bool
    {
        // What the order should be is the ORDER's to say. `applyDerivedStatus()` takes no status
        // argument, so there is no longer any place for a caller to hand a document the wrong
        // answer — the same removal `setStatus()` made for vocabularies.
        //
        // The explicit Void check that stood here is gone because it moved INTO the document, where
        // it belongs: `SalesOrder::deriveStatus()` returns null for a void order, and a null derives
        // nothing. Void is a judgement about the order that nothing about its invoices may undo.
        //
        // The timeline entry is no longer written here either (queue item 64). `setStatus()` writes
        // it, which is what makes a status change that records nobody impossible to write — and
        // leaving this one in place as well would put TWO rows on every derived transition. The
        // actor is System because nobody performed this: it is a consequence of an invoice changing,
        // and the action that changed the invoice signed its own entry on that document.
        return $order->applyDerivedStatus(DocumentActor::system());
    }
}
