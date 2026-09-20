<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Issue #306's port of create()'s hand-built "no active fulfillment region" refusal, following
 * ValidFulfillmentRegionActivation's shape (#317): the fact this checks — whether the company has
 * ANY active region at all — is already computed by OrderController::orderCompanyRegionRows() for
 * the region dropdown, so it is carried as the validated bool with the company's name as a
 * constraint option for the message, rather than the constraint re-deriving either from a repository.
 *
 * edit() deliberately never validates against this constraint — an order already in flight must stay
 * correctable even after the company's regions are deactivated underneath it (see edit()'s door
 * check). Only create() does, where there is no earlier correct value to fall back to.
 */
#[\Attribute]
final class ValidFulfillmentRegionAvailability extends Constraint
{
    public function __construct(
        public readonly string $companyName,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
