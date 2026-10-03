<?php

declare(strict_types=1);

namespace App\Maxeme\Accounting;

use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\RepairOrderJob;
use App\Maxeme\Enum\ServiceLineType;

/**
 * What a service, or an invoice, was billed for, split as the reports show it: Parts, Labour,
 * Discounts and Govt Fees, adding up to the subtotal (before tax).
 *
 * A service is billed its price plus its charge-through lines. Its part, government fee and
 * discount lines say how much of that is parts, fees and discount; Labour is the rest (labour and
 * sublet work, and whatever the price holds beyond its lines). An invoice is split as its repair
 * order's services are, its own discounts added, and Labour is again the rest of its subtotal, so
 * the columns always add up to what was billed. All in cents.
 */
final class RevenueBreakdown
{
    /** @return array{parts: int, labour: int, discounts: int, govtFees: int, subtotal: int} */
    public static function ofJob(RepairOrderJob $job): array
    {
        $billed = Money::toCents($job->getPrice());
        $parts = $fees = $discounts = 0;
        foreach ($job->getLines() as $line) {
            $amount = $line->getExtendedCents();
            if ($line->isChargeThrough()) {
                $billed += $amount;
            }
            match ($line->getType()) {
                ServiceLineType::Part => $parts += $amount,
                ServiceLineType::GovtFee => $fees += $amount,
                ServiceLineType::Discount => $discounts += $amount,
                default => null,
            };
        }

        return ['parts' => $parts, 'labour' => $billed - $parts - $fees - $discounts, 'discounts' => $discounts, 'govtFees' => $fees, 'subtotal' => $billed];
    }

    /** @return array{parts: int, labour: int, discounts: int, govtFees: int, subtotal: int, taxes: int, total: int} */
    public static function ofInvoice(Invoice $invoice): array
    {
        $parts = $fees = 0;
        $discounts = Money::toCents($invoice->getDiscountTotal());
        foreach ($invoice->getRepairOrder()?->getJobs() ?? [] as $job) {
            $split = self::ofJob($job);
            $parts += $split['parts'];
            $fees += $split['govtFees'];
            $discounts += $split['discounts'];
        }
        $subtotal = Money::toCents($invoice->getSubtotal());

        return [
            'parts' => $parts,
            'labour' => $subtotal - $parts - $fees - $discounts,
            'discounts' => $discounts,
            'govtFees' => $fees,
            'subtotal' => $subtotal,
            'taxes' => Money::toCents($invoice->getSalesTax()),
            'total' => Money::toCents($invoice->getTotalPrice()),
        ];
    }
}
