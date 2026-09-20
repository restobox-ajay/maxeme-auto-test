<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Class-level constraint (issue #311) shared by Customer\AuthController::passwordReset()'s
 * token-set branch and accountSetup() — both ran this exact three-check sequence by hand and
 * reported it as a single flash message rather than a per-field error, unlike the "change
 * password" forms ValidPasswordChange covers: there is no current password to check here, since
 * both routes authenticate the request via a one-time reset token instead of a live session.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidNewPassword extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
