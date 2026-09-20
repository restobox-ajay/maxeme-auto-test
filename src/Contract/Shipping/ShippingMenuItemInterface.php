<?php

declare(strict_types=1);

namespace App\Contract\Shipping;

interface ShippingMenuItemInterface
{
    public function getLabel(): string;

    public function getRoute(): string;
}
