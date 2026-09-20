<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Contract\Inventory\ProductActivityProviderInterface;
use App\Entity\ProductCore;
use App\Repository\BundleStatusRepository;

/**
 * Collects every registered {@see ProductActivityProviderInterface}'s rows for one product — the
 * one core service that holds the tagged iterator, so a module (the Product Inventory Hub's own
 * `StockController::byProduct()` included) never needs a compile-time reference to a sibling
 * module's provider class, only to this resolver and the interface both sit behind.
 *
 * With no provider registered for a given source — that module deleted, or switched Inactive —
 * this simply contributes nothing for it, the same as every other provider seam in this app.
 */
final class ProductActivityFeedResolver
{
    /** @param iterable<ProductActivityProviderInterface> $providers */
    public function __construct(
        private readonly iterable $providers,
        private readonly BundleStatusRepository $bundleStatusRepo,
    ) {
    }

    /**
     * @return list<array{type: string, date: string, label: string, quantity: string, url: ?string}>
     */
    public function activityFor(ProductCore $product, int $limit): array
    {
        $rows = [];

        foreach ($this->providers as $provider) {
            if (!$provider instanceof ProductActivityProviderInterface || !$this->bundleStatusRepo->isActive($provider->getSource())) {
                continue;
            }

            foreach ($provider->activityFor($product, $limit) as $row) {
                $rows[] = $row + ['type' => $provider->getType()];
            }
        }

        return $rows;
    }
}
