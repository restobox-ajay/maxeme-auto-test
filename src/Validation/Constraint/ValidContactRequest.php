<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Issue #312's port of Customer\ContactController::index()'s hand-built $errors map: required
 * name/email/location/subject/comments, email format, and a comments min-length. No DB or other
 * dependency, so this needs no constructor options, the same as ValidOrderLines (#306).
 */
#[\Attribute]
final class ValidContactRequest extends Constraint
{
}
