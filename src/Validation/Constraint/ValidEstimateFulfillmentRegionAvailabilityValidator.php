<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class ValidEstimateFulfillmentRegionAvailabilityValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidEstimateFulfillmentRegionAvailability) {
            throw new UnexpectedTypeException($constraint, ValidEstimateFulfillmentRegionAvailability::class);
        }

        if (!is_bool($value)) {
            throw new UnexpectedValueException($value, 'bool');
        }

        if (!$value) {
            $this->context->buildViolation(sprintf(
                '%s has no active fulfillment region, so there is no price list to quote from. Activate a fulfillment region for this company before creating a quote.',
                $constraint->companyName,
            ))->addViolation();
        }
    }
}
