<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/** Line-for-line port of the rule CompanyFulfillmentRegionService::validateActivation() used to run by hand. */
final class ValidFulfillmentRegionActivationValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidFulfillmentRegionActivation) {
            throw new UnexpectedTypeException($constraint, ValidFulfillmentRegionActivation::class);
        }

        if (!is_bool($value)) {
            throw new UnexpectedValueException($value, 'bool');
        }

        if ($value && !$constraint->hasPriceList) {
            $this->context->buildViolation(sprintf('Select a price list before activating "%s".', $constraint->regionName))
                ->addViolation();
        }
    }
}
