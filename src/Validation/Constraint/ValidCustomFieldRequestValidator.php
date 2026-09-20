<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/** Line-for-line port of the checks CustomFieldController::validateRequest() used to run by hand. */
final class ValidCustomFieldRequestValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidCustomFieldRequest) {
            throw new UnexpectedTypeException($constraint, ValidCustomFieldRequest::class);
        }

        if (!is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        if ($value === '') {
            $this->context->buildViolation('Label is required.')->atPath('label')->addViolation();
        }

        if (!array_key_exists($constraint->fieldType, $constraint->fieldTypes)) {
            $this->context->buildViolation('A valid field type is required.')->atPath('field_type')->addViolation();
        }

        if (!$constraint->isCreate) {
            return;
        }

        $objectType = $constraint->objectType ?? '';
        if (!array_key_exists($objectType, $constraint->objectTypes)) {
            $this->context->buildViolation('A valid object type is required.')->atPath('object_type')->addViolation();
        }

        $slug = $constraint->slug ?? '';
        if ($slug === '' || !preg_match('/^[a-z0-9_]+$/', $slug)) {
            $this->context->buildViolation('Slug is required and may only contain lowercase letters, numbers, and underscores.')->atPath('slug')->addViolation();
        } elseif ($objectType !== '' && $constraint->definitionRepo->findBySlug($objectType, $slug) !== null) {
            $this->context->buildViolation(sprintf('A custom field with slug "%s" already exists for this object type.', $slug))->atPath('slug')->addViolation();
        }
    }
}
