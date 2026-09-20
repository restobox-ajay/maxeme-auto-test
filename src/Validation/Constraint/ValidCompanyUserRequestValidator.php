<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use App\Entity\CustomerUser;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/** Line-for-line port of the checks Customer\CompanyUserController::create()/edit() used to run by hand. */
final class ValidCompanyUserRequestValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidCompanyUserRequest) {
            throw new UnexpectedTypeException($constraint, ValidCompanyUserRequest::class);
        }

        if (!is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        $email = $value;

        if (trim($constraint->firstName) === '') {
            $this->context->buildViolation('First name is required.')->atPath('first_name')->addViolation();
        }
        if (trim($constraint->lastName) === '') {
            $this->context->buildViolation('Last name is required.')->atPath('last_name')->addViolation();
        }

        if ($email === '') {
            $this->context->buildViolation('Email is required.')->atPath('email')->addViolation();
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->context->buildViolation('Email must be valid.')->atPath('email')->addViolation();
        }

        $this->validatePassword($constraint);

        // The uniqueness lookup only runs once every other check is clean, exactly as the original
        // methods only queried the database inside their own "if ($errors === [])" guard.
        if (count($this->context->getViolations()) === 0) {
            $this->validateEmailUniqueness($email, $constraint);
        }
    }

    private function validatePassword(ValidCompanyUserRequest $constraint): void
    {
        $password = $constraint->password;
        $confirmPassword = $constraint->confirmPassword;
        $provided = $password !== '' || $confirmPassword !== '';

        if (!$constraint->passwordRequired && !$provided) {
            return;
        }

        if ($password === '' || $confirmPassword === '') {
            $this->context->buildViolation('Password and confirm password are required.')->atPath('password')->addViolation();
        } elseif ($password !== $confirmPassword) {
            $this->context->buildViolation('Passwords do not match.')->atPath('password')->addViolation();
        } elseif (strlen($password) < 8) {
            $this->context->buildViolation('Password must be at least 8 characters.')->atPath('password')->addViolation();
        }
    }

    private function validateEmailUniqueness(string $email, ValidCompanyUserRequest $constraint): void
    {
        $repo = $constraint->entityManager->getRepository(CustomerUser::class);
        $existing = $constraint->emailLookupInsensitive
            ? $repo->findOneByEmailInsensitive($email)
            : $repo->findOneBy(['email' => $email]);

        if ($existing instanceof CustomerUser && ($constraint->excludeUserId === null || $existing->getId() !== $constraint->excludeUserId)) {
            $this->context->buildViolation('An account with this email already exists.')->atPath('email')->addViolation();
        }
    }
}
