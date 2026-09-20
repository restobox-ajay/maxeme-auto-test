<?php

declare(strict_types=1);

namespace Number1CategoryProductPageBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class Number1CategoryProductPageBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'Category Product Pages';
    }

    public function getType(): string
    {
        return 'Template Override';
    }

    public function getSource(): string
    {
        return 'Number1CategoryProductPageBundle';
    }

    public function getEditRoute(): ?string
    {
        return 'admin_bundle_number1_category_product_page_index';
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/Number1CategoryProductPageBundle/README.md';
    }
}
