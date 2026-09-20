<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use App\Validation\Dto\NewPasswordRequest;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Line-for-line port of the password/confirm/length checks passwordReset() and accountSetup()
 * used to run by hand, now shared by both.
 */
final class ValidNewPasswordValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidNewPassword) {
            throw new UnexpectedTypeException($constraint, ValidNewPassword::class);
        }

        if ($value === null) {
            return;
        }

        if (!$value instanceof NewPasswordRequest) {
            throw new UnexpectedValueException($value, NewPasswordRequest::class);
        }

        if ($value->password === '' || $value->confirmPassword === '') {
            $this->context->buildViolation('Please enter and confirm your new password.')->addViolation();
        } elseif ($value->password !== $value->confirmPassword) {
            $this->context->buildViolation('Passwords do not match.')->addViolation();
        } elseif (strlen($value->password) < 8) {
            $this->context->buildViolation('Password must be at least 8 characters long.')->addViolation();
        }
    }
}
