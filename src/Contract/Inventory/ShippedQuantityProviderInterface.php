<?php

declare(strict_types=1);

namespace App\Contract\Inventory;

use App\Entity\InvoiceLine;

/**
 * How much of an invoice line has actually left the building.
 *
 * Core bills goods and core records that an invoice reached Completed, but core has no shipment
 * table and no concept of a line going out in two trips: `InvoiceLine::$quantity` is what was
 * *billed*, and nothing in core says how much of it was *picked*. That is what makes the invoice
 * grid's Shipping Status column unanswerable from core facts beyond its two ends — a Completed
 * invoice shipped, anything else has not, and "Partially Shipped" is a word core has no evidence for.
 *
 * Core cannot ask InventoryDepthBundle directly — shipments are a bundle concern and the bundle has
 * a kill switch — so core asks whoever is listening. With no provider registered, or with every
 * provider's bundle switched Inactive, nothing has shipped as far as core can tell and
 * {@see \App\Service\InvoiceShippingStatusDeriver} falls back to the invoice's own status, which is
 * exactly what the column said before any of this existed.
 *
 * Tagged app.shipped_quantity, summed by InvoiceShippingStatusDeriver.
 */
interface ShippedQuantityProviderInterface
{
    /**
     * The quantity of $invoiceLine this provider knows has shipped, as a decimal string.
     *
     * A decimal string rather than an int, because a shipped quantity is `NUMERIC(14, 4)` on both
     * sides of the comparison the deriver makes: `invoice_line.quantity` and
     * `shipment_line.quantity` are both fractional columns, and a provider that rounded to whole
     * units would report 0.4 of a case as nothing shipped and 1.6 as fully shipped.
     *
     * Counts only what still stands — a withdrawn or voided shipment has, as far as the goods are
     * concerned, not happened, and counting it would leave an invoice reading Shipped with the
     * stock back on the shelf. Returns '0' for a line the provider knows nothing about, which is
     * also the right answer for a line that was never persisted.
     */
    public function shippedQuantityForInvoiceLine(InvoiceLine $invoiceLine): string;
}
