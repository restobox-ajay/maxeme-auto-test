<?php

declare(strict_types=1);

namespace App\Contract\Import;

use App\Entity\ImportRun;

/**
 * Optional companion to ImportRowExecutorInterface, for an import that has a whole-run step beyond
 * "for each row" — e.g. ProductImportService's missing_rows: inactive_missing pass, which only
 * makes sense once every row has been seen, or reporting a summary figure the per-row audit alone
 * doesn't carry (ProductImportService::finalize() appending its unitFlagged count onto
 * $run->description, so it can't hide inside an otherwise-green run). ImportRunner checks for this
 * via instanceof after the row loop finishes (see executeQueued()); an executor with no such step
 * just doesn't implement it.
 */
interface ImportRunFinalizableInterface
{
    /** Called once, after every row in the run has gone through execute(). */
    public function finalize(ImportRun $run): void;
}
