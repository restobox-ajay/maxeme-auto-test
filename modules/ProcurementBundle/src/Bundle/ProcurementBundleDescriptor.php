<?php

declare(strict_types=1);

namespace ProcurementBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;
use App\Contract\Bundle\RequiresBundlesInterface;

/**
 * #788 found this true in code but nowhere on the record: GoodsReceiptLine associates directly
 * with InventoryDepthBundle\Entity\InventoryLot and WarehouseLocation (bin/lot tracking is where
 * received goods land), so receiving cannot run correctly with Inventory Depth switched off.
 *
 * ProductLookup (BarcodeBundle) and PurchaseTaxBreakdown's use of TaxBundle are deliberately NOT
 * declared here — see the exclusion reasons in
 * {@see \App\Tests\Architecture\BundleDependencyDiscoveryTest}.
 */
final class ProcurementBundleDescriptor implements BundleDescriptorInterface, RequiresBundlesInterface
{
    public function getRequiredBundles(): array
    {
        return ['InventoryDepthBundle'];
    }

    public function getName(): string
    {
        return 'Procurement';
    }

    public function getType(): string
    {
        return 'Inventory';
    }

    public function getSource(): string
    {
        return 'ProcurementBundle';
    }

    public function getEditRoute(): ?string
    {
        return 'admin_bundle_procurement_index';
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/ProcurementBundle/README.md';
    }
}
