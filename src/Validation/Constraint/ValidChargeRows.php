<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Issue #317's charge-lines half of the ValidRedirect pattern (#222/#305): validates a raw
 * charges_lines post — the mixed $chargesRaw both OrderController and EstimateController collect
 * before touching their document — rather than an entity, since normalize() and assertValid()
 * both run before anything is persisted. There is no DB dependency here (unlike ValidRedirect's
 * source-path lookup), so ValidChargeRowsValidator needs no injected repository and
 * SalesDocumentChargeLines::errorFor() can build a validator by hand instead of taking one as a
 * constructor dependency, keeping errorFor() the static call every caller already has.
 */
#[\Attribute]
final class ValidChargeRows extends Constraint
{
}
