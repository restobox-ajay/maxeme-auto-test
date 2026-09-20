<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * An invoice's fulfilment state. These are the cases SalesOrder carried before #539 — the
 * payment-driven side of an order was always really the invoice's, and this is where it moves.
 *
 * Payment lives beside this, not in it: Invoice::$paymentStatus answers "has the money arrived",
 * this answers "have the goods gone". An invoice can be Completed and unpaid, or Pending and
 * paid up front, and neither is a contradiction.
 *
 * Enum-typed at the column, unlike SalesOrder::$status, because nothing predates this enum —
 * there are no legacy strings to hydrate, so the database can enforce the set.
 *
 * There is no Void: an invoice is Cancelled instead (issue #539), and a cancelled invoice keeps
 * its number forever. Nothing may delete an invoice row.
 */
enum InvoiceStatus: string
{
    /** Written but not issued. Holds no stock and does not draw down its order's sales hold. */
    case Draft = 'Draft';

    /** Issued, awaiting fulfilment. Holds the pending inventory bucket. */
    case Pending = 'Pending';

    /**
     * Issued, but waiting on an up-front payment that has not arrived — a card checkout the
     * customer never completed.
     *
     * Carried over from SalesOrderStatus rather than dropped, because ecom depends on it and no
     * other case means the same thing. It holds NO inventory (deliberately distinct from Pending)
     * and it is the only status CancelStaleUnpaidOrdersCommand sweeps. Mapping these to Pending
     * would start reserving stock for abandoned checkouts; mapping them to Draft would say the
     * invoice was never issued, which is false — the customer placed the order.
     *
     * A customer on credit terms is legitimately unpaid for the length of their term, so their
     * invoice goes straight to Pending and gets fulfilled. "Unpaid" is not a fulfilment state.
     */
    case OnHold = 'On Hold';

    /** Being fulfilled. Holds the approved bucket. */
    case Processing = 'Processing';

    /**
     * Fulfilled. Keeps holding the approved bucket indefinitely — this app has no mechanism that
     * decrements Starting Inventory on shipment, so an import/recount is the only release valve.
     */
    case Completed = 'Completed';

    /** Withdrawn. Releases its buckets, and its quantity returns to the order's sales hold. */
    case Cancelled = 'Cancelled';

    /**
     * May an invoice in this state have money recorded against it (#31)?
     *
     * Two cases are out, and they are the same rule seen from both ends rather than a list:
     *
     *  - **Draft.** A draft has not been sent to anybody, so nobody could have paid it. Left open it
     *    is worse than merely odd: the payment status is DERIVED from the payment rows at flush by
     *    InvoicePaymentStatusSubscriber, so a draft could display itself as **Paid** while still
     *    being a working document no customer has ever seen — two statuses on one row contradicting
     *    each other.
     *  - **Cancelled.** Out by construction as much as by decision: {@see \App\Entity\Invoice::cancel()}
     *    refuses an invoice that holds payments, so a cancelled invoice holds none and there is
     *    nothing here for a further payment to join. Payments and cancellation are mutually
     *    exclusive, and the guard exists on BOTH sides so neither can be reached around the other —
     *    pay it and you cannot cancel it, cancel it and you cannot pay it.
     *
     * Everything else may: Pending, On Hold and Processing are invoices awaiting money by
     * definition, and Completed takes one too — goods shipped on credit terms are paid after
     * fulfilment, which is the ordinary case rather than an exception.
     *
     * Stated here rather than at the three call sites — the entity's recordPayment(), the payments
     * controller and the templates that offer the screen — for the reason
     * {@see \App\Entity\Invoice::countsTowardInvoicedQuantity()} is stated once: a rule repeated is
     * a rule that drifts, and the screen being hidden is not the guard.
     */
    public function acceptsPayment(): bool
    {
        return $this !== self::Draft && $this !== self::Cancelled;
    }

    /**
     * May an invoice in this state still be edited on the standalone edit screen
     * (`HasStatus::canEditOnStatus()`; #full-parity, 2026-09-12 — widened from Draft-only, per the
     * owner: "well add it cuz zoho allows us to edit it")?
     *
     * Completed and Cancelled are the two genuine exceptions, not Pending/On Hold/Processing:
     * Completed means the goods already shipped, so the document is a record of something that
     * already happened rather than something still being decided; Cancelled is permanent by
     * construction — an invoice keeps its number forever and is never reopened. Every other status
     * is still a document in flight.
     */
    public function allowsEditing(): bool
    {
        return $this !== self::Completed && $this !== self::Cancelled;
    }
}
