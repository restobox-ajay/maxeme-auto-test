<?php

declare(strict_types=1);

namespace FeeABFoodBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class FeeABFoodBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'AB Food Fees';
    }

    public function getType(): string
    {
        return 'Fee';
    }

    public function getSource(): string
    {
        return 'FeeABFoodBundle';
    }

    public function getEditRoute(): ?string
    {
        return 'admin_bundle_fee_ab_food_config';
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/FeeABFoodBundle/README.md';
    }
}
