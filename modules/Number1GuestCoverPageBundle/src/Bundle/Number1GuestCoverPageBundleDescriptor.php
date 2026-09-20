<?php

declare(strict_types=1);

namespace Number1GuestCoverPageBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class Number1GuestCoverPageBundleDescriptor implements BundleDescriptorInterface
{
    public function getName(): string
    {
        return 'Guest Cover Page';
    }

    public function getType(): string
    {
        return 'Template Override';
    }

    public function getSource(): string
    {
        return 'Number1GuestCoverPageBundle';
    }

    public function getEditRoute(): ?string
    {
        return 'admin_bundle_guest_cover_page_index';
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/Number1GuestCoverPageBundle/README.md';
    }
}
