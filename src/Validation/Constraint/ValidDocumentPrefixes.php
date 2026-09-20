<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Issue #310's port of ConfigController::documentPrefixes()'s regex check on the order/quote
 * prefix pair. The validated value is an \ArrayObject wrapping both submitted prefixes, since the
 * original check validates them together and reports one shared message regardless of which one
 * failed.
 */
#[\Attribute]
final class ValidDocumentPrefixes extends Constraint
{
}
