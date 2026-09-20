<?php

declare(strict_types=1);

namespace ShippingFreeBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class ShippingFreeBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'Free Shipping';
    }

    public function getType(): string
    {
        return 'Shipping';
    }

    public function getSource(): string
    {
        return 'ShippingFreeBundle';
    }

    public function getEditRoute(): ?string
    {
        return null;
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/ShippingFreeBundle/README.md';
    }
}
