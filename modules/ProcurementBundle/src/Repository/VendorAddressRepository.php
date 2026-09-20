<?php

declare(strict_types=1);

namespace ProcurementBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use ProcurementBundle\Entity\VendorAddress;

/**
 * @extends ServiceEntityRepository<VendorAddress>
 */
class VendorAddressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VendorAddress::class);
    }
}
