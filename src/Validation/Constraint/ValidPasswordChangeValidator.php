<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use App\Validation\Dto\PasswordChangeRequest;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Line-for-line port of the checks AuthController::profile() (admin) and Customer\ProfileController
 * ::profile() used to run by hand on their "change password" form, now shared by both.
 */
final class ValidPasswordChangeValidator extends ConstraintValidator
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidPasswordChange) {
            throw new UnexpectedTypeException($constraint, ValidPasswordChange::class);
        }

        if ($value === null) {
            return;
        }

        if (!$value instanceof PasswordChangeRequest) {
            throw new UnexpectedValueException($value, PasswordChangeRequest::class);
        }

        if ($value->currentPassword === '') {
            $this->context->buildViolation('Current password is required.')
                ->atPath('currentPassword')->addViolation();
        } elseif (!$this->passwordHasher->isPasswordValid($value->user, $value->currentPassword)) {
            $this->context->buildViolation('Current password is incorrect.')
                ->atPath('currentPassword')->addViolation();
        }

        if ($value->newPassword === '') {
            $this->context->buildViolation('New password is required.')
                ->atPath('newPassword')->addViolation();
        } elseif (strlen($value->newPassword) < 8) {
            $this->context->buildViolation('New password must be at least 8 characters long.')
                ->atPath('newPassword')->addViolation();
        }

        if ($value->confirmNewPassword === '') {
            $this->context->buildViolation('Please confirm the new password.')
                ->atPath('confirmNewPassword')->addViolation();
        } elseif ($value->newPassword !== $value->confirmNewPassword) {
            $this->context->buildViolation('Passwords do not match.')
                ->atPath('confirmNewPassword')->addViolation();
        }
    }
}
