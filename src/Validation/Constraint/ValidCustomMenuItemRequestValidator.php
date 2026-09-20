<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/** Line-for-line port of the checks FrontendMenuManagementController::validateCustomRequest() used to run by hand. */
final class ValidCustomMenuItemRequestValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidCustomMenuItemRequest) {
            throw new UnexpectedTypeException($constraint, ValidCustomMenuItemRequest::class);
        }

        if (!$value instanceof \ArrayAccess) {
            throw new UnexpectedValueException($value, \ArrayAccess::class);
        }

        $label = (string) ($value['label'] ?? '');
        if ($label === '') {
            $this->context->buildViolation('Label is required.')->atPath('label')->addViolation();
        } elseif (strlen($label) > 150) {
            $this->context->buildViolation('Label is too long (150 characters max).')->atPath('label')->addViolation();
        }

        $url = (string) ($value['url'] ?? '');
        if ($url === '') {
            $this->context->buildViolation('URL is required.')->atPath('url')->addViolation();
        } elseif (!str_starts_with($url, '/') && !str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $this->context->buildViolation('URL must be a relative path starting with "/" or an absolute http(s) URL.')->atPath('url')->addViolation();
        } elseif (strlen($url) > 2048) {
            $this->context->buildViolation('URL is too long (2048 characters max).')->atPath('url')->addViolation();
        }
    }
}
