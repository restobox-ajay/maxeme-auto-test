<?php

declare(strict_types=1);

namespace FeeBCTireBundle\Menu;

use App\Contract\Fee\FeeMenuItemInterface;

final class BCTireFeeMenuItem implements FeeMenuItemInterface
{
    public function getLabel(): string { return 'BC Tire Stewardship'; }
    public function getRoute(): string { return 'admin_bundle_fee_bc_tire_config'; }
}
