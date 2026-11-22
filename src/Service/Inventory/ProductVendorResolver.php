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
        // Every active provider's names, in provider order, each name once (the product's own vendor
        // set by the app, then the ones it was bought from on purchase orders).
        $names = [];
        foreach ($this->providers as $provider) {
            if ($provider instanceof ProductVendorProviderInterface && $this->bundleStatusRepo->isActive($provider->getSource())) {
                foreach ($provider->vendorNames($product, $limit) as $name) {
                    if (!in_array($name, $names, true)) {
                        $names[] = $name;
                    }
                }
            }
        }

        return array_slice($names, 0, $limit);
    }
}
