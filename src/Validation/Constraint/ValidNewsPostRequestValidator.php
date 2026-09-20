<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/** Line-for-line port of the checks NewsPostController::buildFromRequest() used to run by hand. */
final class ValidNewsPostRequestValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidNewsPostRequest) {
            throw new UnexpectedTypeException($constraint, ValidNewsPostRequest::class);
        }

        if (!$value instanceof \ArrayObject) {
            throw new UnexpectedValueException($value, \ArrayObject::class);
        }

        if ((string) ($value['title'] ?? '') === '') {
            $this->context->buildViolation('Title is required.')->atPath('title')->addViolation();
        }

        if ((string) ($value['content'] ?? '') === '') {
            $this->context->buildViolation('Content is required.')->atPath('content')->addViolation();
        }

        $publishedAtRaw = (string) ($value['published_at'] ?? '');
        if ($publishedAtRaw === '') {
            $this->context->buildViolation('Published date is required.')->atPath('published_at')->addViolation();

            return;
        }

        try {
            $value['published_at'] = new \DateTimeImmutable($publishedAtRaw);
        } catch (\Exception) {
            $this->context->buildViolation('Invalid published date.')->atPath('published_at')->addViolation();
        }
    }
}
