<?php

declare(strict_types=1);

namespace Number1CategoryProductPageBundle\CatalogFacet;

use App\Contract\Bundle\CatalogFacetProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Number1CategoryProductPageBundle\Service\CategoryPageConfig;

final class WheelCatalogFacetProvider implements CatalogFacetProviderInterface
{
    public function __construct(
        private readonly CategoryPageConfig $config,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function getPoint(): string
    {
        $category = $this->config->resolveCategories($this->entityManager)[CategoryPageConfig::ROLE_WHEEL];

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

    public function getFacets(): array
    {
        return $this->config->wheelFacets();
    }
}
