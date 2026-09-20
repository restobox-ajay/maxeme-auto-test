<?php

declare(strict_types=1);

namespace App\Tests\Support\Fixtures;

use App\Contract\Menu\FrontendMenuItemInterface;

/**
 * Registered as `app.frontend_menu_item` only in the test environment (see
 * `when@test` in config/services.yaml) so FrontendMenuManagementCest and
 * FrontendMenuExtension have a real tagged item to exercise end-to-end,
 * independent of the core/bundle items also tagged in production.
 */
final class TestFrontendMenuItem implements FrontendMenuItemInterface
{
    public function getKey(): string
    {
        return 'test.frontend.menu.item';
    }

    public function getLabel(): string
    {
        return 'Test Frontend Menu Item';
    }

    public function getRoute(): string
    {
        return 'customer_home';
    }

    public function getRouteParams(): array
    {
        return [];
    }

    public function getSource(): string
    {
        return 'FunctionalTestFixture';
    }

    public function isVisible(): bool
    {
        return true;
    }
}
