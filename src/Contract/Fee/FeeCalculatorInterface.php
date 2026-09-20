<?php

declare(strict_types=1);

namespace App\Contract\Fee;

interface FeeCalculatorInterface
{
    public function supports(FeeContext $context): bool;

    /** @return FeeLine[] */
    public function calculate(FeeContext $context): array;
}
