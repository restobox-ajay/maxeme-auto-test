<?php

declare(strict_types=1);

namespace App\Model;

/**
 * One reason an invoice does or does not sit cleanly against a sales order (#539 stage 5).
 *
 * The issue's words are the requirement: "the condition is that SKU/product lines must match so it
 * can deduct properly. If not match, show clearly what is not matching so user can correct." So a
 * mismatch is never the string "lines do not match" — it names the SKU, which side of the pairing
 * it is on, and the quantities involved, because those three are what the person correcting it has
 * to know.
 *
 * $blocking separates "this cannot be linked" from "this is worth knowing before you link it". Both
 * are shown; only the first refuses. An order line the invoice does not bill is the case that makes
 * the distinction necessary: it is exactly what a part-invoice looks like, so blocking on it would
 * forbid linking any invoice that bills less than the whole order, which is the case #539 exists
 * for. It is still reported, so nobody links a half invoice believing it covered everything.
 */
final class InvoiceOrderMismatch
{
    /** The invoice is already attached to an order; unlink it before attaching it to another. */
    public const KIND_ALREADY_LINKED = 'already-linked';

    /** The two documents bill different customers. */
    public const KIND_DIFFERENT_COMPANY = 'different-company';

    /** An invoice line with no SKU has nothing to match on. */
    public const KIND_LINE_HAS_NO_SKU = 'line-has-no-sku';

    /** The invoice bills a SKU the order does not have. */
    public const KIND_SKU_NOT_ON_ORDER = 'sku-not-on-order';

    /** The invoice bills more of a SKU than the order has left to invoice. */
    public const KIND_QUANTITY_EXCEEDS_REMAINING = 'quantity-exceeds-remaining';

    /** The order has a SKU this invoice does not bill. Reported, never refused. */
    public const KIND_ORDER_SKU_NOT_BILLED = 'order-sku-not-billed';

    public function __construct(
        public readonly string $kind,
        /** The SKU this is about, or the line's name when the trouble is that it has no SKU. */
        public readonly string $subject,
        public readonly string $message,
        public readonly bool $blocking,
    ) {
    }

    public static function blocking(string $kind, string $subject, string $message): self
    {
        return new self($kind, $subject, $message, true);
    }

    public static function notice(string $kind, string $subject, string $message): self
    {
        return new self($kind, $subject, $message, false);
    }
}
