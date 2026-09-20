<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Contract\Inventory\InboundTransferProviderInterface;
use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Repository\BundleStatusRepository;

/**
 * Answers, for InventoryDepthBundle's reorder engine, how much of a product is already dispatched
 * toward a warehouse but not yet received — the in-transit-transfer twin of
 * {@see ProductVendorResolver}, same reasoning (#706).
 *
 * With no provider registered — WarehouseOpsBundle deleted, or switched Inactive — this returns 0,
 * read exactly like "nothing in transit", not an error.
 */
final class InboundTransferResolver
{
    /** @param iterable<InboundTransferProviderInterface> $providers */
    public function __construct(
        private readonly iterable $providers,
        private readonly BundleStatusRepository $bundleStatusRepo,
    ) {
    }

    public function inTransitQuantity(ProductCore $product, Warehouse $warehouse): int
    {
        foreach ($this->providers as $provider) {
            if ($provider instanceof InboundTransferProviderInterface && $this->bundleStatusRepo->isActive($provider->getSource())) {
                return $provider->inTransitQuantity($product, $warehouse);
            }
        }

        return 0;
    }
}
