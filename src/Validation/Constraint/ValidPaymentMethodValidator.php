<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use App\Entity\PaymentMethod;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/** Line-for-line port of the check ManualPaymentMethodController::buildFromRequest() used to run by hand. */
final class ValidPaymentMethodValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidPaymentMethod) {
            throw new UnexpectedTypeException($constraint, ValidPaymentMethod::class);
        }

        if (!$value instanceof PaymentMethod) {
            throw new UnexpectedValueException($value, PaymentMethod::class);
        }

        if (trim($value->getName()) === '') {
            $this->context->buildViolation('Name is required.')->atPath('name')->addViolation();
        }
    }
}
