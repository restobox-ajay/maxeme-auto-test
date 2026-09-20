<?php

declare(strict_types=1);

namespace App\Validation\Dto;

use App\Validation\Constraint\ValidNewPassword;

/**
 * Shared shape for the token-authenticated "set a new password" forms (issue #311) —
 * Customer\AuthController::passwordReset()'s token-set branch and accountSetup() both post just
 * these two fields and enforce the same rules, reported as one flash message rather than
 * per-field errors.
 */
#[ValidNewPassword]
final class NewPasswordRequest
{
    public function __construct(
        public readonly string $password,
        public readonly string $confirmPassword,
    ) {
    }
}
