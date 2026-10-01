<?php

declare(strict_types=1);

namespace App\Maxeme\Fee;

use App\Maxeme\Entity\Invoice;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Code that adds government fees by itself, e.g. "every tire on the invoice carries the tire
 * levy". Implement this anywhere under src/ and it is picked up (autoconfigured); return the
 * codes of the fees (Parts & Services › Government Fees) the invoice should carry. A code with no
 * active fee is skipped, so a fee can be switched off without touching the rule.
 */
#[AutoconfigureTag(self::TAG)]
interface GovtFeeRule
{
    public const TAG = 'maxeme.govt_fee_rule';

    /** @return list<string> fee codes */
    public function feeCodesFor(Invoice $invoice): array;
}
