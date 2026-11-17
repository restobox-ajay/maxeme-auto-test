<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Contract\Inventory\ProductPurchaseHistoryProviderInterface;
use App\Entity\ProductCore;
use App\Repository\BundleStatusRepository;

/**
 * Merges every active ProductPurchaseHistoryProviderInterface into one list, newest first. It is
 * the purchase-history twin of {@see ProductVendorResolver}, except that it asks every provider,
 * not just the first: goods receipts and legacy restocks are both purchases of the same part.
 *
 * With no provider active, the answer is an empty list, read as "never bought".
 */
final class ProductPurchaseHistoryResolver
{
    /** @param iterable<ProductPurchaseHistoryProviderInterface> $providers */
    public function __construct(
        private readonly iterable $providers,
        private readonly BundleStatusRepository $bundleStatusRepo,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function purchaseHistory(ProductCore $product, int $limit): array
    {
        $rows = [];
        foreach ($this->providers as $provider) {
            if (!$provider instanceof ProductPurchaseHistoryProviderInterface) {
                continue;
            }
            $source = $provider->getSource();
            if ($source !== null && !$this->bundleStatusRepo->isActive($source)) {
                continue;
            }
            array_push($rows, ...$provider->purchaseHistory($product, $limit));
        }

        usort($rows, static fn (array $a, array $b): int => $b['date'] <=> $a['date']);

        return array_slice($rows, 0, $limit);
    }
}
