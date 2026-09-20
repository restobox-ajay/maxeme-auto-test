<?php

declare(strict_types=1);

namespace ProcurementBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use ProcurementBundle\Entity\UnmatchedVendorSku;
use ProcurementBundle\Entity\Vendor;

/**
 * @extends ServiceEntityRepository<UnmatchedVendorSku>
 */
final class UnmatchedVendorSkuRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UnmatchedVendorSku::class);
    }

    public function findOneForVendorSku(Vendor $vendor, string $vendorSku): ?UnmatchedVendorSku
    {
        return $this->findOneBy(['vendor' => $vendor, 'vendorSku' => $vendorSku]);
    }
}
