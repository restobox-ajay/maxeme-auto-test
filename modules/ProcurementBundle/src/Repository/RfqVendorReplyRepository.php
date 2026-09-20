<?php

declare(strict_types=1);

namespace ProcurementBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use ProcurementBundle\Entity\RfqVendorReply;

/**
 * @extends ServiceEntityRepository<RfqVendorReply>
 */
class RfqVendorReplyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RfqVendorReply::class);
    }
}
