<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Issue #313's port of ManualPaymentMethodController::buildFromRequest()'s "name is required"
 * check, following ValidCategory's #308 pattern: a class-level constraint over the entity, applied
 * after name (and slug/source, for a new row) are written onto it — same apply-then-validate order
 * CategoryController already uses.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidPaymentMethod extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
