<?php

declare(strict_types=1);

namespace WooCommerceBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class WooCommerceBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'WooCommerce';
    }

    public function getType(): string
    {
        return 'Connector';
    }

    public function getSource(): string
    {
        return 'WooCommerceBundle';
    }

    public function getEditRoute(): ?string
    {
        return 'admin_bundle_woocommerce_connections';
    }

    public function getDocsUrl(): ?string
    {
        return null;
    }
}
