<?php

declare(strict_types=1);

namespace App\Maxeme\Service;

use Symfony\Component\Validator\ConstraintViolationListInterface;

/** Flattens violations to one message per field, the shape Maxeme templates read as `errors.<field>`. */
final class FieldErrors
{
    /** @return array<string, string> property path => first message */
    public static function from(ConstraintViolationListInterface $violations): array
    {
        $errors = [];
        foreach ($violations as $violation) {
            $errors[$violation->getPropertyPath()] ??= (string) $violation->getMessage();
        }

        return $errors;
    }
}
