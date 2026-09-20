<?php

namespace App\Repository;

use App\Entity\ProductCore;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductCore>
 */
class ProductCoreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductCore::class);
    }

    /**
     * The one exact-SKU lookup, for a caller that has a SKU and wants the product it names —
     * matching the exact-match, non-deleted-by-default convention every hand-rolled
     * `findOneBy(['sku' => ...])` call site in this app already assumes.
     */
    public function findBySku(string $sku, bool $includeDeleted = false): ?ProductCore
    {
        $criteria = ['sku' => trim($sku)];
        if (!$includeDeleted) {
            $criteria['deleted'] = false;
        }

        return $this->findOneBy($criteria);
    }
}
