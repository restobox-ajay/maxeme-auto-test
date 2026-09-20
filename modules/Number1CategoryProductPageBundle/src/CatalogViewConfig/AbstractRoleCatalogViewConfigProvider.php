<?php

declare(strict_types=1);

namespace Number1CategoryProductPageBundle\CatalogViewConfig;

use App\Contract\Bundle\CatalogViewConfigProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Number1CategoryProductPageBundle\Service\CategoryPageConfig;

/**
 * getPoint() is resolved dynamically for the same reason as
 * AbstractRoleTemplateOverrideProvider — the category id it targets is admin-configured, not
 * knowable at compile/service-registration time.
 */
abstract class AbstractRoleCatalogViewConfigProvider implements CatalogViewConfigProviderInterface
{
    public function __construct(
        protected readonly CategoryPageConfig $config,
        protected readonly EntityManagerInterface $entityManager,
    ) {}

    abstract protected function role(): string;

    public function getPoint(): string
    {
        $category = $this->config->resolveCategories($this->entityManager)[$this->role()];

        return 'customer_catalog_index:' . $category->getId();
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function getSource(): string
    {
        return 'Number1CategoryProductPageBundle';
    }

    /** @return array{gridAvailable: bool, listAvailable: bool, defaultView: string} */
    public function getViewConfig(): array
    {
        return $this->config->viewConfig($this->role());
    }
}
