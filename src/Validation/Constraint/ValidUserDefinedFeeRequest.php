<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Issue #313's port of UserDefinedFeeController::buildFromRequest(), following
 * ValidCustomFieldRequest's #310 pattern: the validated value is the raw posted fields (name,
 * default_value, tax_class, placement) rather than the Fee entity, since buildFromRequest() only
 * writes them onto the entity once every field checks out. The tax class label map is carried as
 * a constraint option — same reason ValidCustomFieldRequest carries its field/object type
 * choices — since it is only ever known to the controller that renders the <select>.
 */
#[\Attribute]
final class ValidUserDefinedFeeRequest extends Constraint
{
    public function __construct(
        public readonly array $taxClasses,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
