<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Inventory;

use App\Contract\Inventory\LotAvailabilityProviderInterface;
use App\Entity\ProductCore;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Repository\InventoryDetailRepository;
use InventoryDepthBundle\Repository\InventoryLotRepository;

/**
 * The bundle's answer to core's lot-scoped question (2026-09-14 lot/serial/expiry plan): how much
 * of lot #N is actually available, straight off InventoryDetailRepository::availableForLot() — the
 * same subtraction the rest of this bundle already lives on, no new mechanism.
 *
 * Registering this is what makes lot-scoped stock checking possible at all. Delete the bundle and
 * App\Service\Inventory\LotAvailabilityResolver finds no provider, every lot-scoped question answers
 * null, and AdminOrderStockValidator falls back to the aggregate product+warehouse check it always
 * ran — which is correct, not a degradation: with no depth layer installed there is no lot ledger to
 * check against in the first place.
 */
final class LotAvailabilityProvider implements LotAvailabilityProviderInterface
{
    public function __construct(
        private readonly InventoryLotRepository $lots,
        private readonly InventoryDetailRepository $details,
    ) {
    }

    public function getSource(): string
    {
        return 'InventoryDepthBundle';
    }

    public function availableForLot(int $lotId, ProductCore $product): ?string
    {
        $lot = $this->lots->find($lotId);

        if (!$lot instanceof InventoryLot || $lot->getProduct() !== $product) {
            return null;
        }

        // On Hold or Recalled (#725): 0, not the physical count. The picker
        // (InventoryLotRepository::withAvailableStock()) already never offers such a lot going
        // forward; this is the save-time backstop for a line naming one directly — a bookmarked
        // page, a hand-crafted POST, a line that was already on the order before the recall. It
        // surfaces as an ordinary shortfall AdminOrderStockValidator already knows how to report
        // and require a reason for, not a new refusal path.
        if (!$lot->isAvailable()) {
            return '0.0000';
        }

        return $this->details->availableForLot($lot);
    }

    public function labelForLot(int $lotId): ?string
    {
        $lot = $this->lots->find($lotId);

        return $lot instanceof InventoryLot ? $lot->getLabel() : null;
    }
}
