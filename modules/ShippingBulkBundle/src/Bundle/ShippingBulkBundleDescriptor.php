<?php

declare(strict_types=1);

namespace ShippingBulkBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class ShippingBulkBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'Bulk Delivery';
    }

    public function getType(): string
    {
        return 'Shipping';
    }

    public function getSource(): string
    {
        return 'ShippingBulkBundle';
    }

    public function getEditRoute(): ?string
    {
        return 'admin_bundle_shipping_bulk';
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/ShippingBulkBundle/README.md';
    }
}
