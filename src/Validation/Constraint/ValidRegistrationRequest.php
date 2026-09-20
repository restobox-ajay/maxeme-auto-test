<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use App\Service\Region;
use Symfony\Component\Validator\Constraint;

/**
 * Issue #311's port of Customer\AuthController::register()'s inline field-error map: required
 * company/contact fields, email format, the password pair, agree-to-terms, and the shipping/
 * billing address pair including Region country/province normalisation — the same normalise-
 * and-write-back need ValidCompanyAddressRequest (#309) covers for a single address, except
 * register() posts two.
 *
 * Validated against an \ArrayObject wrapping the controller's raw values, not the array itself,
 * for the same reason as ValidCompanyAddressRequest: country/province normalisation rewrites the
 * posted values onto canonical codes, and only a handle — not a value-copied array — carries that
 * write back to the caller.
 */
#[\Attribute]
final class ValidRegistrationRequest extends Constraint
{
    public function __construct(
        public readonly Region $region,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
