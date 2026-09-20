<?php

declare(strict_types=1);

namespace Number1HideRealPriceBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class Number1HideRealPriceBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'Number1 Hide Real Price';
    }

    public function getType(): string
    {
        return 'Injection Point';
    }

    public function getSource(): string
    {
        return 'Number1HideRealPriceBundle';
    }

    public function getEditRoute(): ?string
    {
        return null;
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/Number1HideRealPriceBundle/README.md';
    }
}
