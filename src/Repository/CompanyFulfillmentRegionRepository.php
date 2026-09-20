<?php

namespace App\Repository;

use App\Entity\CompanyFulfillmentRegion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompanyFulfillmentRegion>
 */
class CompanyFulfillmentRegionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompanyFulfillmentRegion::class);
    }
}
