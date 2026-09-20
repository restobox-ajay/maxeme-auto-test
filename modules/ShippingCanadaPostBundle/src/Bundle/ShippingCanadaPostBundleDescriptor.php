<?php

declare(strict_types=1);

namespace ShippingCanadaPostBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class ShippingCanadaPostBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'Canada Post';
    }

    public function getType(): string
    {
        return 'Shipping';
    }

    public function getSource(): string
    {
        return 'ShippingCanadaPostBundle';
    }

    public function getEditRoute(): ?string
    {
        return 'admin_bundle_shipping_canada_post';
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/ShippingCanadaPostBundle/README.md';
    }
}
