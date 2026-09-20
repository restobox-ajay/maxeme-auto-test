<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/** Line-for-line port of the check CartHoldConfigController::config() used to run by hand. */
final class ValidCartHoldDurationValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidCartHoldDuration) {
            throw new UnexpectedTypeException($constraint, ValidCartHoldDuration::class);
        }

        if (!is_int($value)) {
            throw new UnexpectedValueException($value, 'int');
        }

        if ($value < 1) {
            $this->context->buildViolation('Hold duration must be at least 1 second.')->addViolation();
        }
    }
}
