<?php

declare(strict_types=1);

namespace App\Maxeme\Accounting;

use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\InvoiceCharge;
use App\Maxeme\Entity\InvoicePartLine;
use App\Maxeme\Entity\InvoiceServiceLine;

/**
 * The invoice arithmetic (legacy invoice_builder.js calculatePrice(), which the legacy server
 * trusted as posted): subtotal = Σ quantity × price of the services (and their charge-through
 * lines) and the standalone parts, plus the (negative) discount and the custom fees and discounts
 * of an invoice issued from a repair order. GST and PST are their percentages of what carries each:
 * every service and charge-through line by the taxes frozen on it (from its Tax Class, LineTax),
 * the parts, the discount and the custom fees and discounts both. total = subtotal + both.
 * public/assets/js/maxeme-invoice.js does the same live, for display only.
 */
final class InvoiceCalculator
{
    /** @param string $discount negative or zero */
    public function calculate(Invoice $invoice, string $discount, int $gstRate, int $pstRate): InvoiceTotals
    {
        // Taxed both: the parts, the discount, the custom fees and discounts.
        $both = array_sum(array_map(static fn (InvoicePartLine $line): int => $line->totalCents(), $invoice->getPartLines()->toArray()))
            + Money::toCents($discount)
            + array_sum(array_map(static fn (InvoiceCharge $charge): int => $charge->getSignedCents(), $invoice->getCharges()));
        $subtotal = $both;
        $gstBase = $both;
        $pstBase = $both;
        foreach ($invoice->getServiceLines() as $line) {
            $cents = $line->totalCents();
            $subtotal += $cents;
            $gstBase += $line->chargesGst() ? $cents : 0;
            $pstBase += $line->chargesPst() ? $cents : 0;
        }
        $gst = Money::percentOf($gstBase, $gstRate);
        $pst = Money::percentOf($pstBase, $pstRate);

        return new InvoiceTotals(
            Money::fromCents($subtotal),
            Money::fromCents($gst),
            Money::fromCents($pst),
            Money::fromCents($gst + $pst),
            Money::fromCents($subtotal + $gst + $pst),
        );
    }
}
