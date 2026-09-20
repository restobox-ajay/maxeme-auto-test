<?php

declare(strict_types=1);

namespace TaxCanadaSimpleBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class TaxCanadaSimpleBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'Canada Simple Tax Bundle';
    }

    public function getType(): string
    {
        return 'Tax';
    }

    public function getSource(): string
    {
        return 'TaxCanadaSimpleBundle';
    }

    public function getEditRoute(): ?string
    {
        return 'admin_bundle_tax_canada_simple_config';
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/TaxCanadaSimpleBundle/README.md';
    }
}
