<?php

declare(strict_types=1);

namespace Number1CategoryProductPageBundle\TemplateOverride;

use Number1CategoryProductPageBundle\Service\CategoryPageConfig;

final class AccessoriesCatalogTemplateOverrideProvider extends AbstractRoleTemplateOverrideProvider
{
    protected function role(): string
    {
        return CategoryPageConfig::ROLE_ACCESSORIES;
    }

    public function getTemplate(): string
    {
        return '@Number1CategoryProductPage/hardware_index.html.twig';
    }
}
