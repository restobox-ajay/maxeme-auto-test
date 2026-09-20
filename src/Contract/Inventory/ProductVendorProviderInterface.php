<?php

declare(strict_types=1);

namespace App\Contract\Inventory;

use App\Entity\ProductCore;

/**
 * "Which vendor(s) has this actually been bought from" — the Product Inventory Hub's identity-card
 * question (docs/plans/2026-09-15-product-inventory-hub.md), answered the same way
 * {@see ProductActivityProviderInterface} answers "what happened to it": a bundle other than the
 * hub's own (InventoryDepthBundle) owns the data (ProcurementBundle's `PurchaseOrderLine`), and the
 * hub may not hold a compile-time reference to it — see
 * `InventoryDepthBundle\Tests\Unit\ThisBundleNamesNoOtherBundleTest`.
 *
 * {@see \App\Service\Inventory\ProductVendorResolver} is the one core service holding the tagged
 * iterator; absent or Inactive means it answers an empty list, same as "never bought from anyone".
 */
interface ProductVendorProviderInterface
{
    /** The owning bundle's source, checked against App\Repository\BundleStatusRepository. */
    public function getSource(): string;

    /** @return list<string> */
    public function vendorNames(ProductCore $product, int $limit): array;
}
