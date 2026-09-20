<?php

declare(strict_types=1);

namespace Number1CategoryProductPageBundle\TemplateOverride;

use Number1CategoryProductPageBundle\Service\CategoryPageConfig;

final class WheelCatalogTemplateOverrideProvider extends AbstractRoleTemplateOverrideProvider
{
    protected function role(): string
    {
        return CategoryPageConfig::ROLE_WHEEL;
    }

    public function getTemplate(): string
    {
        return '@Number1CategoryProductPage/wheel_index.html.twig';
    }
}
