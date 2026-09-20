<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Contract\Import\ImportDefinitionInterface;
use App\Contract\Import\ImportRowExecutorInterface;
use App\Contract\Import\ImportRunFinalizableInterface;
use App\Contract\Import\ImportValidatorInterface;
use App\Contract\Import\ImportWholeRunCheckInterface;
use App\Entity\ImportRun;
use App\Entity\ImportRunRow;
use App\Enum\ImportRowStatus;
use App\Enum\ImportRunStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Orchestrates one import's lifecycle: parse -> map -> validate -> (queue|persist-as-validated) ->
 * execute -> audit. Not a shared Symfony service — each import type builds its own instance with
 * its own ImportDefinitionInterface/ImportValidatorInterface/ImportRowExecutorInterface, the same
 * way ProductImportService or VendorSheetImportService owns its own logic; only the orchestration
 * shape is shared.
 *
 * Two very different call paths, both described in the framework plan (D1, D6):
 *   - Validation Only:  parseAndMap() -> validate() -> persistAsValidationOnly(). All in-request,
 *                        nothing ever queued, no lock needed (nothing is written to the target
 *                        entity, only to the ImportRun/ImportRunRow ledger).
 *   - Real execution:   parseAndMap() -> queueForExecution() (in-request, sets status=queued) ->
 *                        later, only from inside import:process under ImportLock: executeQueued()
 *                        (re-validates per D3, then persists each row via the executor).
 *
 * executeQueued() is the ONLY place ImportRowExecutorInterface::execute() is ever called — there is
 * no synchronous in-request execute path, by design (see D6).
 */
final class ImportRunner
{
    public function __construct(
        private readonly ImportDefinitionInterface $definition,
        private readonly ImportValidatorInterface $validator,
        private readonly ImportRowExecutorInterface $executor,
        private readonly ColumnMapper $columnMapper,
        private readonly CsvRowReader $csvReader,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Parses the file and applies the column mapping. Builds the ImportRun + its rows entirely
     * in-memory — nothing is persisted here, so this is safe to call before deciding whether the
     * run ends up validation-only or queued.
     *
     * @param array<string, string> $columnMapping target field key => chosen header
     */
    public function parseAndMap(UploadedFile $file, array $columnMapping): ImportRun
    {
        $run = new ImportRun($this->definition->name(), $this->definition->entityType());

        $rowNumber = 1;
        foreach ($this->csvReader->rows($file) as $rawRow) {
            $mapped = $this->columnMapper->applyMapping($columnMapping, $rawRow);
            $run->addRow(new ImportRunRow($run, $rowNumber, $rawRow, $mapped));
            ++$rowNumber;
        }

        $run->setRowCount($run->getRows()->count());

        return $run;
    }

    /**
     * A whole-file rejection reason, or null if the file is fine to proceed with — checked when the
     * validator implements ImportWholeRunCheckInterface (most don't; no rows means nothing to
     * check). The caller is expected to call this right after parseAndMap() and refuse to queue (or
     * validation-only-persist) on a non-null result, rather than let per-row validation/execution
     * run against a file this check has already condemned as a whole.
     */
    public function wholeRunError(ImportRun $run): ?string
    {
        if (!$this->validator instanceof ImportWholeRunCheckInterface) {
            return null;
        }

        return $this->validator->checkWholeRun($run);
    }

    /**
     * Validates every row in-memory via ImportValidatorInterface, setting each row's status/error
     * and the run's validated/error counts. Pure — persists nothing; the caller decides what to do
     * with the result (persistAsValidationOnly(), or executeQueued() calling this again per D3).
     */
    public function validate(ImportRun $run): void
    {
        $validatedCount = 0;
        $errorCount = 0;

        foreach ($run->getRows() as $row) {
            $errors = $this->validator->validate($row->getMappedData());
            if ($errors === []) {
                $row->setStatus(ImportRowStatus::Validated);
                $row->setError(null);
                ++$validatedCount;
            } else {
                $row->setStatus(ImportRowStatus::Failed);
                $row->setError(implode('; ', $errors));
                ++$errorCount;
            }
        }

        $run->setValidatedCount($validatedCount);
        $run->setErrorCount($errorCount);
    }

    /**
     * A "Validation Only" run: validate(), then persist straight to status=completed. Never queued
     * — nothing needs the lock, since nothing is ever written to the target entity.
     */
    public function persistAsValidationOnly(ImportRun $run): void
    {
        $this->validate($run);
        $run->setValidationOnly(true);
        $run->setExecutedCount(0);
        $run->setStatus(ImportRunStatus::Completed);
        $run->setFinishedAt(new \DateTimeImmutable());

        $this->em->persist($run);
        $this->em->flush();
    }

    /**
     * A real submission: persist with status=queued. Deliberately does NOT validate here — real
     * validation happens once, inside executeQueued(), under the lock (see D3). The caller (a
     * controller) is expected to spawn `bin/console import:process --detached` right after this
     * returns so the queue starts draining immediately.
     */
    public function queueForExecution(ImportRun $run, string $source = 'ui'): void
    {
        $run->setValidationOnly(false);
        $run->setSource($source);
        $run->setStatus(ImportRunStatus::Queued);
        $run->setQueuedAt(new \DateTimeImmutable());

        $this->em->persist($run);
        $this->em->flush();
    }

    /**
     * Called only from import:process, which already holds the lock (see ImportLock). Re-validates
     * (rules may have drifted since queueing), then persists each still-valid row via the executor.
     *
     * Per D2, one row failing execution does not stop the rest of the file — every validated row is
     * attempted regardless of an earlier row's outcome. Flushes after every row (not just once at
     * the end) so a force-kill mid-run (D9) leaves the already-executed rows' outcomes intact rather
     * than losing them along with the process.
     */
    public function executeQueued(ImportRun $run): void
    {
        $this->validate($run);

        $executedCount = 0;
        $errorCount = $run->getErrorCount();
        $actionsTaken = [];

        foreach ($run->getRows() as $row) {
            if ($row->getStatus() !== ImportRowStatus::Validated) {
                continue;
            }

            try {
                $action = $this->executor->execute($row->getMappedData());
                $row->setAction($action);
                $row->setStatus(ImportRowStatus::Succeeded);
                $actionsTaken[$action->value] = true;
                ++$executedCount;
            } catch (\Throwable $exception) {
                $row->setStatus(ImportRowStatus::Failed);
                $row->setError($exception->getMessage());
                ++$errorCount;
            }

            $this->em->flush();
        }

        if ($this->executor instanceof ImportRunFinalizableInterface) {
            $this->executor->finalize($run);
        }

        $run->setExecutedCount($executedCount);
        $run->setErrorCount($errorCount);
        $run->setAction(implode(',', array_keys($actionsTaken)));
        $this->em->flush();
    }
}
