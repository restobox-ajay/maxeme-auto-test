<?php

declare(strict_types=1);

namespace App\Contract\Payment;

use App\Entity\AdminUser;

/**
 * One slice of a payment landing on one document (#708, the multi-invoice/multi-bill follow-up to
 * queue item 34 — replaces `DocumentPayment`, which assumed a payment could only ever be against
 * one document).
 *
 * ## Why this replaces `DocumentPayment` rather than extending it
 *
 * `DocumentPayment` was "the money": one row, one document, `getDocument()` naming it. That shape
 * broke the moment a single cheque had to pay two invoices — the row genuinely IS against more than
 * one document, in different amounts, on potentially different dates. So the row that used to BE the
 * payment splits into two: `InvoicePayment`/`VendorBillPayment` (the POOL — what arrived, when, by
 * what method, from whom) and `InvoicePaymentApplication`/`VendorBillPaymentApplication` (a CLAIM
 * against one document, for part of that pool). This interface is the claim's contract, mirroring
 * {@see \App\Entity\CreditMemoApplication} — the pattern this feature was told to copy from the
 * start — rather than the old one-payment-one-document shape.
 *
 * ## Why it still carries method/reference/recordedBy, which are genuinely the pool's
 *
 * A payments SCREEN reads one thing per row — date, method, amount, reference, who recorded it —
 * whichever side of the business it is on, and that read pattern does not change just because the
 * amount and the date are now per-application rather than per-payment. Rather than make every
 * template reach through `application.getPayment()` for three of six fields, the four that never
 * vary per application (method, reference, currency, recordedBy — a cheque was written once,
 * however many invoices it settles) are exposed here too, DELEGATED to the pool by each
 * implementation in one line. `getAmount()` and `getAppliedAt()` are the two that genuinely belong
 * to the application and are stored on it.
 *
 * {@see PayableDocument} is the other half: this is the money, that is the thing it is against.
 */
interface PaymentApplication
{
    /**
     * The row's own identity, or null before it is persisted.
     *
     * This is the APPLICATION's id, not the underlying payment's — withdrawing and re-applying (the
     * "move" a document's payments screen offers) is a new application row on the same payment, and
     * the payment's own id is what actually needs to survive a correction; see
     * `InvoicePayment`/`VendorBillPayment`'s own docblocks for why that is still true here.
     */
    public function getId(): ?int;

    /** The document this slice is claimed against. */
    public function getDocument(): PayableDocument;

    /** Decimal string, two places. Always positive — see the entity that creates these. */
    public function getAmount(): string;

    /**
     * The day this slice was applied — which is not necessarily the day the money arrived. A cheque
     * banked in March and split across invoices in March and May has one `receivedAt`/`paidAt` on
     * the pool and two different `appliedAt` dates here, exactly as
     * {@see \App\Entity\CreditMemoApplication::getAppliedAt()} argues for a credit note.
     */
    public function getAppliedAt(): \DateTimeImmutable;

    /**
     * ISO 4217, three letters — or null, exactly as `CommercialDocument::getCurrency()` is nullable
     * and for the same reason. Delegated to the pool, which delegates to the document, which is
     * always the correct currency: a payment cannot be in a currency its document is not in.
     */
    public function getCurrency(): ?string;

    /** Cheque, EFT, card, cash — free text, 64 characters, on both sides. From the pool. */
    public function getMethod(): string;

    /** The cheque number, the EFT reference, the note. From the pool. */
    public function getReference(): ?string;

    /**
     * The admin who recorded the payment, or null.
     *
     * Null is a real answer on both sides and means something different on each: a Stripe checkout
     * the customer completed (sell), a payment run or an import (buy). From the pool.
     */
    public function getRecordedBy(): ?AdminUser;
}
