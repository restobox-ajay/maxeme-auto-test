<?php

declare(strict_types=1);

namespace ProcurementBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use ProcurementBundle\Entity\VendorNote;

/**
 * @extends ServiceEntityRepository<VendorNote>
 */
class VendorNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VendorNote::class);
    }

    /**
     * Newest first, which is the order the vendor screen reads them in.
     *
     * @return list<VendorNote>
     */
    public function forVendor(int $vendorId): array
    {
        /** @var list<VendorNote> $rows */
        $rows = $this->createQueryBuilder('n')
            ->andWhere('n.vendor = :vendor')->setParameter('vendor', $vendorId)
            ->orderBy('n.createdAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
