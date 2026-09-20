<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Issue #308's port of PriceListController::validatePriceList(), following ValidCompanyAddress's
 * #309 pattern: a class-level constraint over the whole PriceList entity, with the raw submitted
 * currency carried as a constraint option rather than read off the entity — PriceList::setCurrency()
 * silently truncates to 3 chars, so checking the already-truncated value would let garbage like
 * "DOLLARS" through as "DOL".
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidPriceList extends Constraint
{
    public function __construct(
        public readonly string $rawCurrency,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
