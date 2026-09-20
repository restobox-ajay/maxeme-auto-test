<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Issue #313's port of CartHoldConfigController's inline "duration must be at least 1 second"
 * check. The validated value is the raw posted int rather than an entity — the duration is a
 * bare AppSetting scalar, with no entity of its own for a class-level constraint to attach to.
 */
#[\Attribute]
final class ValidCartHoldDuration extends Constraint
{
}
