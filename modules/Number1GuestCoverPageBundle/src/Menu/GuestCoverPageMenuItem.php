<?php

declare(strict_types=1);

namespace Number1GuestCoverPageBundle\Menu;

use App\Contract\Hook\InjectionPointMenuItemInterface;

final class GuestCoverPageMenuItem implements InjectionPointMenuItemInterface
{
    public function getLabel(): string
    {
        return 'Guest Cover Page';
    }

    public function getRoute(): string
    {
        return 'admin_bundle_guest_cover_page_index';
    }

    public function getSection(): string
    {
        return 'Number1 Integration';
    }
}
