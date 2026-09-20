<?php

declare(strict_types=1);

namespace ShippingPickupBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class ShippingPickupBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'Pickup Shipping';
    }

    public function getType(): string
    {
        return 'Shipping';
    }

    public function getSource(): string
    {
        return 'ShippingPickupBundle';
    }

    public function getEditRoute(): ?string
    {
        return null;
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/ShippingPickupBundle/README.md';
    }
}
