<?php

declare(strict_types=1);

namespace Number1CategoryProductPageBundle\Menu;

use Number1CategoryProductPageBundle\Service\CategoryPageConfig;

final class WheelFrontendMenuItem extends AbstractRoleFrontendMenuItem
{
    protected function role(): string
    {
        return CategoryPageConfig::ROLE_WHEEL;
    }
}
