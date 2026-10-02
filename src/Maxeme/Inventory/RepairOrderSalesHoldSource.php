<?php

declare(strict_types=1);

namespace App\Maxeme\Inventory;

use App\Entity\OrderInventoryReservation;
use App\Maxeme\Entity\RepairOrderInventoryReservation;
use App\Service\Inventory\SalesHoldSource;
use Doctrine\ORM\EntityManagerInterface;

/** The approved repair orders' parts, for app:inventory-recalc's sales hold (see SalesHoldSource). */
final class RepairOrderSalesHoldSource implements SalesHoldSource
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function salesHoldSums(): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('IDENTITY(r.product) AS product', 'IDENTITY(r.warehouse) AS warehouse', 'SUM(r.quantity) AS quantity')
            ->from(RepairOrderInventoryReservation::class, 'r')
            ->andWhere('r.bucket = :bucket')->setParameter('bucket', OrderInventoryReservation::BUCKET_SALES_HOLD)
            ->groupBy('r.product', 'r.warehouse')
            ->getQuery()
            ->getArrayResult();

        $sums = [];
        foreach ($rows as $row) {
            $sums[$row['product'] . '|' . $row['warehouse']] = (string) $row['quantity'];
        }

        return $sums;
    }
}
