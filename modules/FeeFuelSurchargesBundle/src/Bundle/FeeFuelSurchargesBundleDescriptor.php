<?php

declare(strict_types=1);

namespace FeeFuelSurchargesBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class FeeFuelSurchargesBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'Fuel Surcharges';
    }

    public function getType(): string
    {
        return 'Fee';
    }

    public function getSource(): string
    {
        return 'FeeFuelSurchargesBundle';
    }

    public function getEditRoute(): ?string
    {
        return 'admin_bundle_fee_fuel_surcharges_config';
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/FeeFuelSurchargesBundle/README.md';
    }
}
