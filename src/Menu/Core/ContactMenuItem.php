<?php

declare(strict_types=1);

namespace App\Menu\Core;

use App\Contract\Menu\FrontendMenuItemInterface;

final class ContactMenuItem implements FrontendMenuItemInterface
{
    public function getKey(): string
    {
        return 'core.contact';
    }

    public function getLabel(): string
    {
        return 'Contact';
    }

    public function getRoute(): string
    {
        return 'customer_contact';
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
