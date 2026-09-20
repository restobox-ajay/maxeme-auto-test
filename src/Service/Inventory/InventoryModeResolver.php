<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Contract\Inventory\DimensionalInventoryProviderInterface;
use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Repository\BundleStatusRepository;

/**
 * Answers, for core only, whether a product's `product_inventory.quantity` is typed by a human or
 * maintained by a depth layer underneath it (#550).
 *
 * The important property is what happens with NO provider registered, which is what a checkout of
 * this repo with modules/InventoryDepthBundle deleted looks like: `isDimensional()` returns false
 * for every product regardless of what `product_core.inventory_mode` says, so every quantity field
 * is editable and every writer behaves exactly as it did before #550. The stored `dimensional`
 * values are inert data rather than a lockout, and the numbers themselves are untouched — they were
 * never derived on read.
 *
 * This resolver deliberately answers only the two questions core has:
 *
 *  - render an editable quantity box, or a link? (`isDimensional`)
 *  - what link? (`breakdownUrl`)
 *
 * Anything about bins, lots, serials or movements is the bundle's, and core never asks.
 */
final class InventoryModeResolver
{
    /** @param iterable<DimensionalInventoryProviderInterface> $providers */
    public function __construct(
        private readonly iterable $providers,
        private readonly BundleStatusRepository $bundleStatusRepo,
    ) {
    }

    /**
     * Is `dimensional` a mode anything can actually honour right now? False with the bundle absent
     * or switched Inactive on App Management, which is what makes the mode un-selectable rather
     * than merely unhelpful.
     */
    public function isDimensionalAvailable(): bool
    {
        return $this->activeProvider() !== null;
    }

    /**
     * True only when BOTH the product opted in AND a provider is live. The conjunction is the whole
     * safety property: a database carrying `dimensional` rows from a previous install still reads as
     * `simple` everywhere once the bundle is gone.
     */
    public function isDimensional(ProductCore $product): bool
    {
        return $product->getInventoryMode() === ProductCore::INVENTORY_MODE_DIMENSIONAL
            && $this->isDimensionalAvailable();
    }

    /** Where to send an admin instead of an editable quantity box. Null whenever isDimensional() is false. */
    public function breakdownUrl(ProductCore $product, ?Warehouse $warehouse = null): ?string
    {
        if ($product->getInventoryMode() !== ProductCore::INVENTORY_MODE_DIMENSIONAL) {
            return null;
        }

        return $this->activeProvider()?->breakdownUrl($product, $warehouse);
    }

    /**
     * Applies a mode change through whoever owns it, and reports whether anything happened.
     *
     * Returns false when there is no active provider, and deliberately writes nothing in that case:
     * with nothing able to maintain a breakdown, quietly flipping the column to `dimensional` would
     * leave a product whose quantity nobody types and nobody maintains. `simple` is the mode that
     * needs nothing installed, so core can always fall back to it.
     */
    public function switchMode(ProductCore $product, string $mode, ?string $actor = null): bool
    {
        $provider = $this->activeProvider();

        if ($provider === null) {
            if ($mode === ProductCore::INVENTORY_MODE_SIMPLE) {
                $product->setInventoryMode(ProductCore::INVENTORY_MODE_SIMPLE);

                return true;
            }

            return false;
        }

        $provider->switchMode($product, $mode, $actor);

        return true;
    }

    private function activeProvider(): ?DimensionalInventoryProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider instanceof DimensionalInventoryProviderInterface
                && $this->bundleStatusRepo->isActive($provider->getSource())
            ) {
                return $provider;
            }
        }

        return null;
    }
}
