<?php

declare(strict_types=1);

namespace ProcurementBundle\Purchase;

/**
 * What {@see PurchaseSideLineReconciler::resolveUnitCost()} resolves a row's money to — two fields
 * rather than one, because the vendor rate card {@see PurchaseSideLineReconciler} falls back to on a
 * blank cost can ALSO name the vendor's own SKU for that product; `$vendorSkuFromRateCard` carries
 * that second fact out of the same lookup rather than repeating it (`PurchaseOrderController`'s own
 * original code does this with `??=` in the same breath it reads the rate card's cost — this DTO is
 * that side effect made explicit).
 */
final class ResolvedPurchaseCost
{
    public function __construct(
        public readonly string $unitCost,
        public readonly ?string $vendorSkuFromRateCard = null,
    ) {
    }
}
