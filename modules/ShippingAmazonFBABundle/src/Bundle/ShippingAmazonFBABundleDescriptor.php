<?php

declare(strict_types=1);

namespace ShippingAmazonFBABundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class ShippingAmazonFBABundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'Amazon FBA';
    }

    public function getType(): string
    {
        return 'Shipping';
    }

    public function getSource(): string
    {
        return 'ShippingAmazonFBABundle';
    }

    public function getEditRoute(): ?string
    {
        return 'admin_bundle_shipping_amazon_fba';
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/ShippingAmazonFBABundle/README.md';
    }
}
