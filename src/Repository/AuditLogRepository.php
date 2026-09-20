<?php

namespace App\Repository;

use App\Entity\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
final class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /** @return AuditLog[] */
    public function findForEntity(string $entityType, int $entityId): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.entityType = :entityType')
            ->andWhere('a.entityId = :entityId')
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->orderBy('a.occurredAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The narrative half only — what a document's own named actions wrote via
     * `AbstractSalesDocument::queueActivityLogEntry()` / the buy-side equivalent, not the generic
     * field-diff rows every entity (including this one) also gets from AuditLogSubscriber's own
     * automatic auditing. actorType = 'document' is the marker AuditLogger::flushQueued() stamps
     * on exactly these rows and no others — see PendingActivityLogEntry's own docblock for why
     * this distinction exists (it is what used to be SalesOrderLog / InvoiceLog / EstimateLog /
     * PurchaseOrderLog / VendorBillLog, one table each, before they were absorbed into this one).
     *
     * @return AuditLog[]
     */
    public function findNarrativeForEntity(string $entityType, int $entityId): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.entityType = :entityType')
            ->andWhere('a.entityId = :entityId')
            ->andWhere("a.actorType = 'document'")
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->orderBy('a.occurredAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
