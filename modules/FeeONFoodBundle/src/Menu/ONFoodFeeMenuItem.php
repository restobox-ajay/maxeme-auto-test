<?php

declare(strict_types=1);

namespace FeeONFoodBundle\Menu;

use App\Contract\Fee\FeeMenuItemInterface;

final class ONFoodFeeMenuItem implements FeeMenuItemInterface
{
    public function getLabel(): string { return 'ON Food Fees'; }
    public function getRoute(): string { return 'admin_bundle_fee_on_food_config'; }
}
