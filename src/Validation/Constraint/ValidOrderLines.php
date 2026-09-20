<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Issue #306's counterpart to ValidChargeRows (#317) for the order form's other door check:
 * OrderController::validateOrderLines()'s at-least-one-non-blank-line rule, ported as a raw-array
 * constraint the same way — no DB dependency, so ValidOrderLinesValidator needs no injected
 * repository and OrderController can build a validator by hand instead of taking one as a
 * constructor dependency, keeping validateOrderLines() the private helper every caller already has.
 */
#[\Attribute]
final class ValidOrderLines extends Constraint
{
}
