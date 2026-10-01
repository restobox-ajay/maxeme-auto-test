<?php

declare(strict_types=1);

namespace App\Maxeme\Fee;

use App\Maxeme\Entity\GovtFee;
use App\Maxeme\Entity\Invoice;
use App\Maxeme\Repository\GovtFeeRepository;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Every GovtFeeRule together: the active government fees an invoice should carry automatically. */
final class GovtFeeRules
{
    /** @param iterable<GovtFeeRule> $rules */
    public function __construct(
        #[AutowireIterator(GovtFeeRule::TAG)]
        private readonly iterable $rules,
        private readonly GovtFeeRepository $fees,
    ) {
    }

    /** @return list<GovtFee> each fee once, whichever rules asked for it */
    public function feesFor(Invoice $invoice): array
    {
        $codes = [];
        foreach ($this->rules as $rule) {
            array_push($codes, ...$rule->feeCodesFor($invoice));
        }

        return $this->fees->findActiveByCodes($codes);
    }
}
