<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Issue #308's port of InventoryController::update()'s inline checks, following
 * ValidFulfillmentRegionActivation's #317 pattern: the validated value is the submitted quantity,
 * with the product/warehouse ids carried as constraint options since neither can be derived from
 * the quantity alone.
 */
#[\Attribute]
final class ValidInventoryUpdate extends Constraint
{
    public function __construct(
        public readonly int $productId,
        public readonly int $warehouseId,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
