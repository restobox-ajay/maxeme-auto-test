<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use App\Service\Region;
use Symfony\Component\Validator\Constraint;

/**
 * Issue #309's port of the validation Customer\CompanyAddressController::create() and edit() used
 * to duplicate inline, plus their shared validateRegion() helper: required label/address1/city,
 * and country/province normalisation against Region.
 *
 * The validated value is an \ArrayObject wrapping the controller's raw $values array rather than
 * the array itself: validateRegion() used to both validate and rewrite the posted country/province
 * onto canonical codes, and a plain PHP array is copied on entry to validate(), which would make
 * that rewrite invisible to the caller. \ArrayObject is a handle, so ValidCompanyAddressRequestValidator's
 * writes land back in the same values the controller holds.
 */
#[\Attribute]
final class ValidCompanyAddressRequest extends Constraint
{
    public function __construct(
        public readonly Region $region,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
