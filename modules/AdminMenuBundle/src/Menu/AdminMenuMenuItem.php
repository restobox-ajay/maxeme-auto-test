<?php

declare(strict_types=1);

namespace AdminMenuBundle\Menu;

use App\Contract\Hook\InjectionPointMenuItemInterface;

final class AdminMenuMenuItem implements InjectionPointMenuItemInterface
{
    public function getLabel(): string
    {
        return 'Admin Menu';
    }

    public function getRoute(): string
    {
        return 'admin_bundle_admin_menu_config';
    }

    public function getSection(): string
    {
        return 'Configuration';
    }
}
