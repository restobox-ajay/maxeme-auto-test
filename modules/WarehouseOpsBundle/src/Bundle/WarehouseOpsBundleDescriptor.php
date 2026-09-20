<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;
use App\Contract\Bundle\RequiresBundlesInterface;

/**
 * Declares what #788 found already true in code but nowhere on the record: 22 files under
 * src/ import InventoryDepthBundle classes directly (bins, lots, the shipped-quantity seam), so
 * this bundle cannot run correctly with Inventory Depth switched off.
 */
final class WarehouseOpsBundleDescriptor implements BundleDescriptorInterface, RequiresBundlesInterface
{
    public function getRequiredBundles(): array
    {
        return ['InventoryDepthBundle'];
    }

    public function getName(): string
    {
        return 'Warehouse Operations';
    }

    public function getType(): string
    {
        return 'Inventory';
    }

    public function getSource(): string
    {
        return 'WarehouseOpsBundle';
    }

    public function getEditRoute(): ?string
    {
        return 'admin_bundle_warehouse_ops_index';
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/WarehouseOpsBundle/README.md';
    }
}
