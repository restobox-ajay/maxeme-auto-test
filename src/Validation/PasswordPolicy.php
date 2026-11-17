<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * The one password-length rule every form shares (reset, account setup, registration, company
 * users, password change, an admin setting someone's password). It used to be a literal 8 in each,
 * which is how the rule drifts: change it here and every entry point follows.
 *
 * app:create-admin keeps its own stricter minimum (12) for console-created admin accounts.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 8;

    public static function isTooShort(string $password): bool
    {
        return strlen($password) < self::MIN_LENGTH;
    }

    /** "Password must be at least 8 characters long." ($subject: "Password", "New password"…) */
    public static function tooShortMessage(string $subject = 'Password'): string
    {
        return sprintf('%s must be at least %d characters long.', $subject, self::MIN_LENGTH);
    }
}
