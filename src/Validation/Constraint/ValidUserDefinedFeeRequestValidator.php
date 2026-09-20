<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use App\Entity\Fee;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/** Line-for-line port of the checks UserDefinedFeeController::buildFromRequest() used to run by hand. */
final class ValidUserDefinedFeeRequestValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidUserDefinedFeeRequest) {
            throw new UnexpectedTypeException($constraint, ValidUserDefinedFeeRequest::class);
        }

        if (!is_array($value)) {
            throw new UnexpectedValueException($value, 'array');
        }

        if (trim((string) ($value['name'] ?? '')) === '') {
            $this->context->buildViolation('Name is required.')->atPath('name')->addViolation();
        }

        $defaultValue = $value['default_value'] ?? '';
        if (!is_numeric($defaultValue) || (float) $defaultValue < 0) {
            $this->context->buildViolation('A valid default value is required.')->atPath('default_value')->addViolation();
        }

        $taxClass = (string) ($value['tax_class'] ?? '');
        if (!array_key_exists($taxClass, $constraint->taxClasses)) {
            $this->context->buildViolation('Invalid tax class.')->atPath('tax_class')->addViolation();
        }

        $placement = (string) ($value['placement'] ?? Fee::PLACEMENT_MAIN_LINE);
        if (!in_array($placement, Fee::PLACEMENTS, true)) {
            $this->context->buildViolation('Invalid placement.')->atPath('placement')->addViolation();
        } elseif ($placement === Fee::PLACEMENT_AFTER_TAX && $taxClass !== 'E') {
            $this->context->buildViolation('After Tax fees cannot be taxable — set Tax Class to Exempt (E).')->atPath('placement')->addViolation();
        }
    }
}
