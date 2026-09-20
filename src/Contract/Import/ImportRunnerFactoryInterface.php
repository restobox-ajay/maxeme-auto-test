<?php

declare(strict_types=1);

namespace App\Contract\Import;

use App\Entity\ImportRun;
use App\Service\Import\ImportRunner;

/**
 * One per import type. Registered as `app.import_runner_factory` (see config/services.yaml) and
 * collected by App\Service\Import\ImportRunnerRegistry, so import:process can build the right
 * ImportRunner for any queued ImportRun without core ever referencing a bundle's classes — the
 * same seam DimensionalImportProviderInterface and friends already use for this codebase's other
 * "core drives it, a bundle supplies it" points.
 */
interface ImportRunnerFactoryInterface
{
    /** Must match exactly one ImportDefinitionInterface::name() — what ImportRun::$importType holds. */
    public function importType(): string;

    /**
     * Builds a fresh ImportRunner wired to this import type's definition/validator/executor. Takes
     * the queued $run itself (not just its type) so an executor with run-level options beyond
     * per-row mapped data — e.g. ProductImportRowExecutorFactory reading missing_rows/
     * clear_approved_balance off $run->getContext() — can prepare itself before ImportRunner starts
     * calling execute() per row.
     */
    public function create(ImportRun $run): ImportRunner;
}
