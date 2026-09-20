<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Issue #313's port of the try/catch every fee-bundle config controller (AB Food, BC Food, BC
 * Tire, ON Food, Fuel Surcharges) used to build around Fee::assertValid(), following ValidChargeRows'
 * #317 pattern: a class-level constraint that delegates to the entity's own assertion method rather
 * than re-stating its rules, so a future fix to any of Fee's invariants is inherited by all five
 * config controllers — plus User-Defined Fees and anywhere else a Fee gets validated — without any
 * of them changing.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidFee extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
