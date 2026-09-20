<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/** Line-for-line port of the checks ConfigController::validateSimpleConfigRow() used to run by hand. */
final class ValidSimpleConfigRowValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidSimpleConfigRow) {
            throw new UnexpectedTypeException($constraint, ValidSimpleConfigRow::class);
        }

        if (!$value instanceof \ArrayAccess) {
            throw new UnexpectedValueException($value, \ArrayAccess::class);
        }

        foreach ($constraint->requiredFields as $field) {
            if (trim((string) ($value[$field] ?? '')) === '') {
                $this->context->buildViolation(sprintf('%s is required.', $this->fieldLabel($field)))
                    ->atPath($field)->addViolation();
            }
        }

        foreach ($constraint->numericFields as $field) {
            $raw = trim((string) ($value[$field] ?? ''));
            if ($raw === '') {
                continue;
            }

            $normalized = str_replace(',', '', $raw);
            if (!is_numeric($normalized) || (float) $normalized < 0) {
                $this->context->buildViolation(sprintf('%s must be a non-negative number.', $this->fieldLabel($field)))
                    ->atPath($field)->addViolation();
            }
        }
    }

    private function fieldLabel(string $field): string
    {
        return ucwords(str_replace('_', ' ', $field));
    }
}
