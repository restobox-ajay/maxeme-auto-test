<?php

declare(strict_types=1);

namespace App\Maxeme\Accounting;

use App\Maxeme\Entity\TaxRate;
use App\Maxeme\Repository\TaxRateRepository;

/**
 * The invoice tax rates, in percent, from Config › Settings › Tax Rates: each tax is either charged
 * at its rate or not at all.
 */
final class InvoiceSettings
{
    public function __construct(
        private readonly TaxRateRepository $taxRates,
    ) {
    }

    public function gstRate(): int
    {
        return $this->taxRates->rateOf(TaxRate::GST);
    }

    public function pstRate(): int
    {
        return $this->taxRates->rateOf(TaxRate::PST);
    }

    /** 0 when $rate is 0 (the builder's GST switched off); otherwise the GST rate. */
    public function gst(int $rate): int
    {
        return $rate === 0 ? 0 : $this->gstRate();
    }

    public function pst(int $rate): int
    {
        return $rate === 0 ? 0 : $this->pstRate();
    }
}
