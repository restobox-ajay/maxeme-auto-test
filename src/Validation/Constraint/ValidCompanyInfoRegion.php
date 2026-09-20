<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use App\Service\Region;
use Symfony\Component\Validator\Constraint;

/**
 * Issue #310's port of ConfigController::companyInformation()'s country/province
 * normalize-or-reject rule. The validated value is an \ArrayObject wrapping the submitted
 * company_country/company_state pair — a plain array is copied on entry to validate(), which
 * would make the normalization write-back invisible to the caller, same reasoning as
 * ValidCompanyAddressRequest.
 */
#[\Attribute]
final class ValidCompanyInfoRegion extends Constraint
{
    public function __construct(
        public readonly Region $region,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
