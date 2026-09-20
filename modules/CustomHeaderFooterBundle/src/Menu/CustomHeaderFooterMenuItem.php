<?php

declare(strict_types=1);

namespace CustomHeaderFooterBundle\Menu;

use App\Contract\Hook\InjectionPointMenuItemInterface;

final class CustomHeaderFooterMenuItem implements InjectionPointMenuItemInterface
{
    public function getLabel(): string
    {
        return 'Custom Header & Footer HTML';
    }

    public function getRoute(): string
    {
        return 'admin_bundle_custom_header_footer_index';
    }

    public function getSection(): string
    {
        return 'Page Customization';
    }
}
