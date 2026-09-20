<?php

declare(strict_types=1);

namespace Number1CategoryProductPageBundle\CatalogViewConfig;

use Number1CategoryProductPageBundle\Service\CategoryPageConfig;

final class AccessoriesCatalogViewConfigProvider extends AbstractRoleCatalogViewConfigProvider
{
    protected function role(): string
    {
        return CategoryPageConfig::ROLE_ACCESSORIES;
    }
}
