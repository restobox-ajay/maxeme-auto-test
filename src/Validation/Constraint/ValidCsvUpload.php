<?php

declare(strict_types=1);

namespace App\Validation\Constraint;

use Symfony\Component\Validator\Constraint;

/**
 * Port of the "please choose a valid CSV file" check every CSV-upload controller
 * (core and Number1 import bundles) ran by hand: reject when no file was chosen, or the
 * upload didn't survive transit (partial upload, exceeded ini limits, etc.). The validated
 * value is the submitted upload itself, since there is no entity or other field the rule
 * needs alongside it.
 */
#[\Attribute]
final class ValidCsvUpload extends Constraint
{
}
