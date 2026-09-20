<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/** Line-for-line port of the checks Customer\ContactController::index() used to run by hand. */
final class ValidContactRequestValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidContactRequest) {
            throw new UnexpectedTypeException($constraint, ValidContactRequest::class);
        }

        if (!$value instanceof \ArrayAccess) {
            throw new UnexpectedValueException($value, \ArrayAccess::class);
        }

        if ((string) $value['name'] === '') {
            $this->context->buildViolation('Name is required.')->atPath('name')->addViolation();
        }

        $email = (string) $value['email'];
        if ($email === '') {
            $this->context->buildViolation('Email is required.')->atPath('email')->addViolation();
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->context->buildViolation('Email must be a valid email address.')->atPath('email')->addViolation();
        }

        if ((string) $value['location'] === '') {
            $this->context->buildViolation('Location is required.')->atPath('location')->addViolation();
        }

        if ((string) $value['subject'] === '') {
            $this->context->buildViolation('Subject is required.')->atPath('subject')->addViolation();
        }

        $comments = (string) $value['comments'];
        if ($comments === '') {
            $this->context->buildViolation('Comments are required.')->atPath('comments')->addViolation();
        } elseif (mb_strlen($comments) < 5) {
            $this->context->buildViolation('Comments must be at least 5 characters.')->atPath('comments')->addViolation();
        }
    }
}
