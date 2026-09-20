<?php

declare(strict_types=1);

namespace App\Menu\Core;

use App\Contract\Menu\FrontendMenuItemInterface;

final class MyQuotesMenuItem implements FrontendMenuItemInterface
{
    public function getKey(): string
    {
        return 'core.my_quotes';
    }

    public function getLabel(): string
    {
        return 'My Quotes';
    }

    public function getRoute(): string
    {
        return 'customer_estimates';
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
