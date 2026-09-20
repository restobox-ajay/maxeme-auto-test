<?php

declare(strict_types=1);

namespace App\Service\Product;

use App\Contract\Product\ProductBarcodeSearchProviderInterface;
use App\Repository\BundleStatusRepository;

/**
 * Answers, for core only, which products a search term names by barcode — the barcode-scoped twin
 * of {@see \App\Service\Inventory\LotAvailabilityResolver}.
 *
 * With no provider registered — modules/BarcodeBundle deleted, or switched Inactive —
 * matchingProductIds() returns [] for every term, and {@see ProductPicker::searchPage()} falls back
 * to exactly the name/SKU match it always ran.
 */
final class ProductBarcodeSearchResolver
{
    /** @param iterable<ProductBarcodeSearchProviderInterface> $providers */
    public function __construct(
        private readonly iterable $providers,
        private readonly BundleStatusRepository $bundleStatusRepo,
    ) {
    }

    /** @return list<int> empty when nothing can answer, or the term matches no barcode */
    public function matchingProductIds(string $term): array
    {
        foreach ($this->providers as $provider) {
            if ($provider instanceof ProductBarcodeSearchProviderInterface && $this->bundleStatusRepo->isActive($provider->getSource())) {
                return $provider->matchingProductIds($term);
            }
        }

        return [];
    }

    /**
     * @param list<int> $productIds
     *
     * @return array<int, list<string>> empty when nothing can answer
     */
    public function codesForProducts(array $productIds): array
    {
        foreach ($this->providers as $provider) {
            if ($provider instanceof ProductBarcodeSearchProviderInterface && $this->bundleStatusRepo->isActive($provider->getSource())) {
                return $provider->codesForProducts($productIds);
            }
        }

        return [];
    }
}
