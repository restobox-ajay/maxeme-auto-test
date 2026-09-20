<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class InventoryDepthBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'Inventory Depth';
    }

    public function getType(): string
    {
        return 'Inventory';
    }

    public function getSource(): string
    {
        return 'InventoryDepthBundle';
    }

    public function getEditRoute(): ?string
    {
        return 'admin_bundle_inventory_depth_index';
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/InventoryDepthBundle/README.md';
    }
}
