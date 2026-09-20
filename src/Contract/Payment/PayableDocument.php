<?php

declare(strict_types=1);

namespace App\Contract\Payment;

use App\Contract\Document\CommercialDocument;
use Doctrine\Common\Collections\Collection;

/**
 * A commercial document that money can be recorded against (queue item 34, extended for
 * multi-invoice/multi-bill splitting at #708) — the other half of {@see PaymentApplication}.
 *
 * ## Why this is not just `CommercialDocument`
 *
 * Not every commercial document takes money. An `Estimate` is priced and never paid; a
 * `SalesReturn` and an `RfqVendorReply` state no payable balance at all. `CommercialDocument`
 * answers "what is this document", this answers "may money sit on it, and how much already does".
 * Extending rather than duplicating, because everything a refusal has to say about a document — its
 * number, its currency, its total — is already promised there.
 *
 * ## The one question this interface exists for
 *
 * {@see self::paymentRefusal()}. It returns a SENTENCE and not a boolean, which is the standing
 * "if there's an issue, be clear and be loud" rule expressed in a type: a boolean forces every
 * caller to reinvent the wording, and the wording is per-side — a void bill and a cancelled invoice
 * are refused for reasons that read differently to the person holding the cheque.
 */
interface PayableDocument extends CommercialDocument
{
    /**
     * Null when this document may hold a payment; otherwise the reason it may not, in the user's
     * language, ready to be shown where they are looking.
     *
     * ## Not a boolean, deliberately
     *
     * A `canTakePayment(): bool` would push the sentence back out to the three or four call sites
     * that need it — the record path, the move path's source and target, and the templates that
     * decide whether to offer a screen — and a rule repeated is a rule that drifts. That is the same
     * argument `Invoice::countsTowardInvoicedQuantity()` and `VendorBillStatus::claimsOrderedQuantity()`
     * both make for being stated once.
     *
     * ## The two sides disagree about the DRAFT, and the disagreement stays
     *
     * This is the payments equivalent of `VendorBillStatus::counts()` versus
     * `::claimsOrderedQuantity()`: an asymmetry that is declared rather than flattened, because
     * flattening it would carry one side's reasoning onto a side where it is false.
     *
     *  - **A draft INVOICE may not take a payment.** An invoice's payment status is a separate
     *    column derived from the payment rows at flush, so a draft holding payments displays itself
     *    as **Paid** while being a working document no customer has ever seen — two statuses on one
     *    row contradicting each other. Guarded by `InvoiceStatus::acceptsPayment()`.
     *  - **A draft BILL may.** A bill folds fulfilment and money into ONE status column, and
     *    `VendorBillStatus::isDerivable()` refuses to derive a Draft at all, so a draft bill holding
     *    payments still reads Draft — it cannot contradict itself the way an invoice can. And the
     *    buy side has never treated a draft as inert: a draft bill already claims quantity on its
     *    purchase order lines and already appears on the match and exception screens.
     *
     * So the same word, "draft", is a different object on the two sides, and one predicate that
     * answered both would have to pick one side's meaning and be wrong on the other.
     */
    public function paymentRefusal(): ?string;

    /**
     * How to name this document in a refusal — its number, or `#id` while it has none.
     *
     * Here rather than left to `getDocumentNumber()` because a document can hold payments before a
     * number is allocated, and a message reading "Bill  cannot take this payment" is not a message.
     * Both sides already had this as a private helper; this is the one place it is promised.
     */
    public function getDocumentLabel(): string;

    /**
     * Who the money is between, as an opaque key — `vendor:12`, `company:7`.
     *
     * A key and not a name, because the rule it backs is an identity test and names collide: two
     * vendors called "Acme Supply" are two vendors, and a payment applied to the wrong one is a
     * payment fabricated to somebody who was never paid. Opaque because the two sides' counterparties
     * are different entities that share no ancestor — `Vendor` and `Company` — and
     * {@see \App\Payment\PaymentApplicationGuard} only ever compares two of these for equality. It
     * never parses one.
     */
    public function getPaymentCounterpartyKey(): string;

    /**
     * @return Collection<int, PaymentApplication> the slices of one or more payments claimed against
     *                                              this document — not the payments themselves, which
     *                                              may also be claimed elsewhere
     */
    public function getApplications(): Collection;

    /** Everything applied against it, summed in whole cents and formatted back. Derived, never stored. */
    public function getAmountPaid(): string;

    /** What is still owed. Negative when the document has been overpaid, which is information. */
    public function getBalance(): string;
}
