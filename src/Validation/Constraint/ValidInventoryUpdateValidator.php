<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/** Line-for-line port of the checks InventoryController::update() used to run inline. */
final class ValidInventoryUpdateValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidInventoryUpdate) {
            throw new UnexpectedTypeException($constraint, ValidInventoryUpdate::class);
        }

        if (!is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        if ($constraint->productId <= 0 || $constraint->warehouseId <= 0) {
            $this->context->buildViolation('Invalid request.')->addViolation();

            return;
        }

        if (!ctype_digit($value)) {
            $this->context->buildViolation('Quantity must be a non-negative whole number.')
                ->atPath('quantity')->addViolation();
        }
    }
}
