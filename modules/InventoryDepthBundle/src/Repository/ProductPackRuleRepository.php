<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Repository;

use App\Entity\ProductCore;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use InventoryDepthBundle\Entity\ProductPackRule;

/**
 * @extends ServiceEntityRepository<ProductPackRule>
 */
class ProductPackRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductPackRule::class);
    }

    /**
     * The one declaration a case SKU has, or null.
     *
     * Singular by construction — `uniq_inventory_pack_rule_case` — which is the whole of the
     * directional decision on {@see ProductPackRule}: "what does this case hold" has one answer.
     */
    public function forCaseProduct(ProductCore $caseProduct): ?ProductPackRule
    {
        return $this->findOneBy(['caseProduct' => $caseProduct]);
    }

    /**
     * Every declaration whose unit SKU is this product — the direction that legitimately returns
     * several rows, because one bottle ships in a twelve AND in a twenty-four.
     *
     * @return list<ProductPackRule>
     */
    public function forUnitProduct(ProductCore $unitProduct): array
    {
        /** @var list<ProductPackRule> $rows */
        $rows = $this->findBy(['unitProduct' => $unitProduct], ['id' => 'ASC']);

        return $rows;
    }

    /**
     * Every declaration, case SKU first — the list the screen renders.
     *
     * @return list<ProductPackRule>
     */
    public function all(): array
    {
        /** @var list<ProductPackRule> $rows */
        $rows = $this->createQueryBuilder('r')
            ->innerJoin('r.caseProduct', 'c')->addSelect('c')
            ->innerJoin('r.unitProduct', 'u')->addSelect('u')
            ->orderBy('c.sku', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
