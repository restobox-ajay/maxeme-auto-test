<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The figures behind a credit-limit overage (#724) — the credit-side counterpart to
 * {@see \App\Service\Inventory\StockShortfall}, one document wide instead of one line wide, since
 * credit exposure is a whole-document question and stock is a per-(product, warehouse) one.
 *
 * Built only by {@see CompanyCreditExposureCalculator::overageFor()}, and only when there is
 * something to say: a limit is set and the projected exposure exceeds it.
 */
final class CreditExposure
{
    public function __construct(
        public readonly string $limit,
        public readonly string $currentExposure,
        public readonly string $additionalAmount,
        public readonly string $projectedExposure,
    ) {
    }

    /** How far past the limit the projected exposure sits. Always positive — this object never exists otherwise. */
    public function over(): string
    {
        return number_format((float) $this->projectedExposure - (float) $this->limit, 2, '.', '');
    }

    /** @param string $companyName the sentence names whose balance this is; the object itself does not carry it */
    public function describe(string $companyName): string
    {
        return sprintf(
            'This order would bring %s\'s outstanding balance to $%s, which is $%s over its $%s credit limit.',
            $companyName,
            $this->projectedExposure,
            $this->over(),
            $this->limit,
        );
    }
}
