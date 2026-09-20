<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use WarehouseOpsBundle\Entity\PickList;

/**
 * @extends ServiceEntityRepository<PickList>
 */
class PickListRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PickList::class);
    }

    /**
     * The next `PL-000123`.
     *
     * Derived from MAX(id) rather than COUNT(*) so deleting a list never hands its number to the
     * next one — a reused document number is indistinguishable from the original on a printout.
     * Collisions are still possible under concurrency and are caught by `uniq_pick_list_number`,
     * which is the correct place for that to fail: loudly, before anything is picked.
     */
    public function nextNumber(): string
    {
        $max = (int) $this->createQueryBuilder('p')
            ->select('COALESCE(MAX(p.id), 0)')
            ->getQuery()
            ->getSingleScalarResult();

        return sprintf('PL-%06d', $max + 1);
    }
}
