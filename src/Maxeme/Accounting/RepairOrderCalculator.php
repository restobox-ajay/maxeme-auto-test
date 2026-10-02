<?php

declare(strict_types=1);

namespace App\Maxeme\Accounting;

use App\Maxeme\Entity\RepairOrder;
use App\Maxeme\Entity\RepairOrderCharge;
use App\Maxeme\Entity\RepairOrderJob;
use App\Maxeme\Entity\RepairOrderJobLine;

/**
 * A repair order's arithmetic, in whole cents:
 *
 *   subtotal     = Σ service prices + Σ their charge-through lines (qty × price per unit)
 *   charge total = Σ custom fees − Σ custom discounts (under the subtotal, above tax)
 *   GST, PST     = their rate (copied onto the repair order) of subtotal + charge total, each apart
 *   total        = subtotal + charge total + GST + PST
 *
 * Lines that are not charged through are advice only and add nothing.
 * public/assets/js/maxeme-repair-order.js does the same live, for display only.
 */
final class RepairOrderCalculator
{
    public function apply(RepairOrder $repairOrder): void
    {
        $subtotal = array_sum(array_map(static fn (RepairOrderJob $job): int => Money::toCents($job->getPrice())
            + array_sum(array_map(static fn (RepairOrderJobLine $line): int => $line->getExtendedCents(), $job->getChargeThroughLines())),
            $repairOrder->getJobs()));
        $charges = array_sum(array_map(static fn (RepairOrderCharge $charge): int => $charge->getSignedCents(), $repairOrder->getCharges()));

        $taxable = $subtotal + $charges;
        $gst = Money::percentOf($taxable, $repairOrder->getGstRate());
        $pst = Money::percentOf($taxable, $repairOrder->getPstRate());

        $repairOrder->setTotals(
            Money::fromCents($subtotal),
            Money::fromCents($charges),
            Money::fromCents($gst),
            Money::fromCents($pst),
            Money::fromCents($taxable + $gst + $pst),
        );
    }
}
