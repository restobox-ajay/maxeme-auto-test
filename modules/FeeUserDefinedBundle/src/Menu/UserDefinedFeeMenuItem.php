<?php

declare(strict_types=1);

namespace FeeUserDefinedBundle\Menu;

use App\Contract\Fee\FeeMenuItemInterface;

final class UserDefinedFeeMenuItem implements FeeMenuItemInterface
{
    public function getLabel(): string { return 'User-Defined Fees'; }
    public function getRoute(): string { return 'admin_bundle_fee_user_defined_index'; }
}
