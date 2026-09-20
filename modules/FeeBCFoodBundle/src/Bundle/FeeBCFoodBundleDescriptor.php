<?php

declare(strict_types=1);

namespace FeeBCFoodBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class FeeBCFoodBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'BC Food Fees';
    }

    public function getType(): string
    {
        return 'Fee';
    }

    public function getSource(): string
    {
        return 'FeeBCFoodBundle';
    }

    public function getEditRoute(): ?string
    {
        return 'admin_bundle_fee_bc_food_config';
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/FeeBCFoodBundle/README.md';
    }
}
