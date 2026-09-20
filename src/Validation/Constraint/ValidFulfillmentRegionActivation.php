<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Issue #317's fulfillment-region half of the ValidRedirect pattern (#222/#305): validates the
 * boolean "activating?" decision itself, with the row's other two facts — whether a price list is
 * attached and the region's name for the message — carried as constraint options rather than a
 * validated value, since CompanyController, CompanyFulfillmentRegionController and
 * ConfigController::guestFulfillmentRegions() all call this ahead of a row existing (a checklist
 * post, a single row's form, a guest-visibility toggle) and none of them share an entity to attach
 * the rule to.
 */
#[\Attribute]
final class ValidFulfillmentRegionActivation extends Constraint
{
    public function __construct(
        public readonly bool $hasPriceList,
        public readonly string $regionName,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
