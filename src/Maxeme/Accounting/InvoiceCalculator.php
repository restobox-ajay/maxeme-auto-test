<?php

declare(strict_types=1);

namespace App\Maxeme\Accounting;

use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\InvoicePartLine;
use App\Maxeme\Entity\InvoiceServiceLine;

/**
 * The invoice arithmetic (legacy invoice_builder.js calculatePrice(), which the legacy server
 * trusted as posted): subtotal = Σ quantity × price of the services and the standalone parts,
 * plus the (negative) discount; GST and PST are that subtotal's percentages; total = subtotal + both.
 * public/assets/js/maxeme-invoice.js does the same live, for display only.
 */
final class InvoiceCalculator
{
    /** @param string $discount negative or zero */
    public function calculate(Invoice $invoice, string $discount, int $gstRate, int $pstRate): InvoiceTotals
    {
        $lines = array_sum(array_map(static fn (InvoiceServiceLine $line): int => $line->totalCents(), $invoice->getServiceLines()->toArray()))
            + array_sum(array_map(static fn (InvoicePartLine $line): int => $line->totalCents(), $invoice->getPartLines()->toArray()));

        $subtotal = $lines + Money::toCents($discount);
        $gst = Money::percentOf($subtotal, $gstRate);
        $pst = Money::percentOf($subtotal, $pstRate);

        return new InvoiceTotals(
            Money::fromCents($subtotal),
            Money::fromCents($gst),
            Money::fromCents($pst),
            Money::fromCents($gst + $pst),
            Money::fromCents($subtotal + $gst + $pst),
        );
    }
}
