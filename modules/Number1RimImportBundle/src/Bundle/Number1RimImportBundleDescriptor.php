<?php

declare(strict_types=1);

namespace Number1RimImportBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class Number1RimImportBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'Number1 Rim API Import';
    }

    public function getType(): string
    {
        return 'Import';
    }

    public function getSource(): string
    {
        return 'Number1RimImportBundle';
    }

    public function getEditRoute(): ?string
    {
        return 'admin_bundle_number1_rim_import_index';
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/Number1RimImportBundle/README.md';
    }
}
