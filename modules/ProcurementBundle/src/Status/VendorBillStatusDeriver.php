<?php

declare(strict_types=1);

namespace ProcurementBundle\Status;

use App\Service\DocumentActor;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Enum\VendorBillStatus;

/**
 * Recomputes a vendor bill's status from the money against it (#555).
 *
 * The mirror of `App\Service\InvoicePaymentStatusDeriver`, and no longer only in shape: both now
 * derive from summed payment rows — `vendor_bill_payment`, since queue item 34 (#708) landed AP
 * payments, which this docblock used to say were out of scope for #555. `getAmountPaid()` sums them
 * the same way `Invoice::getAmountPaid()` does; the derivation below was already written against
 * that figure and needed no change when the input stopped being hand-recorded.
 *
 * This is now ORCHESTRATION only — deciding WHEN to run. The computation itself lives on
 * {@see VendorBill::deriveStatus()}, and the write and the timeline row on
 * {@see VendorBill::setStatus()} (`HasStatus::applyDerivedStatus()`, inherited from
 * `AbstractPurchaseDocument`), now that this document is on the status seam.
 *
 * ## The rules
 *
 * | Status          | When                                                        |
 * |-----------------|-------------------------------------------------------------|
 * | Paid            | the amount paid covers the total — including a total of zero |
 * | Partially Paid  | something has been paid, but not the whole total            |
 * | Open            | nothing has been paid                                       |
 *
 * ## Three states it will not touch
 *
 * Draft, Void and Disputed are decisions rather than projections, and `VendorBill::deriveStatus()`
 * returns null for all three. Two of those refusals are load-bearing:
 *
 *  - a **draft** bill is inert. Deriving it to Open would approve it for payment because somebody
 *    typed a total, which is exactly the authorisation `approve()` exists to require;
 *  - a **disputed** bill that gets a part payment is still disputed. Deriving it back to Partially
 *    Paid would quietly drop the dispute, which is the one thing standing between a query and a
 *    payment.
 */
final class VendorBillStatusDeriver
{
    /**
     * Puts $bill into the status its money implies. Returns true when the bill was moved, so the
     * caller knows whether a flush is owed.
     */
    public function recalculate(VendorBill $bill): bool
    {
        return $bill->applyDerivedStatus(DocumentActor::system());
    }

    /** The status $bill's money implies, ignoring what it currently says. */
    public function statusFor(VendorBill $bill): VendorBillStatus
    {
        if ($bill->isSettled()) {
            return VendorBillStatus::Paid;
        }

        return $bill->hasPaidAnything()
            ? VendorBillStatus::PartiallyPaid
            : VendorBillStatus::Open;
    }
}
