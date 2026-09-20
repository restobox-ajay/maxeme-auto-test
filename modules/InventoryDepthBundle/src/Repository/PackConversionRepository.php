<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use InventoryDepthBundle\Entity\PackConversion;
use InventoryDepthBundle\Entity\ProductPackRule;

/**
 * @extends ServiceEntityRepository<PackConversion>
 */
class PackConversionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PackConversion::class);
    }

    /**
     * The most recent conversions, newest first — what the screen shows under the form.
     *
     * Ordered by id rather than by the group's `occurred_at`, because a conversion may be backdated
     * and "what did I just do" is answered by write order. The group's own date is rendered beside
     * it, so the two are never confused.
     *
     * @return list<PackConversion>
     */
    public function recent(int $limit = 25): array
    {
        /** @var list<PackConversion> $rows */
        $rows = $this->createQueryBuilder('c')
            ->innerJoin('c.group', 'g')->addSelect('g')
            ->orderBy('c.id', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /** @return list<PackConversion> */
    public function forRule(ProductPackRule $rule): array
    {
        /** @var list<PackConversion> $rows */
        $rows = $this->findBy(['rule' => $rule], ['id' => 'DESC']);

        return $rows;
    }
}
