<?php

declare(strict_types=1);

namespace App\Maxeme\Accounting;

use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Entity\RepairOrderCharge;
use App\Maxeme\Enum\RepairOrderStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Accounting › Service Report (mockup 8b30420): only work that was done, the repair orders made in the
 * period that are Completed, Picked Up or Invoiced (estimates, work in progress and cancelled orders
 * are not revenue). One row per service, then the repair order's fees and discounts as rows of their
 * own (a Fees column beside Discounts), each split into Labour, Parts, Fees, Discounts and Govt Fees
 * (RevenueBreakdown) with its Subtotal. When the repair order's own total differs from what its rows
 * add up to, the difference is an Adjustment row. The repair order's GST and PST are shared out over
 * its rows by each row's taxable base, with a running rounding, so the rows add up to the cent.
 */
final class ServiceReport
{
    /** The amount columns, in order: row key => heading (the page and the CSV share them). */
    public const AMOUNTS = [
        'labour' => 'Labour $',
        'parts' => 'Parts $',
        'fees' => 'Fees $',
        'discounts' => 'Discounts $',
        'govtFees' => 'Govt Fees $',
        'subtotal' => 'Subtotal $',
        'taxes' => 'Taxes $',
        'total' => 'Grand Total $',
    ];

    private const DONE = [RepairOrderStatus::Completed, RepairOrderStatus::CompletedPickedUp, RepairOrderStatus::Invoiced];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PartTaxes $partTaxes,
    ) {
    }

    /**
     * @return array{rows: list<array{repairOrder: RepairOrder, name: string, amounts: array<string, string>}>, totals: array<string, string>}
     */
    public function run(ReportPeriod $period): array
    {
        /** @var list<RepairOrder> $repairOrders */
        $repairOrders = $this->entityManager->createQueryBuilder()
            ->select('r', 'c', 'j', 'l')
            ->from(RepairOrder::class, 'r')
            ->leftJoin('r.client', 'c')
            ->leftJoin('r.jobs', 'j')
            ->leftJoin('j.lines', 'l')
            ->andWhere('r.createdOn >= :from AND r.createdOn <= :to')
            ->andWhere('r.status IN (:done)')
            ->setParameter('from', $period->startUtc())
            ->setParameter('to', $period->endUtc())
            ->setParameter('done', self::DONE)
            ->orderBy('r.createdOn', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();

        $rows = [];
        $totals = array_fill_keys(array_keys(self::AMOUNTS), 0);
        foreach ($repairOrders as $repairOrder) {
            foreach ($this->rowsOf($repairOrder) as $row) {
                foreach ($totals as $key => $sum) {
                    $totals[$key] = $sum + $row['cents'][$key];
                }
                $rows[] = ['repairOrder' => $repairOrder, 'name' => $row['name'], 'amounts' => array_map(Money::fromCents(...), $row['cents'])];
            }
        }

        return ['rows' => $rows, 'totals' => array_map(Money::fromCents(...), $totals)];
    }

    /** When the newest finished repair order was made (for the default period: never an empty page). */
    public function latestFinished(): ?\DateTimeImmutable
    {
        $latest = $this->entityManager->createQueryBuilder()
            ->select('MAX(r.createdOn)')->from(RepairOrder::class, 'r')
            ->andWhere('r.status IN (:done)')->setParameter('done', self::DONE)
            ->getQuery()->getSingleScalarResult();

        return $latest !== null ? new \DateTimeImmutable((string) $latest, new \DateTimeZone('UTC')) : null;
    }

    /** @return list<array{name: string, cents: array<string, int>}> */
    private function rowsOf(RepairOrder $repairOrder): array
    {
        $zero = array_fill_keys(array_keys(self::AMOUNTS), 0);
        $rows = [];
        foreach ($repairOrder->getJobs() as $job) {
            $split = RevenueBreakdown::ofJob($job);
            $serviceTax = RepairOrderCalculator::serviceTax($job);
            $gst = $serviceTax->gst ? Money::toCents($job->getPrice()) : 0;
            $pst = $serviceTax->pst ? Money::toCents($job->getPrice()) : 0;
            foreach ($job->getChargeThroughLines() as $line) {
                $tax = LineTax::ofLine($line, $serviceTax, $this->partTaxes);
                $gst += $tax->gst ? $line->getExtendedCents() : 0;
                $pst += $tax->pst ? $line->getExtendedCents() : 0;
            }
            $rows[] = ['name' => $job->getName() ?: '—', 'cents' => array_merge($zero, $split, ['fees' => 0]), 'gst' => $gst, 'pst' => $pst];
        }
        foreach ($repairOrder->getCharges() as $charge) {
            $cents = $charge->getSignedCents();
            $discount = $cents < 0;
            $rows[] = [
                'name' => sprintf('%s: %s', $discount ? 'Discount' : 'Fee', $charge->getLabel() ?: ($discount ? 'Discount' : 'Fee')),
                'cents' => array_merge($zero, [$discount ? 'discounts' : 'fees' => $cents, 'subtotal' => $cents]),
                'gst' => $cents,
                'pst' => $cents,
            ];
        }
        // The rows add up to the repair order: a total that differs (an old export, a total kept from before) is a row of its own.
        $taxable = Money::toCents($repairOrder->getSubtotal()) + Money::toCents($repairOrder->getChargeTotal());
        $gap = $taxable - array_sum(array_map(static fn (array $row): int => $row['cents']['subtotal'], $rows));
        if ($gap !== 0) {
            $rows[] = ['name' => 'Adjustment: the repair order total differs from its lines', 'cents' => array_merge($zero, ['labour' => $gap, 'subtotal' => $gap]), 'gst' => $gap, 'pst' => $gap];
        }

        // Its own GST and PST shared out by each row's base, with a running rounding.
        $gstBase = array_sum(array_column($rows, 'gst'));
        $pstBase = array_sum(array_column($rows, 'pst'));
        $gstTotal = Money::toCents($repairOrder->getGst());
        $pstTotal = Money::toCents($repairOrder->getPst());
        $runningGst = $runningPst = $sharedGst = $sharedPst = 0;
        $last = count($rows) - 1;
        foreach ($rows as $n => &$row) {
            $runningGst += $row['gst'];
            $runningPst += $row['pst'];
            $nextGst = $n === $last ? $gstTotal : ($gstBase !== 0 ? (int) round($gstTotal * $runningGst / $gstBase) : 0);
            $nextPst = $n === $last ? $pstTotal : ($pstBase !== 0 ? (int) round($pstTotal * $runningPst / $pstBase) : 0);
            $row['cents']['taxes'] = ($nextGst - $sharedGst) + ($nextPst - $sharedPst);
            $row['cents']['total'] = $row['cents']['subtotal'] + $row['cents']['taxes'];
            $sharedGst = $nextGst;
            $sharedPst = $nextPst;
        }
        unset($row);

        return array_map(static fn (array $row): array => ['name' => $row['name'], 'cents' => $row['cents']], $rows);
    }
}
