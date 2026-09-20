<?php

declare(strict_types=1);

namespace App\Contract\Inventory;

use App\Entity\ProductCore;
use App\Entity\Warehouse;

/**
 * The one question core asks the inventory-depth layer: "is `dimensional` a real option right now,
 * and if this product is on it, where do I send the admin instead of an editable quantity box?"
 *
 * Core defines the seam and implements nothing. With no provider registered — i.e. with
 * modules/InventoryDepthBundle deleted — App\Service\Inventory\InventoryModeResolver answers
 * "no" to everything, every product is treated as `simple`, and every quantity field goes back to
 * being editable. That is the whole of the bundle-removal story, and it is why removing the bundle
 * loses the breakdown and not the number: `product_inventory.quantity` is maintained by whoever
 * writes it, never derived on read, so it is already sitting there correct.
 *
 * The `inventory_mode` column stays on ProductCore rather than moving here, because core has to
 * decide whether to render an editable field and it cannot ask the bundle that without depending
 * on it. What the bundle owns is whether the `dimensional` value MEANS anything.
 *
 * getSource() names the owning bundle so the App Management Active/Inactive kill-switch applies to
 * the provider without the provider checking its own status — same contract as
 * App\Contract\Menu\AdminMenuOverrideProviderInterface.
 */
interface DimensionalInventoryProviderInterface
{
    /** The owning bundle's source, checked against App\Repository\BundleStatusRepository. */
    public function getSource(): string;

    /**
     * Where an admin manages this product's stock instead of typing a number. Null means the
     * provider has nothing to offer for this product, which core reads the same as "simple".
     */
    public function breakdownUrl(ProductCore $product, ?Warehouse $warehouse = null): ?string;

    /**
     * Moves the product onto `$mode`.
     *
     * Core calls this rather than writing `inventory_mode` itself, because only the provider knows
     * what a switch costs, and the two directions do not cost the same:
     *
     *  - `simple → dimensional` needs an **opening balance** — the breakdown rows have to come from
     *    somewhere, and the only honest source is the total already sitting in `product_inventory`.
     *  - `dimensional → simple` needs **nothing at all** — the total is already there.
     *
     * Either way the NUMBER does not change. That asymmetry falls straight out of the invariant and
     * is the same reason the bundle can be removed with no conversion step.
     */
    public function switchMode(ProductCore $product, string $mode, ?string $actor = null): void;
}
