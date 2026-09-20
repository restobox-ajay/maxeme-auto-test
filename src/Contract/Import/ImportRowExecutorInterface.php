<?php

declare(strict_types=1);

namespace App\Contract\Import;

use App\Enum\ImportAction;

/**
 * Persists one already-validated row against the target entity. The counterpart to
 * ImportValidatorInterface — that one only says whether a row is well-formed, this one is what
 * actually writes it.
 *
 * Called exactly once per row, only from inside `import:process` (ImportRunner::executeQueued()),
 * never in-request — a validation-only run never reaches this. Deliberately not called
 * `ImportExecutorInterface`: it runs per row, not once per run, since many imports (VendorSheet
 * upserting VendorPrice) can only decide append vs. update by looking each row up individually.
 */
interface ImportRowExecutorInterface
{
    /**
     * @param array<string, mixed> $mappedData target field key => value
     *
     * @return ImportAction the action actually taken — may differ from what the row's own data
     *                       suggested (e.g. an "update" row whose target turned out not to exist)
     *
     * @throws \RuntimeException on any failure; ImportRunner catches it, records it as
     *                           ImportRunRow::$error, and continues with the next row (see D2 —
     *                           execute() failing on one row never stops the rest of the file)
     */
    public function execute(array $mappedData): ImportAction;
}
