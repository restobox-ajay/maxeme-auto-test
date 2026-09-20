<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Issue #314's port of NewsPostController::buildFromRequest(): required title/content plus a
 * required, parseable published date. Validated against an \ArrayObject of the raw posted values
 * (title, content, published_at) rather than the NewsPost entity, since NewsPost::setPublishedAt()
 * takes a non-nullable \DateTimeImmutable and so can't be called until the raw string has been
 * parsed — the validator parses it and writes the parsed value back into the ArrayObject,
 * following ValidCompanyAddressRequest's #309 write-back pattern.
 */
#[\Attribute]
final class ValidNewsPostRequest extends Constraint
{
}
