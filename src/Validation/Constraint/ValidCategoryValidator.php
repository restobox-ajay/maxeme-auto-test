<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use App\Entity\ProductCategory;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/** Line-for-line port of the rule CategoryController::validateCategory() used to run by hand. */
final class ValidCategoryValidator extends ConstraintValidator
{
    private const STATUSES = ['Visible', 'Hidden'];

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidCategory) {
            throw new UnexpectedTypeException($constraint, ValidCategory::class);
        }

        if ($value === null) {
            return;
        }

        if (!$value instanceof ProductCategory) {
            throw new UnexpectedValueException($value, ProductCategory::class);
        }

        if (trim($value->getName()) === '') {
            $this->context->buildViolation('Category name is required.')
                ->atPath('name')->addViolation();
        }

        if (!in_array($value->getStatus(), self::STATUSES, true)) {
            $this->context->buildViolation('Category status must be Visible or Hidden.')
                ->atPath('status')->addViolation();
        }
    }
}
