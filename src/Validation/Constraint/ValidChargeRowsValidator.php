<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use App\Service\SalesDocumentChargeLines;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Runs the same two rules errorFor() used to catch by hand — normalize()'s unknown-line-type
 * refusal and assertValid()'s After Tax/Exempt refusal — as a constraint instead, so a future fix
 * to either rule is inherited by every errorFor() caller without them changing anything. Delegates
 * to those two methods rather than re-stating their logic, since they are the vocabulary's single
 * source of truth and are also called directly elsewhere (toFeeLines(), normalize()'s own callers).
 */
final class ValidChargeRowsValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidChargeRows) {
            throw new UnexpectedTypeException($constraint, ValidChargeRows::class);
        }

        try {
            SalesDocumentChargeLines::assertValid(SalesDocumentChargeLines::normalize($value));
        } catch (\InvalidArgumentException $e) {
            $this->context->buildViolation($e->getMessage())->addViolation();
        }
    }
}
