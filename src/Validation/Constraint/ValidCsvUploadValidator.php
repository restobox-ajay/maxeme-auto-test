<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/** Line-for-line port of the check ProductImportController::index() used to run inline, shared by the Number1 import bundles too. */
final class ValidCsvUploadValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidCsvUpload) {
            throw new UnexpectedTypeException($constraint, ValidCsvUpload::class);
        }

        if ($value !== null && !$value instanceof UploadedFile) {
            throw new UnexpectedValueException($value, UploadedFile::class);
        }

        if (!$value instanceof UploadedFile || !$value->isValid()) {
            $this->context->buildViolation('Please choose a valid CSV file to import.')
                ->atPath('csv_file')->addViolation();
        }
    }
}
