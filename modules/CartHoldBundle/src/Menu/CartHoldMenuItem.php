<?php

declare(strict_types=1);

namespace CartHoldBundle\Menu;

use App\Contract\Hook\InjectionPointMenuItemInterface;

final class CartHoldMenuItem implements InjectionPointMenuItemInterface
{
    public function getLabel(): string { return 'Cart Hold'; }
    public function getRoute(): string { return 'admin_bundle_cart_hold_config'; }
    public function getSection(): string { return 'Inventory'; }
}
