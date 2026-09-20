<?php

declare(strict_types=1);

namespace App\Menu\Core;

use App\Contract\Menu\FrontendMenuItemInterface;

final class FaqMenuItem implements FrontendMenuItemInterface
{
    public function getKey(): string
    {
        return 'core.faq';
    }

    public function getLabel(): string
    {
        return 'FAQ';
    }

    public function getRoute(): string
    {
        return 'customer_faq';
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
