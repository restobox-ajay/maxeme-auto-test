<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use WarehouseOpsBundle\Entity\TransferOrder;

/**
 * @extends ServiceEntityRepository<TransferOrder>
 */
class TransferOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TransferOrder::class);
    }

    /** The next `TR-000123`. Same MAX(id) reasoning as PickListRepository::nextNumber(). */
    public function nextNumber(): string
    {
        $max = (int) $this->createQueryBuilder('t')
            ->select('COALESCE(MAX(t.id), 0)')
            ->getQuery()
            ->getSingleScalarResult();

        return sprintf('TR-%06d', $max + 1);
    }
}
