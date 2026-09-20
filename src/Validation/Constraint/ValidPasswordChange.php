<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Class-level constraint (issue #311) shared by the admin and customer "change password"
 * self-service forms — Admin\AuthController::profile() and Customer\ProfileController::profile()
 * used to run this exact sequence of checks by hand, and both ran it identically. A service
 * validator rather than #[Assert\Callback] because confirming the current password needs
 * UserPasswordHasherInterface, which a Callback closure has no constructor to receive — same
 * reasoning as ValidRedirect/ValidRedirectValidator (issue #222).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidPasswordChange extends Constraint
{
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
