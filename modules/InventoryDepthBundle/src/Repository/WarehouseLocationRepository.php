<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Repository;

use App\Entity\Warehouse;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use InventoryDepthBundle\Entity\WarehouseLocation;

/**
 * @extends ServiceEntityRepository<WarehouseLocation>
 */
class WarehouseLocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WarehouseLocation::class);
    }

    /** In pick-path order, which is what a cycle count and a picker's round both walk. */
    public function findByWarehouseInPickOrder(Warehouse $warehouse): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.warehouse = :warehouse')->setParameter('warehouse', $warehouse)
            ->orderBy('l.sortKey', 'ASC')
            ->addOrderBy('l.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByCode(Warehouse $warehouse, string $code): ?WarehouseLocation
    {
        return $this->findOneBy(['warehouse' => $warehouse, 'code' => $code]);
    }
}
