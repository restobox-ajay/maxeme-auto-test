<?php

declare(strict_types=1);

namespace App\Contract\Tax;

interface TaxCalculatorInterface
{
    public function supports(TaxContext $context): bool;

    /** @return TaxLine[] */
    public function calculate(TaxContext $context): array;
}
