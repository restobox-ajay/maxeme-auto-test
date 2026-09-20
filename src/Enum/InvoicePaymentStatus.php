<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Whether an invoice has been settled (#539 stage 4).
 *
 * Beside InvoiceStatus, never inside it: that one answers "have the goods gone", this one answers
 * "has the money arrived". An invoice can be Completed and Not Paid, or Pending and Paid up front,
 * and neither is a contradiction.
 *
 * The three values are the ones the app already used on SalesOrder before payment moved here, so
 * nothing an admin reads changes wording. What changes is that they are no longer typed in: this is
 * DERIVED from the invoice's payments against its total by InvoicePaymentStatusDeriver, and there is
 * no setPaymentStatus() to write it by hand — see Invoice::applyDerivedPaymentStatus().
 *
 * Enum-typed at the column, like InvoiceStatus and unlike SalesOrder::$status: stage 4's migration
 * normalises every existing row, so there are no legacy strings left for the database to have to
 * hydrate.
 */
enum InvoicePaymentStatus: string
{
    /** Nothing has been received against this invoice. */
    case NotPaid = 'Not Paid';

    /** Something has been received, but not the whole total. */
    case PartiallyPaid = 'Partially Paid';

    /** Payments cover the total. An invoice owing nothing at all (a zero total) is born here. */
    case Paid = 'Paid';

    /**
     * How settled this is, on a 0..1 scale, for rolling several invoices up into one answer.
     *
     * The two ends are the only values that decide a label — an order is Paid when every one of its
     * invoices scores 1 and Not Paid when every one scores 0 — so the 0.5 in the middle exists only
     * to order a partially-paid invoice between the two when a grid sorts on the rollup. The same
     * three numbers appear in OrderPaymentRollup's SQL, which is where the rollup is actually
     * computed; this is the PHP-side statement of the same scale.
     */
    public function settledScore(): float
    {
        return match ($this) {
            self::Paid => 1.0,
            self::PartiallyPaid => 0.5,
            self::NotPaid => 0.0,
        };
    }
}
