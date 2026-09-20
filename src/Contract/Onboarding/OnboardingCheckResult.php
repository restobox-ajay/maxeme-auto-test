<?php

declare(strict_types=1);

namespace App\Contract\Onboarding;

/**
 * The outcome of a single OnboardingCheckInterface::run() call (#426).
 *
 * $message is shown to every admin viewing /admin/onboarding — the page is deliberately
 * accessible to all admins, not just Tech Support, so a check must never put anything sensitive
 * in here (a user's email, a raw DSN, a secret value). Say WHAT is wrong and WHERE to fix it,
 * never WHO or WHAT VALUE. See TechSupportUserExistsCheck for the sharpest example: it reports
 * yes/no only, by design, per the issue's own instruction not to show detail.
 */
final class OnboardingCheckResult
{
    public function __construct(
        public readonly bool $passed,
        public readonly string $message,
    ) {
    }

    public static function pass(string $message = 'OK'): self
    {
        return new self(true, $message);
    }

    public static function fail(string $message): self
    {
        return new self(false, $message);
    }
}
