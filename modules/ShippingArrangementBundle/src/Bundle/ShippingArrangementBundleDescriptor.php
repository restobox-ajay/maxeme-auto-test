<?php

declare(strict_types=1);

namespace ShippingArrangementBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class ShippingArrangementBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'Shipping Upon Existing Arrangement';
    }

    public function getType(): string
    {
        return 'Shipping';
    }

    public function getSource(): string
    {
        return 'ShippingArrangementBundle';
    }

    public function getEditRoute(): ?string
    {
        return 'admin_bundle_shipping_arrangement_index';
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/ShippingArrangementBundle/README.md';
    }
}
