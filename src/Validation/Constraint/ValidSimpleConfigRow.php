<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Issue #310's port of ConfigController::validateSimpleConfigRow(), the generic check driving the
 * payment_term/credit_memo_type/sales_tax/shipping_zone family of admin forms
 * (self::SIMPLE_CONFIG). The validated value is an \ArrayObject wrapping the submitted row; which
 * fields are required and which must be non-negative numbers is entirely metadata-driven, so both
 * lists are carried as constraint options rather than hard-coded here.
 */
#[\Attribute]
final class ValidSimpleConfigRow extends Constraint
{
    /**
     * @param list<string> $requiredFields
     * @param list<string> $numericFields
     */
    public function __construct(
        public readonly array $requiredFields,
        public readonly array $numericFields,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
