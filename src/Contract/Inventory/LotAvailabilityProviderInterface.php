<?php

declare(strict_types=1);

namespace App\Contract\Inventory;

use App\Entity\ProductCore;

/**
 * The one question core asks the inventory-depth layer for the lot/serial/expiry reservation plan
 * (2026-09-14): "how much of lot #N is actually available, and does it even belong to this product?"
 *
 * Same seam, same reason, as {@see DimensionalInventoryProviderInterface}: `InventoryLot` and
 * `InventoryDetail` belong to modules/InventoryDepthBundle, which is deletable, and
 * `AdminOrderStockValidator` (core) may not hold a compile-time reference to either to ask this.
 * With no provider registered — the bundle deleted, or switched Inactive — every lot-scoped question
 * answers null, and {@see App\Service\Inventory\LotAvailabilityResolver} treats null exactly like
 * "this line named no lot at all": the existing product+warehouse check is everything that runs,
 * exactly as it already does today for every product nobody has opted into lot tracking.
 *
 * getSource() names the owning bundle so the App Management Active/Inactive kill-switch applies to
 * the provider without the provider checking its own status — same contract as every other provider
 * on this seam.
 */
interface LotAvailabilityProviderInterface
{
    /** The owning bundle's source, checked against App\Repository\BundleStatusRepository. */
    public function getSource(): string;

    /**
     * `InventoryDetailRepository::availableForLot()` for the lot named by $lotId — the same
     * subtraction ProductInventory::getAvailableQuantity() already does for a whole product, one
     * dimension narrower.
     *
     * Null when $lotId names no lot at all, or names a lot belonging to a product other than
     * $product — both read as "nothing to check against" by the resolver, the same as a line naming
     * no lot.
     */
    public function availableForLot(int $lotId, ProductCore $product): ?string;

    /**
     * `InventoryLot::getLabel()` for $lotId — code and expiry together, the same label the picker
     * shows — or null when $lotId names no lot at all. Used to render a line's already-picked lot
     * back onto the Order/Invoice screen without core holding a compile-time reference to
     * InventoryLot itself.
     */
    public function labelForLot(int $lotId): ?string;
}
