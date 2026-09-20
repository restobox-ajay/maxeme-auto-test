<?php

declare(strict_types=1);

namespace ProcurementBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use ProcurementBundle\Entity\Rfq;

/**
 * @extends ServiceEntityRepository<Rfq>
 */
class RfqRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Rfq::class);
    }

    /**
     * @param array<string, string> $filters
     *
     * @return array{rows: list<Rfq>, total: int}
     */
    public function search(array $filters, int $page, int $limit, string $sort, string $dir): array
    {
        $sortable = ['number' => 'r.documentNumber', 'status' => 'r.status', 'id' => 'r.id'];
        $orderBy = $sortable[$sort] ?? 'r.id';

        $qb = $this->createQueryBuilder('r');

        if (($filters['q'] ?? '') !== '') {
            $qb->andWhere('r.documentNumber LIKE :q')->setParameter('q', '%' . $filters['q'] . '%');
        }
        if (($filters['status'] ?? '') !== '') {
            $qb->andWhere('r.status = :status')->setParameter('status', $filters['status']);
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();

        /** @var list<Rfq> $rows */
        $rows = $qb->orderBy($orderBy, $dir)
            ->addOrderBy('r.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['rows' => $rows, 'total' => $total];
    }
}
