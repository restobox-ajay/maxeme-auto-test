<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/** Line-for-line port of the three MIME/size checks ConfigController::brandingSettings() used to run by hand. */
final class ValidUploadedImageValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidUploadedImage) {
            throw new UnexpectedTypeException($constraint, ValidUploadedImage::class);
        }

        if (!$value instanceof UploadedFile) {
            throw new UnexpectedValueException($value, UploadedFile::class);
        }

        if (!in_array($value->getMimeType(), $constraint->allowedMimes, true)) {
            $this->context->buildViolation($constraint->invalidTypeMessage)->addViolation();
        } elseif ($value->getSize() > $constraint->maxBytes) {
            $this->context->buildViolation($constraint->tooLargeMessage)->addViolation();
        }
    }
}
