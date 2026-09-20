<?php

declare(strict_types=1);

namespace FeeBCFoodBundle\Menu;

use App\Contract\Fee\FeeMenuItemInterface;

final class BCFoodFeeMenuItem implements FeeMenuItemInterface
{
    public function getLabel(): string { return 'BC Food Fees'; }
    public function getRoute(): string { return 'admin_bundle_fee_bc_food_config'; }
}
