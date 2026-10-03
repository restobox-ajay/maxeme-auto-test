<?php

declare(strict_types=1);

namespace App\Maxeme\Accounting;

use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Entity\RepairOrderJob;
use App\Maxeme\Enum\RepairOrderStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Accounting › Service Report: one row per service of every repair order made in the period
 * (cancelled ones left out), a repair order with three services giving three rows: its date and
 * number, the service, the customer, and the service split into Labour, Parts, Discounts and Govt
 * Fees (RevenueBreakdown), with its Subtotal, Taxes (the repair order's rates) and Grand Total.
 */
final class ServiceReport
{
    /** The amount columns, in order: row key => heading (the page and the CSV share them). */
    public const AMOUNTS = [
        'labour' => 'Labour $',
        'parts' => 'Parts $',
        'discounts' => 'Discounts $',
        'govtFees' => 'Govt Fees $',
        'subtotal' => 'Subtotal $',
        'taxes' => 'Taxes $',
        'total' => 'Grand Total $',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{rows: list<array{repairOrder: RepairOrder, job: RepairOrderJob, amounts: array<string, string>}>, totals: array<string, string>}
     */
    public function run(ReportPeriod $period): array
    {
        /** @var list<RepairOrder> $repairOrders */
        $repairOrders = $this->entityManager->createQueryBuilder()
            ->select('r', 'c', 'j', 'l')
            ->from(RepairOrder::class, 'r')
            ->leftJoin('r.client', 'c')
            ->join('r.jobs', 'j')
            ->leftJoin('j.lines', 'l')
            ->andWhere('r.createdOn >= :from AND r.createdOn <= :to')
            ->andWhere('r.status <> :cancelled')
            ->setParameter('from', $period->startUtc())
            ->setParameter('to', $period->endUtc())
            ->setParameter('cancelled', RepairOrderStatus::Cancelled)
            ->orderBy('r.createdOn', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();

        $rows = [];
        $totals = array_fill_keys(array_keys(self::AMOUNTS), 0);
        foreach ($repairOrders as $repairOrder) {
            foreach ($repairOrder->getJobs() as $job) {
                $split = RevenueBreakdown::ofJob($job);
                $split['taxes'] = Money::percentOf($split['subtotal'], $repairOrder->getGstRate()) + Money::percentOf($split['subtotal'], $repairOrder->getPstRate());
                $split['total'] = $split['subtotal'] + $split['taxes'];
                foreach ($totals as $key => $sum) {
                    $totals[$key] = $sum + $split[$key];
                }
                $rows[] = ['repairOrder' => $repairOrder, 'job' => $job, 'amounts' => array_map(Money::fromCents(...), $split)];
            }
        }

        return ['rows' => $rows, 'totals' => array_map(Money::fromCents(...), $totals)];
    }
}
