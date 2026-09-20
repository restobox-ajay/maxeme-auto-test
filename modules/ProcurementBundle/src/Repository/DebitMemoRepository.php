<?php

declare(strict_types=1);

namespace ProcurementBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use ProcurementBundle\Entity\DebitMemo;

/**
 * @extends ServiceEntityRepository<DebitMemo>
 */
class DebitMemoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DebitMemo::class);
    }

    /**
     * @param array<string, string> $filters
     *
     * @return array{rows: list<DebitMemo>, total: int}
     */
    public function search(array $filters, int $page, int $limit, string $sort, string $dir): array
    {
        $sortable = ['number' => 'd.documentNumber', 'vendor' => 'ven.name', 'status' => 'd.status', 'id' => 'd.id'];
        $orderBy = $sortable[$sort] ?? 'd.id';

        $qb = $this->createQueryBuilder('d')->join('d.vendor', 'ven');

        if (($filters['q'] ?? '') !== '') {
            $qb->andWhere('d.documentNumber LIKE :q OR ven.name LIKE :q')->setParameter('q', '%' . $filters['q'] . '%');
        }
        if (($filters['status'] ?? '') !== '') {
            $qb->andWhere('d.status = :status')->setParameter('status', $filters['status']);
        }
        // By id rather than by name, because two vendors can be called the same thing and the
        // Vendor column's filter is a <select> of rows, not a typed string. Mirrors the vendor
        // filter on the Vendor Bills grid.
        if (($filters['vendor'] ?? '') !== '') {
            $qb->andWhere('IDENTITY(d.vendor) = :vendor')->setParameter('vendor', (int) $filters['vendor']);
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(d.id)')->getQuery()->getSingleScalarResult();

        /** @var list<DebitMemo> $rows */
        $rows = $qb->orderBy($orderBy, $dir)
            ->addOrderBy('d.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['rows' => $rows, 'total' => $total];
    }
}
