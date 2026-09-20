<?php

declare(strict_types=1);

namespace Number1ProductImportBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class Number1ProductImportBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'Number1 Product CSV Import';
    }

    public function getType(): string
    {
        return 'Import';
    }

    public function getSource(): string
    {
        return 'Number1ProductImportBundle';
    }

    public function getEditRoute(): ?string
    {
        return 'admin_bundle_number1_product_import_index';
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/Number1ProductImportBundle/README.md';
    }
}
