<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use App\Service\Region;
use Symfony\Component\Validator\Constraint;

/**
 * Issue #309's port of CompanyController::validateAddress(), following ValidRedirect's #222/#305
 * pattern: a class-level constraint over the whole CompanyAddress entity, since the rule both reads
 * and writes it (country/province get normalised onto the address as a side effect of validating
 * them). Region is carried as a constraint option rather than injected into the validator: it is
 * optional here exactly as it was in the original method (some callers validate without it), which
 * a constructor dependency could not express.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ValidCompanyAddress extends Constraint
{
    public function __construct(
        public readonly ?Region $region = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
