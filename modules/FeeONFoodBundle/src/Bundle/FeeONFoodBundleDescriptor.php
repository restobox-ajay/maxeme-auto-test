<?php

declare(strict_types=1);

namespace FeeONFoodBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class FeeONFoodBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'ON Food Fees';
    }

    public function getType(): string
    {
        return 'Fee';
    }

    public function getSource(): string
    {
        return 'FeeONFoodBundle';
    }

    public function getEditRoute(): ?string
    {
        return 'admin_bundle_fee_on_food_config';
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/FeeONFoodBundle/README.md';
    }
}
