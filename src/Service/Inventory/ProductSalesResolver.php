<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Contract\Inventory\ProductSalesProviderInterface;
use App\Entity\ProductCore;
use App\Repository\BundleStatusRepository;

/**
 * Sums every registered {@see ProductSalesProviderInterface}'s units sold for one product, the
 * same way {@see ProductActivityFeedResolver} collects activity rows: no provider, or an Inactive
 * one, contributes nothing.
 */
final class ProductSalesResolver
{
    /** @param iterable<ProductSalesProviderInterface> $providers */
    public function __construct(
        private readonly iterable $providers,
        private readonly BundleStatusRepository $bundleStatusRepo,
    ) {
    }

    public function unitsSold(ProductCore $product, \DateTimeImmutable $since): float
    {
        $units = 0.0;
        foreach ($this->providers as $provider) {
            if ($provider instanceof ProductSalesProviderInterface && $this->bundleStatusRepo->isActive($provider->getSource())) {
                $units += $provider->unitsSold($product, $since);
            }
        }

        return $units;
    }
}
