<?php

declare(strict_types=1);

namespace ProcurementBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Enum\PurchaseOrderStatus;

/**
 * @extends ServiceEntityRepository<PurchaseOrder>
 */
class PurchaseOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseOrder::class);
    }

    /**
     * @param array{q?:string,status?:string,vendor?:string,warehouse?:string,from?:string,to?:string,product?:string} $filters
     *
     * @return array{rows: list<PurchaseOrder>, total: int}
     */
    public function search(array $filters, int $page, int $limit, string $sort, string $dir): array
    {
        $sortable = [
            'number' => 'p.poNumber',
            'vendor' => 'p.vendorName',
            'status' => 'p.status',
            'date' => 'p.documentDate',
            'expected' => 'p.expectedDate',
            'total' => 'p.total',
            'id' => 'p.id',
        ];
        $orderBy = $sortable[$sort] ?? 'p.poNumber';

        $qb = $this->createQueryBuilder('p');

        if (($filters['q'] ?? '') !== '') {
            $qb->andWhere('p.poNumber LIKE :q OR p.vendorName LIKE :q')->setParameter('q', '%' . $filters['q'] . '%');
        }
        if (($filters['status'] ?? '') !== '') {
            $qb->andWhere('p.status = :status')->setParameter('status', $filters['status']);
        }
        if (($filters['vendor'] ?? '') !== '') {
            $qb->andWhere('IDENTITY(p.vendor) = :vendor')->setParameter('vendor', (int) $filters['vendor']);
        }
        if (($filters['warehouse'] ?? '') !== '') {
            $qb->andWhere('IDENTITY(p.warehouse) = :warehouse')->setParameter('warehouse', (int) $filters['warehouse']);
        }
        if (($filters['from'] ?? '') !== '') {
            $qb->andWhere('p.documentDate >= :from')->setParameter('from', $filters['from']);
        }
        if (($filters['to'] ?? '') !== '') {
            $qb->andWhere('p.documentDate <= :to')->setParameter('to', $filters['to']);
        }

        // Product Inventory Hub's "Recent Activity" View more link (build order step 1) — no
        // product filter existed on this screen at all before this, so there is no existing text
        // search to preserve; go straight to an exact id match.
        $usesLineJoin = false;
        if (($filters['product'] ?? '') !== '') {
            $qb->innerJoin('p.lines', 'pl');
            $usesLineJoin = true;
            $qb->andWhere('IDENTITY(pl.product) = :product')->setParameter('product', (int) $filters['product']);
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select($usesLineJoin ? 'COUNT(DISTINCT p.id)' : 'COUNT(p.id)')->getQuery()->getSingleScalarResult();

        if ($usesLineJoin) {
            $qb->distinct(true);
        }

        /** @var list<PurchaseOrder> $rows */
        $rows = $qb->orderBy($orderBy, $dir)
            ->addOrderBy('p.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * What is due in, by date, optionally per warehouse — the expected-arrivals screen.
     *
     * Only Issued and Partially Received: a draft has not been sent to anybody, and a Received,
     * Closed or Cancelled order is not expected. An order with no expected date sorts last rather
     * than being dropped, because "we do not know when this is coming" is exactly the row a buyer
     * needs to see.
     *
     * @return list<PurchaseOrder>
     */
    public function expectedArrivals(?int $warehouseId, ?string $through): array
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.status IN (:open)')
            ->setParameter('open', [PurchaseOrderStatus::Issued, PurchaseOrderStatus::PartiallyReceived])
            ->orderBy('CASE WHEN p.expectedDate IS NULL THEN 1 ELSE 0 END', 'ASC')
            ->addOrderBy('p.expectedDate', 'ASC')
            ->addOrderBy('p.poNumber', 'ASC');

        if ($warehouseId !== null && $warehouseId > 0) {
            $qb->andWhere('IDENTITY(p.warehouse) = :warehouse')->setParameter('warehouse', $warehouseId);
        }
        if ($through !== null && $through !== '') {
            $qb->andWhere('p.expectedDate IS NULL OR p.expectedDate <= :through')->setParameter('through', $through);
        }

        /** @var list<PurchaseOrder> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /**
     * The orders a vendor's bill may be entered against, for the bill form's picker.
     *
     * A WIDER set than openForReceiving(): a Closed order is still billable. Closing short says the
     * goods stopped coming, never that the paperwork did — the vendor's invoice for what did arrive
     * routinely lands after the order is closed, and a bill that cannot name it matches against
     * nothing and loses the three-way match for no reason. Draft is out because a draft order has
     * not been placed with anybody, and Cancelled is out because nothing was bought.
     *
     * @return list<PurchaseOrder>
     */
    public function billableFor(int $vendorId): array
    {
        if ($vendorId <= 0) {
            return [];
        }

        /** @var list<PurchaseOrder> $rows */
        $rows = $this->createQueryBuilder('p')
            ->andWhere('IDENTITY(p.vendor) = :vendor')
            ->andWhere('p.status IN (:billable)')
            ->setParameter('vendor', $vendorId)
            ->setParameter('billable', [
                PurchaseOrderStatus::Issued,
                PurchaseOrderStatus::PartiallyReceived,
                PurchaseOrderStatus::Received,
                PurchaseOrderStatus::Closed,
            ])
            ->orderBy('p.poNumber', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Orders that goods can still be booked in against, for the receiving screen's picker.
     *
     * @return list<PurchaseOrder>
     */
    public function openForReceiving(?int $vendorId = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.status IN (:open)')
            ->setParameter('open', [
                PurchaseOrderStatus::Issued,
                PurchaseOrderStatus::PartiallyReceived,
                PurchaseOrderStatus::Received,
            ])
            ->orderBy('p.poNumber', 'ASC');

        if ($vendorId !== null && $vendorId > 0) {
            $qb->andWhere('IDENTITY(p.vendor) = :vendor')->setParameter('vendor', $vendorId);
        }

        /** @var list<PurchaseOrder> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }
}
