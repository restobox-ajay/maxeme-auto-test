<?php

declare(strict_types=1);

namespace CartHoldBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class CartHoldBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'Cart Hold';
    }

    public function getType(): string
    {
        return 'Inventory';
    }

    public function getSource(): string
    {
        return 'CartHoldBundle';
    }

    public function getEditRoute(): ?string
    {
        return 'admin_bundle_cart_hold_config';
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/CartHoldBundle/README.md';
    }
}
