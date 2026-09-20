<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Issue #308's port of CategoryController::validateCategory(), following ValidCompany's #309
 * pattern: a class-level constraint since the rule is cross-field-free but still needs the whole
 * entity (name and status), not a single property.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidCategory extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
