<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Contract\Inventory\ProductVendorProviderInterface;
use App\Entity\ProductCore;
use App\Repository\BundleStatusRepository;

/**
 * Answers, for core (and for InventoryDepthBundle's Product Inventory Hub), which vendor(s) a
 * product has actually been bought from — the vendor-scoped twin of
 * {@see ProductActivityFeedResolver}, same reasoning.
 *
 * With no provider registered — ProcurementBundle deleted, or switched Inactive — this returns an
 * empty list, read exactly like "never bought from anyone", not an error.
 */
final class ProductVendorResolver
{
    /** @param iterable<ProductVendorProviderInterface> $providers */
    public function __construct(
        private readonly iterable $providers,
        private readonly BundleStatusRepository $bundleStatusRepo,
    ) {
    }

    /** @return list<string> */
    public function vendorNames(ProductCore $product, int $limit): array
    {
        foreach ($this->providers as $provider) {
            if ($provider instanceof ProductVendorProviderInterface && $this->bundleStatusRepo->isActive($provider->getSource())) {
                return $provider->vendorNames($product, $limit);
            }
        }

        return [];
    }
}
