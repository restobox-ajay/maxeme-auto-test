<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Reference implementation for issue #222 — the app's first symfony/validator constraint.
 *
 * A class-level (not property-level) constraint because the rules RedirectController used to run
 * by hand in validateRequest() are cross-field (destination_type decides which destination field is
 * required) and DB-dependent (source path uniqueness, destination category existence) — none of
 * which a #[Assert\NotBlank] on a single property could express. ValidRedirectValidator is the
 * literal port of that method's logic, now as an injectable service instead of a private method
 * that had to be called by hand at the top of every action.
 *
 * See App\EventSubscriber\ValidationExceptionSubscriber for what happens when this fails — the
 * counterpart to CsrfProtectionSubscriber for validation instead of forged requests.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidRedirect extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
