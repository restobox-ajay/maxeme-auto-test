<?php

declare(strict_types=1);

namespace AdminMenuBundle\Bundle;

use App\Contract\Bundle\BundleDescriptorInterface;

final class AdminMenuBundleDescriptor implements BundleDescriptorInterface
{
    /**
     * Just the name. The "hiding is not access control" caveat used to ride along in here, because
     * BundleDescriptorInterface has no description field — but a name is not a place to put a
     * sentence: it is also what the App Management toggle prints back ("... is now inactive"), which
     * read badly. The warning belongs where someone is about to act on it, so it lives in the
     * callout above the switches on the config screen instead.
     */
    public function getName(): string
    {
        return 'Admin Menu';
    }

    public function getType(): string
    {
        return 'Configuration';
    }

    public function getSource(): string
    {
        return 'AdminMenuBundle';
    }

    public function getEditRoute(): ?string
    {
        return 'admin_bundle_admin_menu_config';
    }

    public function getDocsUrl(): ?string
    {
        return 'https://github.com/axcelmediacorp/wholesale-b2b-core/blob/main/modules/AdminMenuBundle/README.md';
    }
}
