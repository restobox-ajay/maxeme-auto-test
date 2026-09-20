<?php

declare(strict_types=1);

namespace ProcurementBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use ProcurementBundle\Entity\GoodsReceipt;
use ProcurementBundle\Entity\ShortDatedReceipt;

/**
 * @extends ServiceEntityRepository<GoodsReceipt>
 */
class GoodsReceiptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GoodsReceipt::class);
    }

    /**
     * @param array<string, string> $filters
     *
     * @return array{rows: list<GoodsReceipt>, total: int}
     */
    public function search(array $filters, int $page, int $limit, string $sort, string $dir): array
    {
        $sortable = [
            'number' => 'r.receiptNumber',
            'vendor' => 'r.vendorName',
            'received' => 'r.receivedAt',
            'id' => 'r.id',
        ];
        $orderBy = $sortable[$sort] ?? 'r.receivedAt';

        $qb = $this->createQueryBuilder('r');

        if (($filters['q'] ?? '') !== '') {
            $qb->andWhere('r.receiptNumber LIKE :q OR r.vendorName LIKE :q OR r.packingSlip LIKE :q')
                ->setParameter('q', '%' . $filters['q'] . '%');
        }
        if (($filters['vendor'] ?? '') !== '') {
            $qb->andWhere('IDENTITY(r.vendor) = :vendor')->setParameter('vendor', (int) $filters['vendor']);
        }
        if (($filters['warehouse'] ?? '') !== '') {
            $qb->andWhere('IDENTITY(r.warehouse) = :warehouse')->setParameter('warehouse', (int) $filters['warehouse']);
        }
        // "Goods nobody raised a PO for" — legitimate, and exactly the list a buyer wants to work.
        if (($filters['unordered'] ?? '') === '1') {
            $qb->andWhere('r.purchaseOrder IS NULL');
        }
        // Receipts somebody accepted short-dated (item 68). An EXISTS over the exception rows
        // rather than a join: one receipt can carry several, and a join would return it once per
        // override and make the count in the footer disagree with the rows on the screen.
        if (($filters['short_dated'] ?? '') === '1') {
            $qb->andWhere(
                'EXISTS (SELECT 1 FROM ' . ShortDatedReceipt::class . ' sd JOIN sd.receiptLine sdl WHERE sdl.receipt = r)',
            );
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();

        /** @var list<GoodsReceipt> $rows */
        $rows = $qb->orderBy($orderBy, $dir)
            ->addOrderBy('r.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['rows' => $rows, 'total' => $total];
    }
}
