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
 *   charge total = Σ custom fees − Σ custom discounts (under the subtotal, above tax); the discounts
 *                  never more than subtotal + fees (mockup 8b30420: the total is never below zero)
 *   GST, PST     = their rate (the repair order's own, editable in its Totals box) of what carries
 *                  that tax: each service and charge-through line by its Tax Class (LineTax), plus the
 *                  fees, less the discounts, never below zero (a GST-only order never gets negative PST)
 *   total        = subtotal + charge total + GST + PST
 *
 * Lines that are not charged through are advice only and add nothing.
 * public/assets/js/maxeme-repair-order.js does the same live, for display only.
 */
final class RepairOrderCalculator
{
    public function __construct(
        private readonly PartTaxes $partTaxes,
    ) {
    }

    /** @return bool false when its discounts are more than subtotal + fees, or its subtotal is below zero (the save is refused) */
    public function apply(RepairOrder $repairOrder): bool
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
                $add($line->getExtendedCents(), LineTax::ofLine($line, $serviceTax, $this->partTaxes));
            }
        }
        $fees = 0;
        $discounts = 0;
        foreach ($repairOrder->getCharges() as $charge) {
            $cents = $charge->getSignedCents();
            $cents >= 0 ? $fees += $cents : $discounts -= $cents;
        }
        $discount = min($discounts, max(0, $subtotal + $fees));
        $charges = $fees - $discount;
        $gst = Money::percentOf(max(0, $gstBase + $charges), $repairOrder->getGstRate());
        $pst = Money::percentOf(max(0, $pstBase + $charges), $repairOrder->getPstRate());
        $repairOrder->setTotals(
            Money::fromCents($subtotal),
            Money::fromCents($charges),
            Money::fromCents($gst),
            Money::fromCents($pst),
            Money::fromCents($subtotal + $charges + $gst + $pst),
        );

        return $discount === $discounts && $subtotal >= 0;
    }

    public static function serviceTax(RepairOrderJob $job): LineTax
    {
        return LineTax::of($job->getService()?->getTaxClass());
    }
}
