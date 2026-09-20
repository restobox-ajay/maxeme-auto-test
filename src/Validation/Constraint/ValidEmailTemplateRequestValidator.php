<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/** Line-for-line port of the check ConfigController::handleEmailTemplateForm() used to run by hand. */
final class ValidEmailTemplateRequestValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidEmailTemplateRequest) {
            throw new UnexpectedTypeException($constraint, ValidEmailTemplateRequest::class);
        }

        if (!$value instanceof \ArrayAccess) {
            throw new UnexpectedValueException($value, \ArrayAccess::class);
        }

        $module = (string) ($value['module'] ?? '');
        $subject = (string) ($value['subject'] ?? '');
        $body = (string) ($value['body'] ?? '');

        if ($module === '' || $subject === '' || $body === '') {
            $this->context->buildViolation('Module, subject, and body are required.')->addViolation();
        }
    }
}
