<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;

/**
 * Issue #310's port of ConfigController::registrationSettings()'s cross-field rule: "auto" mode
 * requires both a valid price list and fulfillment region to exist. The validated value is the
 * submitted mode; the two candidate IDs and the entity manager needed to resolve them are carried
 * as constraint options, since neither can be derived from the mode string alone.
 */
#[\Attribute]
final class ValidRegistrationSettingsRequest extends Constraint
{
    public function __construct(
        public readonly string $defaultPriceListId,
        public readonly string $defaultRegionId,
        public readonly EntityManagerInterface $entityManager,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
