<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Issue #313's port of the negative-rate check BCTaxConfigController and
 * CanadaSimpleTaxConfigController each ran inline over their own $rows loop — a class-level
 * constraint over SalesTax, following ValidCategory's #308 pattern, so both tax bundles' config
 * screens share the one rule instead of two copies of the same `< 0` check.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidSalesTax extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
