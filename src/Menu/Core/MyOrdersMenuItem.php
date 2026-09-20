<?php

declare(strict_types=1);

namespace App\Menu\Core;

use App\Contract\Menu\FrontendMenuItemInterface;

final class MyOrdersMenuItem implements FrontendMenuItemInterface
{
    public function getKey(): string
    {
        return 'core.my_orders';
    }

    public function getLabel(): string
    {
        return 'My Orders';
    }

    public function getRoute(): string
    {
        return 'customer_orders';
    }

    public function getRouteParams(): array
    {
        return [];
    }

    public function getSource(): string
    {
        return 'Core';
    }

    public function isVisible(): bool
    {
        return true;
    }
}
