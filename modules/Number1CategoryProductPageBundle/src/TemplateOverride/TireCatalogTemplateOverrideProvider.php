<?php

declare(strict_types=1);

namespace Number1CategoryProductPageBundle\TemplateOverride;

use Number1CategoryProductPageBundle\Service\CategoryPageConfig;

final class TireCatalogTemplateOverrideProvider extends AbstractRoleTemplateOverrideProvider
{
    protected function role(): string
    {
        return CategoryPageConfig::ROLE_TIRE;
    }

    public function getTemplate(): string
    {
        return '@Number1CategoryProductPage/tire_index.html.twig';
    }
}
