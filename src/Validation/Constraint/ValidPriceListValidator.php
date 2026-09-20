<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use App\Entity\PriceList;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/** Line-for-line port of the rule PriceListController::validatePriceList() used to run by hand. */
final class ValidPriceListValidator extends ConstraintValidator
{
    private const STATUSES = ['Active', 'Inactive'];

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidPriceList) {
            throw new UnexpectedTypeException($constraint, ValidPriceList::class);
        }

        if ($value === null) {
            return;
        }

        if (!$value instanceof PriceList) {
            throw new UnexpectedValueException($value, PriceList::class);
        }

        if (trim($value->getName()) === '') {
            $this->context->buildViolation('Price list name is required.')
                ->atPath('name')->addViolation();
        }

        if (!preg_match('/^[A-Z]{3}$/', $constraint->rawCurrency)) {
            $this->context->buildViolation('Currency must be a 3-letter code (e.g. USD).')
                ->atPath('currency')->addViolation();
        }

        if (!in_array($value->getStatus(), self::STATUSES, true)) {
            $this->context->buildViolation('Status must be Active or Inactive.')
                ->atPath('status')->addViolation();
        }
    }
}
