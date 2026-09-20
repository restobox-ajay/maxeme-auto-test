<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Issue #309's port of CompanyController::validateCompany(), following the ValidRedirect
 * pattern (#222/#305): a class-level constraint since the rule is cross-field-free but still
 * needs the whole entity (name and primary email), not a single property.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidCompany extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
