<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use App\Entity\SalesTax;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/** Line-for-line port of the rule BCTaxConfigController/CanadaSimpleTaxConfigController used to run by hand. */
final class ValidSalesTaxValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidSalesTax) {
            throw new UnexpectedTypeException($constraint, ValidSalesTax::class);
        }

        if ($value === null) {
            return;
        }

        if (!$value instanceof SalesTax) {
            throw new UnexpectedValueException($value, SalesTax::class);
        }

        if ($value->getRate() < 0) {
            $this->context->buildViolation(sprintf('Rate for "%s" cannot be negative.', $value->getProvinceName()))
                ->atPath('rate')->addViolation();
        }
    }
}
