<?php

declare(strict_types=1);

namespace FeeUserDefinedBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class FeeUserDefinedBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'User-Defined Fees';
    }

    public function getType(): string
    {
        return 'Fee';
    }

    public function getSource(): string
    {
        return 'FeeUserDefinedBundle';
    }

    public function getEditRoute(): ?string
    {
        return 'admin_bundle_fee_user_defined_index';
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/FeeUserDefinedBundle/README.md';
    }
}
