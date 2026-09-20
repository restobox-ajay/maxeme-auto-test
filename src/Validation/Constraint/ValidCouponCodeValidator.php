<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class ValidCouponCodeValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidCouponCode) {
            throw new UnexpectedTypeException($constraint, ValidCouponCode::class);
        }

        if ($value !== null && !is_array($value)) {
            throw new UnexpectedValueException($value, 'array|null');
        }

        if ($value === null) {
            $this->context->buildViolation('That coupon code is not valid.')->addViolation();

            return;
        }

        $minSubtotal = (float) ($value['minSubtotal'] ?? 0);
        if ($constraint->subtotal < $minSubtotal) {
            $this->context->buildViolation(sprintf(
                'Coupon %s requires a minimum subtotal of %s.',
                $constraint->couponCode,
                '$' . number_format($minSubtotal, 2, '.', ','),
            ))->addViolation();
        }
    }
}
