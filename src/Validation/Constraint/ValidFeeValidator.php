<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use App\Entity\Fee;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/** Delegates to Fee::assertValid(), the single source of truth for what makes a fee valid. */
final class ValidFeeValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidFee) {
            throw new UnexpectedTypeException($constraint, ValidFee::class);
        }

        if ($value === null) {
            return;
        }

        if (!$value instanceof Fee) {
            throw new UnexpectedValueException($value, Fee::class);
        }

        try {
            $value->assertValid();
        } catch (\InvalidArgumentException $e) {
            $this->context->buildViolation($e->getMessage())->addViolation();
        }
    }
}
