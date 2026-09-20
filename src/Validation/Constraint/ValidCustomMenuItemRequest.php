<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Issue #310's port of FrontendMenuManagementController::validateCustomRequest(): required
 * label (+ length cap) and required URL (relative-or-absolute format + length cap). The
 * validated value is an \ArrayObject wrapping the submitted label/url pair.
 */
#[\Attribute]
final class ValidCustomMenuItemRequest extends Constraint
{
}
