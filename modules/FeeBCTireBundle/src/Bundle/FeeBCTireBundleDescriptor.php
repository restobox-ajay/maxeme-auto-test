<?php

declare(strict_types=1);

namespace FeeBCTireBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class FeeBCTireBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'BC Tire Stewardship';
    }

    public function getType(): string
    {
        return 'Fee';
    }

    public function getSource(): string
    {
        return 'FeeBCTireBundle';
    }

    public function getEditRoute(): ?string
    {
        return 'admin_bundle_fee_bc_tire_config';
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/FeeBCTireBundle/README.md';
    }
}
