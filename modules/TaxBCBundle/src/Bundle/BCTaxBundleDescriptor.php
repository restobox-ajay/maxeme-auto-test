<?php

declare(strict_types=1);

namespace TaxBCBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class BCTaxBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'BC Tax Bundle';
    }

    public function getType(): string
    {
        return 'Tax';
    }

    public function getSource(): string
    {
        return 'TaxBCBundle';
    }

    public function getEditRoute(): ?string
    {
        return 'admin_bundle_tax_bc_config';
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/TaxBCBundle/README.md';
    }
}
