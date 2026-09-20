<?php

declare(strict_types=1);

namespace FeeFuelSurchargesBundle\Menu;

use App\Contract\Fee\FeeMenuItemInterface;

final class FuelSurchargeFeeMenuItem implements FeeMenuItemInterface
{
    public function getLabel(): string { return 'Fuel Surcharges'; }
    public function getRoute(): string { return 'admin_bundle_fee_fuel_surcharges_config'; }
}
