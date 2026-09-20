<?php

namespace App\Repository;

use App\Entity\ProductCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductCategory>
 */
class ProductCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductCategory::class);
    }

    public function findOneByNameAndParent(string $name, ?ProductCategory $parent): ?ProductCategory
    {
        $found = $this->findOneBy(['name' => $name, 'parent' => $parent]);

        return $found instanceof ProductCategory ? $found : null;
    }
}
