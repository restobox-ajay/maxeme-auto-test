<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use InventoryDepthBundle\Entity\InventoryMovementGroup;

/**
 * @extends ServiceEntityRepository<InventoryMovementGroup>
 */
class InventoryMovementGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InventoryMovementGroup::class);
    }

    public function findOneByClientOperationId(string $clientOperationId): ?InventoryMovementGroup
    {
        return $this->findOneBy(['clientOperationId' => $clientOperationId]);
    }
}
