<?php

declare(strict_types=1);

namespace WooCommerceBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use WooCommerceBundle\Entity\WooCommerceConnection;
use WooCommerceBundle\Entity\WooCommerceOrderImport;

/**
 * @extends ServiceEntityRepository<WooCommerceOrderImport>
 */
class WooCommerceOrderImportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WooCommerceOrderImport::class);
    }

    /** The idempotency check: has this connection already recorded this Woo order? */
    public function findOneForConnectionAndWooOrderId(WooCommerceConnection $connection, int $wooOrderId): ?WooCommerceOrderImport
    {
        return $this->findOneBy(['connection' => $connection, 'wooOrderId' => $wooOrderId]);
    }

    /**
     * The "All orders" grid's rows: flagged (Error) first, newest first within each group, with
     * the filter bar's own optional narrowing. Default sort is a real business decision, not a
     * cosmetic one — with hundreds of orders a connection can carry, whatever needs a human's
     * attention has to surface without anyone having to think to filter for it first.
     *
     * @param array{connection?: ?WooCommerceConnection, status?: ?string} $filters
     *
     * @return list<WooCommerceOrderImport>
     */
    public function search(array $filters = []): array
    {
        $qb = $this->createQueryBuilder('i');

        if (($filters['connection'] ?? null) instanceof WooCommerceConnection) {
            $qb->andWhere('i.connection = :connection')->setParameter('connection', $filters['connection']);
        }
        if (($filters['status'] ?? '') !== '') {
            $qb->andWhere('i.status = :status')->setParameter('status', $filters['status']);
        }

        return $qb
            ->addOrderBy("CASE WHEN i.status = 'Error' THEN 0 ELSE 1 END", 'ASC')
            ->addOrderBy('i.importedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
