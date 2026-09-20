<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Issue #310's port of ConfigController::handleEmailTemplateForm()'s required module/subject/body
 * check. The validated value is an \ArrayObject wrapping the three submitted fields, which the
 * original check requires together behind one shared message.
 */
#[\Attribute]
final class ValidEmailTemplateRequest extends Constraint
{
}
