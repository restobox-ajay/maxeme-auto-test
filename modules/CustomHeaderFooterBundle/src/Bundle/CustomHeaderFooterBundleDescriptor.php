<?php

declare(strict_types=1);

namespace CustomHeaderFooterBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class CustomHeaderFooterBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'Custom Header & Footer HTML';
    }

    public function getType(): string
    {
        return 'Injection Point';
    }

    public function getSource(): string
    {
        return 'CustomHeaderFooterBundle';
    }

    public function getEditRoute(): ?string
    {
        return 'admin_bundle_custom_header_footer_index';
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/CustomHeaderFooterBundle/README.md';
    }
}
