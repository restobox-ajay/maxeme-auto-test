<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProductAvailableUnit;
use App\Entity\ProductCore;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductAvailableUnit>
 */
class ProductAvailableUnitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductAvailableUnit::class);
    }

    /**
     * One product's list, smallest ratio first.
     *
     * Ordered by the ratio rather than by name or id, so the list reads bottom-up the way somebody
     * thinks about it — `EA`, then `BOX-12`, then `PALLET-240` — whatever order the boxes were
     * ticked in.
     *
     * @return list<ProductAvailableUnit>
     */
    public function forProduct(ProductCore $product): array
    {
        // A product being created has no id yet, and nothing can reference it. Asked rather than
        // assumed, because passing an identifier-less entity as a parameter is an exception and the
        // product form renders this block before the row exists.
        if ($product->getId() === null) {
            return [];
        }

        /** @var list<ProductAvailableUnit> $rows */
        $rows = $this->createQueryBuilder('a')
            ->join('a.unit', 'u')->addSelect('u')
            ->andWhere('a.product = :product')->setParameter('product', $product)
            ->orderBy('u.factorToFamilyBase', 'ASC')
            ->addOrderBy('u.code', 'ASC')
            ->getQuery()->getResult();

        return $rows;
    }

    /**
     * The same thing for many products at once, keyed by product id.
     *
     * One query for a whole document rather than one per row: a twenty-line order asking per row is
     * twenty queries to render a dropdown. A product with no rows is simply absent from the map —
     * blank is allowed, and callers read it as the empty list.
     *
     * @param list<int> $productIds
     *
     * @return array<int, list<ProductAvailableUnit>>
     */
    public function forProducts(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter($productIds, static fn (int $id): bool => $id > 0)));
        if ($productIds === []) {
            return [];
        }

        /** @var list<ProductAvailableUnit> $rows */
        $rows = $this->createQueryBuilder('a')
            ->join('a.unit', 'u')->addSelect('u')
            ->andWhere('IDENTITY(a.product) IN (:ids)')->setParameter('ids', $productIds)
            ->orderBy('u.factorToFamilyBase', 'ASC')
            ->addOrderBy('u.code', 'ASC')
            ->getQuery()->getResult();

        $byProduct = [];
        foreach ($rows as $row) {
            $byProduct[(int) $row->getProduct()->getId()][] = $row;
        }

        return $byProduct;
    }
}
