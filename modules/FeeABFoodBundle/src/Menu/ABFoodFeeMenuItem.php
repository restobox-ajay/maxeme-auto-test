<?php

declare(strict_types=1);

namespace FeeABFoodBundle\Menu;

use App\Contract\Fee\FeeMenuItemInterface;

final class ABFoodFeeMenuItem implements FeeMenuItemInterface
{
    public function getLabel(): string { return 'AB Food Fees'; }
    public function getRoute(): string { return 'admin_bundle_fee_ab_food_config'; }
}
