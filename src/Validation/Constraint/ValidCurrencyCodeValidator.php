<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/** Line-for-line port of the check ConfigController::baseCurrency() used to run by hand. */
final class ValidCurrencyCodeValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidCurrencyCode) {
            throw new UnexpectedTypeException($constraint, ValidCurrencyCode::class);
        }

        if (!is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        if (!preg_match('/^[A-Z]{3}$/', $value)) {
            $this->context->buildViolation('Base Currency must be a valid 3-letter currency code, for example CAD or USD.')->addViolation();
        }
    }
}
