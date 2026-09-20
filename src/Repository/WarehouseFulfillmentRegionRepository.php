<?php

namespace App\Repository;

use App\Entity\WarehouseFulfillmentRegion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WarehouseFulfillmentRegion>
 */
class WarehouseFulfillmentRegionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WarehouseFulfillmentRegion::class);
    }
}
