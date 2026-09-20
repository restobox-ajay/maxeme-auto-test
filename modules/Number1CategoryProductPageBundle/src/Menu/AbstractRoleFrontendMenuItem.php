<?php

declare(strict_types=1);

namespace Number1CategoryProductPageBundle\Menu;

use App\Contract\Menu\FrontendMenuItemInterface;
use Doctrine\ORM\EntityManagerInterface;
use Number1CategoryProductPageBundle\Service\CategoryPageConfig;

/**
 * getRouteParams() is resolved dynamically for the same reason as
 * AbstractRoleCatalogViewConfigProvider — the target category id is admin-configured, not
 * knowable at compile/service-registration time.
 */
abstract class AbstractRoleFrontendMenuItem implements FrontendMenuItemInterface
{
    public function __construct(
        protected readonly CategoryPageConfig $config,
        protected readonly EntityManagerInterface $entityManager,
    ) {}

    abstract protected function role(): string;

    public function getKey(): string
    {
        return 'number1_category_product_page.' . $this->role();
    }

    public function getLabel(): string
    {
        return CategoryPageConfig::ROLE_DEFAULT_NAMES[$this->role()];
    }

    public function getRoute(): string
    {
        return 'customer_catalog';
    }

    public function getRouteParams(): array
    {
        $category = $this->config->resolveCategories($this->entityManager)[$this->role()];

        return ['ProductSearch[category_id]' => $category->getId()];
    }

    public function getSource(): string
    {
        return 'Number1CategoryProductPageBundle';
    }

    public function isVisible(): bool
    {
        return true;
    }
}
