<?php

declare(strict_types=1);

namespace ProcurementBundle\Import;

use App\Entity\ProductCore;
use ProcurementBundle\Entity\VendorPrice;

/**
 * What VendorSkuResolver::resolve() found — exactly one of three shapes, so a caller cannot read
 * $product on a row that failed to resolve one.
 */
final class VendorSkuResolution
{
    private function __construct(
        public readonly bool $isSuccess,
        public readonly bool $isUnmatched,
        public readonly ?ProductCore $product,
        public readonly ?VendorPrice $existingPrice,
        public readonly ?string $unmatchedVendorSku,
        public readonly ?string $failureReason,
    ) {
    }

    /** our_sku resolved (or vendor_sku matched an existing VendorPrice) to a real product. */
    public static function success(ProductCore $product, ?VendorPrice $existingPrice): self
    {
        return new self(true, false, $product, $existingPrice, null, null);
    }

    /** vendor_sku given, no our_sku, and no existing VendorPrice answers it — the worklist case. */
    public static function unmatched(string $vendorSku): self
    {
        return new self(false, true, null, null, $vendorSku, null);
    }

    /** our_sku given but not found, or neither column named anything. */
    public static function failure(string $reason): self
    {
        return new self(false, false, null, null, null, $reason);
    }
}
