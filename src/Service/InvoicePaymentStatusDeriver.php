<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use App\Enum\InvoicePaymentStatus;

/**
 * Recomputes an invoice's payment status from its payments (#539 stage 4).
 *
 * The same shape as SalesOrderStatusDeriver, and for the same reason. A payment status somebody can
 * type in is a second answer to a question the payment rows already answer, and it disagrees with
 * them the first time a payment is recorded, corrected or removed by anything that forgot to update
 * it — which is how an order could sit at "Paid" with no payment against it, and how a part-payment
 * could leave an invoice reading "Not Paid" forever.
 *
 * So there is no setPaymentStatus(). This is the only thing that writes the column, through the
 * narrow Invoice::applyDerivedPaymentStatus(), and InvoicePaymentStatusSubscriber calls it on every
 * write to an invoice or to any of its payments.
 *
 * ## The rules
 *
 * | Status          | When                                                        |
 * |-----------------|-------------------------------------------------------------|
 * | Paid            | payments cover the total — including a total of zero        |
 * | Partially Paid  | something has been received, but not the whole total        |
 * | Not Paid        | nothing has been received                                   |
 *
 * ## Cancellation is deliberately not a case here
 *
 * A cancelled invoice keeps saying what actually happened to its money: cancelling one nobody ever
 * paid leaves it Not Paid, which is the truth and what an admin needs to see. That is separate from
 * Invoice::isFullyPaid(), which answers a different question — "is this invoice still owed?" — and
 * for which a cancelled invoice is settled, because it is owed nothing and never will be. Folding
 * the two together would either hold an order open forever on an invoice nobody is going to pay, or
 * claim money arrived that never did.
 *
 * ## How this composes with the order's status
 *
 * SalesOrderStatusDeriver decides Closed from "every counting invoice fully paid", and it asks
 * Invoice::isFullyPaid(), which sums the payment rows itself rather than reading the column this
 * writes. So the two derivations do not depend on which subscriber runs first: the order reaches the
 * same answer whether or not this one has written its column yet, and the column is a queryable
 * projection of the same sum rather than an input to it.
 */
final class InvoicePaymentStatusDeriver
{
    /**
     * Puts $invoice into the payment status its payments imply, and writes the timeline entry when
     * that is a real change.
     *
     * Returns true when the invoice was moved, so the caller knows whether a flush is owed.
     */
    public function recalculate(Invoice $invoice): bool
    {
        $previous = $invoice->getPaymentStatus();
        $target = $this->statusFor($invoice);

        if (!$invoice->applyDerivedPaymentStatus($target)) {
            return false;
        }

        // Written here rather than by whoever triggered the flush, for the reason the actions write
        // theirs: this is the only place that knows a transition happened. The actor is System
        // because nobody performed this — recording the payment is the act, and recordPayment()
        // already signed that entry; this is its consequence.
        $invoice->queueActivityLogEntry()
            ->setUserName(DocumentActor::system()->displayName)
            ->setComment(sprintf('Payment status changed from %s to %s.', $previous->value, $target->value))
            ->setType('System');

        return true;
    }

    /** The payment status $invoice's payments imply, ignoring what it currently says. */
    public function statusFor(Invoice $invoice): InvoicePaymentStatus
    {
        if ($invoice->paymentCoversTotal()) {
            return InvoicePaymentStatus::Paid;
        }

        return $invoice->hasReceivedMoney()
            ? InvoicePaymentStatus::PartiallyPaid
            : InvoicePaymentStatus::NotPaid;
    }
}
