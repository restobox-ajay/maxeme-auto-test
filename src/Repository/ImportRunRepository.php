<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ImportRun;
use App\Enum\ImportRunStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportRun>
 */
final class ImportRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportRun::class);
    }

    /**
     * The oldest still-queued run — what import:process's drain loop pulls next. Oldest first so
     * the queue drains in submission order.
     */
    public function findOldestQueued(): ?ImportRun
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.status = :status')
            ->setParameter('status', ImportRunStatus::Queued->value)
            ->orderBy('r.queuedAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countQueued(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.status = :status')
            ->setParameter('status', ImportRunStatus::Queued->value)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
