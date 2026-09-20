<?php

declare(strict_types=1);

namespace App\Service\Document;

/**
 * What {@see SellSideLineReconciler::resolvePrice()} decides for one row — its own return shape
 * because "what does a blank price mean" is exactly the seam Estimate overrides (a quote's "TBD" is
 * a real state Order and Invoice never reach; see {@see EstimateLineReconciler}).
 */
final class ResolvedLinePrice
{
    public function __construct(
        /** Full precision, or null for "no price resolved" (TBD) — never round this for storage. */
        public readonly ?string $price,
        /** The same rate at the column's scale, or null alongside a null $price. */
        public readonly ?string $priceForColumn,
        /** Null alongside a null $price: an unpriced line contributes nothing to the subtotal. */
        public readonly ?string $lineSubtotal,
        /** Whether this row counts as priced — Order/Invoice always true; Estimate's TBD is false. */
        public readonly bool $priced,
    ) {
    }
}
