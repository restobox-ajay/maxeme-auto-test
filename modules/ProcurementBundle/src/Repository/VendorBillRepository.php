<?php

declare(strict_types=1);

namespace ProcurementBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Enum\VendorBillStatus;

/**
 * @extends ServiceEntityRepository<VendorBill>
 */
class VendorBillRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VendorBill::class);
    }

    /**
     * Bills already on file carrying this vendor invoice number — the duplicate-payment guard.
     *
     * Paying the same invoice twice is the single most expensive clerical error in AP, and it
     * happens because the same PDF arrives twice by email. This backs a **warning**, never a block:
     * vendors do reuse and recycle their own numbers, and a hard block would eventually force
     * somebody to enter a real bill under a made-up number.
     *
     * Void bills are included deliberately. "You already entered this one and voided it" is
     * information the person about to enter it again needs — it is often exactly why they are
     * entering it again, and hiding it invites the third attempt.
     *
     * `$excludeId` keeps a bill from reporting itself when the check runs on an edit.
     *
     * @return list<VendorBill>
     */
    public function findByVendorInvoiceNumber(Vendor $vendor, string $vendorInvoiceNo, ?int $excludeId = null): array
    {
        $vendorInvoiceNo = trim($vendorInvoiceNo);
        if ($vendorInvoiceNo === '') {
            return [];
        }

        $qb = $this->createQueryBuilder('b')
            ->andWhere('b.vendor = :vendor')->setParameter('vendor', $vendor)
            // Case-insensitively, because "INV-1001" and "inv-1001" off two copies of the same PDF
            // are the same invoice and a case-sensitive guard would wave the second one through.
            ->andWhere('LOWER(b.vendorInvoiceNo) = :number')->setParameter('number', mb_strtolower($vendorInvoiceNo))
            ->orderBy('b.id', 'ASC');

        if ($excludeId !== null && $excludeId > 0) {
            $qb->andWhere('b.id <> :self')->setParameter('self', $excludeId);
        }

        /** @var list<VendorBill> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    /**
     * @param array<string, string> $filters
     *
     * @return array{rows: list<VendorBill>, total: int}
     */
    public function search(array $filters, int $page, int $limit, string $sort, string $dir): array
    {
        $sortable = [
            'number' => 'b.billNumber',
            'vendor' => 'b.vendorName',
            'status' => 'b.status',
            'date' => 'b.documentDate',
            'due' => 'b.dueDate',
            'total' => 'b.total',
            'id' => 'b.id',
        ];
        $orderBy = $sortable[$sort] ?? 'b.documentDate';

        $qb = $this->createQueryBuilder('b');

        if (($filters['q'] ?? '') !== '') {
            $qb->andWhere('b.billNumber LIKE :q OR b.vendorInvoiceNo LIKE :q OR b.vendorName LIKE :q')
                ->setParameter('q', '%' . $filters['q'] . '%');
        }
        if (($filters['status'] ?? '') !== '') {
            $qb->andWhere('b.status = :status')->setParameter('status', $filters['status']);
        }
        if (($filters['vendor'] ?? '') !== '') {
            $qb->andWhere('IDENTITY(b.vendor) = :vendor')->setParameter('vendor', (int) $filters['vendor']);
        }
        // A date range over the bill's OWN date, which is the column the grid sorts on and the one
        // a vendor statement is reconciled against. The invoice grid has carried
        // invoiceDateFrom/invoiceDateTo since #539 and the bills grid had neither, so a month's
        // payables could only be found by paging. Both ends are inclusive, and `documentDate` is a
        // 'Y-m-d' STRING column on purpose (AbstractPurchaseDocument says why), so a lexicographic
        // comparison is exactly a calendar one.
        if (($filters['dateFrom'] ?? '') !== '') {
            $qb->andWhere('b.documentDate >= :dateFrom')->setParameter('dateFrom', $filters['dateFrom']);
        }
        if (($filters['dateTo'] ?? '') !== '') {
            $qb->andWhere('b.documentDate <= :dateTo')->setParameter('dateTo', $filters['dateTo']);
        }
        if (($filters['payable'] ?? '') === '1') {
            $qb->andWhere('b.status IN (:payable)')->setParameter('payable', [
                VendorBillStatus::Open,
                VendorBillStatus::PartiallyPaid,
                VendorBillStatus::Disputed,
            ]);
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(b.id)')->getQuery()->getSingleScalarResult();

        /** @var list<VendorBill> $rows */
        $rows = $qb->orderBy($orderBy, $dir)
            ->addOrderBy('b.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['rows' => $rows, 'total' => $total];
    }
}
