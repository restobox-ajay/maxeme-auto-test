<?php

declare(strict_types=1);

namespace App\Enum;

/** Per-row outcome, distinct from ImportRunStatus (which is the whole run's workflow state). */
enum ImportRowStatus: string
{
    /** Passed validation; not yet (or never, for a validation-only run) written. */
    case Validated = 'validated';

    /** Validation failed — see ImportRunRow::$error. Never reaches execution. */
    case Failed = 'failed';

    /** Validated and, for a real (non-validation-only) run, actually persisted. */
    case Succeeded = 'succeeded';
}
