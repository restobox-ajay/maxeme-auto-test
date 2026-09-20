<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Inventory;

use App\Contract\Inventory\DimensionalInventoryProviderInterface;
use App\Entity\ProductCore;
use App\Entity\Warehouse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The bundle's answer to core's one question (#550): yes, `dimensional` means something while I am
 * installed and Active, and here is where the admin manages this product's stock instead of typing
 * a number.
 *
 * Registering this is the *only* thing that makes `dimensional` selectable. Delete the bundle and
 * App\Service\Inventory\InventoryModeResolver finds no provider, every product reads as `simple`,
 * and every quantity field is editable again — with the numbers exactly as they were, because they
 * were never derived on read.
 */
final class DimensionalInventoryProvider implements DimensionalInventoryProviderInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly InventoryModeSwitcher $switcher,
    ) {
    }

    public function getSource(): string
    {
        return 'InventoryDepthBundle';
    }

    public function breakdownUrl(ProductCore $product, ?Warehouse $warehouse = null): ?string
    {
        $productId = $product->getId();
        if ($productId === null) {
            return null;
        }

        $params = ['id' => $productId];
        if ($warehouse instanceof Warehouse && $warehouse->getId() !== null) {
            $params['warehouse'] = $warehouse->getId();
        }

        return $this->urlGenerator->generate('admin_bundle_inventory_depth_stock_by_product', $params);
    }

    public function switchMode(ProductCore $product, string $mode, ?string $actor = null): void
    {
        if ($mode === ProductCore::INVENTORY_MODE_DIMENSIONAL) {
            $this->switcher->toDimensional($product, $actor);

            return;
        }

        $this->switcher->toSimple($product);
    }
}
