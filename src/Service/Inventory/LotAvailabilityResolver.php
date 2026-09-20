<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Contract\Inventory\LotAvailabilityProviderInterface;
use App\Entity\ProductCore;
use App\Repository\BundleStatusRepository;

/**
 * Answers, for core only, how much of a specific InventoryLot is actually available (2026-09-14
 * lot/serial/expiry plan) — the lot-scoped twin of {@see InventoryModeResolver}.
 *
 * With no provider registered — modules/InventoryDepthBundle deleted, or switched Inactive —
 * `availableForLot()` returns null for every lot, which {@see AdminOrderStockValidator} reads
 * exactly like "this line named no lot": only the existing product+warehouse check runs, and every
 * order/invoice save behaves exactly as it did before this class existed.
 */
final class LotAvailabilityResolver
{
    /** @param iterable<LotAvailabilityProviderInterface> $providers */
    public function __construct(
        private readonly iterable $providers,
        private readonly BundleStatusRepository $bundleStatusRepo,
    ) {
    }

    /** Null when nothing can answer, $lotId names no lot, or that lot belongs to another product. */
    public function availableForLot(int $lotId, ProductCore $product): ?string
    {
        foreach ($this->providers as $provider) {
            if ($provider instanceof LotAvailabilityProviderInterface && $this->bundleStatusRepo->isActive($provider->getSource())) {
                return $provider->availableForLot($lotId, $product);
            }
        }

        return null;
    }

    /** Null when nothing can answer, or $lotId names no lot. */
    public function labelForLot(int $lotId): ?string
    {
        foreach ($this->providers as $provider) {
            if ($provider instanceof LotAvailabilityProviderInterface && $this->bundleStatusRepo->isActive($provider->getSource())) {
                return $provider->labelForLot($lotId);
            }
        }

        return null;
    }
}
