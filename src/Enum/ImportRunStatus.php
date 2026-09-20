<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Workflow state only — never outcome. A completed run can still have failed rows; that is what
 * ImportRun::$errorCount and ImportRun::$error are for, not this enum. Kept to three values on
 * purpose (queued/started/completed, no separate "succeeded"/"failed") per the framework's own
 * design doc, docs/plans/2026-09-18-unified-import-framework.md.
 */
enum ImportRunStatus: string
{
    /** Row written, nothing has processed it yet — sits in the queue for import:process. */
    case Queued = 'queued';

    /** import:process picked it up and holds the flock on it right now. */
    case Started = 'started';

    /** Processing stopped, whether or not every row succeeded — see ImportRun::$error/$errorCount. */
    case Completed = 'completed';
}
