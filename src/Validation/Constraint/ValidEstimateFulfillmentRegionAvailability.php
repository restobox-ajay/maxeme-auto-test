<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Issue #307's port of EstimateController::create()'s hand-built "no active fulfillment region"
 * refusal, following ValidFulfillmentRegionAvailability's #306 shape: the fact this checks —
 * whether the company has ANY active region at all — is already computed by
 * EstimateController::estimateCompanyRegionRows() for the region dropdown, so it is carried as
 * the validated bool with the company's name as a constraint option for the message, rather than
 * the constraint re-deriving either from a repository. The message differs from the order
 * version's ("quote" rather than "order"), which is why this is its own constraint rather than a
 * reuse of ValidFulfillmentRegionAvailability.
 *
 * edit() deliberately never validates against this constraint — an in-flight quote must stay
 * correctable even after the company's regions are deactivated underneath it (see edit()'s door
 * check). Only create() does, where there is no earlier correct value to fall back to.
 */
#[\Attribute]
final class ValidEstimateFulfillmentRegionAvailability extends Constraint
{
    public function __construct(
        public readonly string $companyName,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
