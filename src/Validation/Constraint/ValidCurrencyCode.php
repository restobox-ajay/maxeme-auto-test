<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Issue #310's port of ConfigController::baseCurrency()'s inline 3-letter currency-code check.
 * The validated value is the raw (already uppercased/trimmed) submitted currency string, since
 * there is no entity — Base Currency is a bare AppSetting scalar.
 */
#[\Attribute]
final class ValidCurrencyCode extends Constraint
{
}
