<?php

declare(strict_types=1);

namespace App\Maxeme\Accounting;

use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Entity\RepairOrderCharge;
use App\Maxeme\Entity\RepairOrderJob;

/**
 * A repair order's arithmetic, in whole cents:
 *
 *   subtotal     = Σ service prices + Σ their charge-through lines (qty × price per unit)
 *   charge total = Σ custom fees − Σ custom discounts (under the subtotal, above tax)
 *   GST, PST     = their rate (copied onto the repair order) of what carries that tax: each service
 *                  and charge-through line by its Tax Class (LineTax), plus the charge total
 *   total        = subtotal + charge total + GST + PST
 *
 * Lines that are not charged through are advice only and add nothing.
 * public/assets/js/maxeme-repair-order.js does the same live, for display only.
 */
final class RepairOrderCalculator
{
    public function apply(RepairOrder $repairOrder): void
    {
        $subtotal = 0;
        $gstBase = 0;
        $pstBase = 0;
        $add = static function (int $cents, LineTax $tax) use (&$subtotal, &$gstBase, &$pstBase): void {
            $subtotal += $cents;
            $gstBase += $tax->gst ? $cents : 0;
            $pstBase += $tax->pst ? $cents : 0;
        };
        foreach ($repairOrder->getJobs() as $job) {
            $serviceTax = self::serviceTax($job);
            $add(Money::toCents($job->getPrice()), $serviceTax);
            foreach ($job->getChargeThroughLines() as $line) {
                $add($line->getExtendedCents(), LineTax::ofLine($line, $serviceTax));
            }
        }
        $charges = array_sum(array_map(static fn (RepairOrderCharge $charge): int => $charge->getSignedCents(), $repairOrder->getCharges()));
        $gst = Money::percentOf($gstBase + $charges, $repairOrder->getGstRate());
        $pst = Money::percentOf($pstBase + $charges, $repairOrder->getPstRate());
        $repairOrder->setTotals(
            Money::fromCents($subtotal),
            Money::fromCents($charges),
            Money::fromCents($gst),
            Money::fromCents($pst),
            Money::fromCents($subtotal + $charges + $gst + $pst),
        );
    }

    public static function serviceTax(RepairOrderJob $job): LineTax
    {
        return LineTax::of($job->getService()?->getTaxClass());
    }
}
