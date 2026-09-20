<?php

declare(strict_types=1);

namespace App\Contract\Import;

use App\Entity\ImportRun;

/**
 * Optional companion to ImportValidatorInterface, for a check that only makes sense across the
 * WHOLE file rather than one row at a time — e.g. the same upsert key naming two different items,
 * which no single row can see is wrong. ImportRunner checks for this via instanceof right after
 * parseAndMap(), before a run is ever queued: a non-null result refuses the entire file rather than
 * letting corrupted rows execute and silently overwrite good data (see
 * VendorSheetImportValidator::checkWholeRun(), added after a vendor sheet with a reused,
 * inconsistent SKU column clobbered 203 of 204 freshly-created products' prices).
 */
interface ImportWholeRunCheckInterface
{
    /** @return string|null a whole-run rejection reason, or null if the file is fine to queue */
    public function checkWholeRun(ImportRun $run): ?string;
}
