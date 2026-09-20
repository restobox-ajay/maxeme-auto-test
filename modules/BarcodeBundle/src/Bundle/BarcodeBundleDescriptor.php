<?php

declare(strict_types=1);

namespace BarcodeBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class BarcodeBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'Barcodes';
    }

    public function getType(): string
    {
        return 'Inventory';
    }

    public function getSource(): string
    {
        return 'BarcodeBundle';
    }

    public function getEditRoute(): ?string
    {
        return 'admin_bundle_barcodes';
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/BarcodeBundle/README.md';
    }
}
