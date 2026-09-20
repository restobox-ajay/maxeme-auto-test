<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Repository;

use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use InventoryDepthBundle\Entity\ProductReorderRule;

/**
 * @extends ServiceEntityRepository<ProductReorderRule>
 */
class ProductReorderRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductReorderRule::class);
    }

    public function forPair(ProductCore $product, Warehouse $warehouse): ?ProductReorderRule
    {
        return $this->findOneBy(['product' => $product, 'warehouse' => $warehouse]);
    }

    /**
     * Every managed row, narrowed by the filters the screen offers.
     *
     * The candidate set is bounded by the number of ROWS SOMEBODY HAS SET A LEVEL ON, not by the
     * catalogue: a product nobody has managed has no row here and is never a candidate. That is what
     * makes it safe for the caller to evaluate the rule in PHP rather than restating availability
     * in DQL — the sum has eleven terms, three of which are switched on and off by bundle status,
     * and a second copy of it here would be wrong the first time core changed one.
     *
     * @return list<ProductReorderRule>
     */
    public function managed(?int $warehouseId, string $productTerm): array
    {
        $qb = $this->createQueryBuilder('r')
            ->innerJoin('r.product', 'p')->addSelect('p')
            ->innerJoin('r.warehouse', 'w')->addSelect('w');

        if ($warehouseId !== null) {
            $qb->andWhere('w.id = :fw')->setParameter('fw', $warehouseId);
        }

        if ($productTerm !== '') {
            $qb->andWhere('p.sku LIKE :fp OR p.name LIKE :fp')->setParameter('fp', '%' . $productTerm . '%');
        }

        /** @var list<ProductReorderRule> $rows */
        $rows = $qb
            ->orderBy('p.sku', 'ASC')
            ->addOrderBy('w.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * The `product_inventory` rows behind a set of reorder rules, keyed `productId:warehouseId`.
     *
     * One query for the whole page rather than one per row. Loading them through the ORM is what
     * gets `BundleBucketAvailabilityGate` to stamp each row on the way in, which is what makes
     * `getAvailableQuantity()` and `positiveBucketsCount()` true answers rather than raw columns.
     *
     * @param list<ProductReorderRule> $rules
     *
     * @return array<string, ProductInventory>
     */
    public function inventoryFor(EntityManagerInterface $em, array $rules): array
    {
        if ($rules === []) {
            return [];
        }

        $productIds = [];
        foreach ($rules as $rule) {
            $id = $rule->getProduct()->getId();
            if ($id !== null) {
                $productIds[$id] = $id;
            }
        }

        if ($productIds === []) {
            return [];
        }

        /** @var list<ProductInventory> $rows */
        $rows = $em->getRepository(ProductInventory::class)->createQueryBuilder('inv')
            ->where('inv.product IN (:products)')->setParameter('products', array_values($productIds))
            ->getQuery()
            ->getResult();

        $byPair = [];
        foreach ($rows as $row) {
            $warehouse = $row->getWarehouse();
            if (!$warehouse instanceof Warehouse) {
                // Core allows a warehouse-less inventory row; a reorder rule always names one, so
                // such a row can never be the partner of any rule here.
                continue;
            }

            $byPair[$row->getProduct()->getId() . ':' . $warehouse->getId()] = $row;
        }

        return $byPair;
    }
}
