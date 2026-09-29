<?php

declare(strict_types=1);

namespace App\Maxeme\Accounting;

/** An invoice's calculated amounts, as decimal strings (dollars). */
final class InvoiceTotals
{
    public function __construct(
        public readonly string $subtotal,
        public readonly string $gst,
        public readonly string $pst,
        public readonly string $salesTax,
        public readonly string $total,
    ) {
    }
}
