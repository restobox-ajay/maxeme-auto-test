<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Contract\Import\ImportDefinitionInterface;
use App\Contract\Import\ImportRowExecutorInterface;
use App\Contract\Import\ImportValidatorInterface;
use App\Enum\ImportAction;
use App\Enum\ImportRowStatus;
use App\Enum\ImportRunStatus;
use App\Model\ImportTargetField;
use App\Repository\ImportColumnMappingRepository;
use App\Service\Import\ColumnMapper;
use App\Service\Import\CsvRowReader;
use App\Service\Import\ImportRunner;
use App\Tests\DoctrineIntegrationTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * End-to-end coverage of the unified import framework's core orchestration, against a real
 * EntityManager (see DoctrineIntegrationTestCase) and a throwaway fake import (definition +
 * validator + executor) rather than a real one, since none is wired up yet — this is what proves
 * ImportRunner itself is correct independent of any concrete import.
 */
final class ImportRunnerTest extends DoctrineIntegrationTestCase
{
    private ImportRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();

        // Built by hand rather than fetched from the container: ColumnMapper/CsvRowReader have no
        // real consumer yet (no concrete import is wired up in this pass — see the framework
        // plan's implementation order), so the compiler prunes them as unused private services.
        // ManagerRegistry (Doctrine's own "doctrine" service, always public) is unaffected.
        $mappingRepository = new ImportColumnMappingRepository(self::getContainer()->get('doctrine'));

        $this->runner = new ImportRunner(
            new FakeImportDefinition(),
            new FakeImportValidator(),
            new FakeImportRowExecutor(),
            new ColumnMapper($this->em, $mappingRepository),
            new CsvRowReader(),
            $this->em,
        );
    }

    /** @param list<list<string>> $rows */
    private function csvUpload(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import_runner_test_');
        $fp = fopen($path, 'w');
        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);

        return new UploadedFile($path, 'fake.csv', 'text/csv', null, true);
    }

    public function testValidationOnlyRunNeverQueuesAndCompletesImmediately(): void
    {
        $csv = $this->csvUpload([
            ['SKU', 'QTY'],
            ['SKU-1', '10'],
            ['', '5'],       // fails validation: sku required
            ['SKU-2', 'abc'], // fails validation: qty must be numeric
        ]);

        $run = $this->runner->parseAndMap($csv, ['sku' => 'SKU', 'qty' => 'QTY']);
        $this->runner->persistAsValidationOnly($run);

        self::assertNotNull($run->getId());
        self::assertTrue($run->isValidationOnly());
        self::assertSame(ImportRunStatus::Completed, $run->getStatus());
        self::assertNull($run->getQueuedAt(), 'validation-only run must never queue');
        self::assertSame(3, $run->getRowCount());
        self::assertSame(1, $run->getValidatedCount());
        self::assertSame(2, $run->getErrorCount());
        self::assertSame(0, $run->getExecutedCount(), 'validation-only must never execute');

        $rows = $run->getRows();
        self::assertSame(ImportRowStatus::Validated, $rows[0]->getStatus());
        self::assertSame(ImportRowStatus::Failed, $rows[1]->getStatus());
        self::assertStringContainsString('sku required', (string) $rows[1]->getError());
        self::assertSame(ImportRowStatus::Failed, $rows[2]->getStatus());
        self::assertStringContainsString('qty must be numeric', (string) $rows[2]->getError());
    }

    public function testQueueForExecutionPersistsAsQueuedWithoutRunningTheExecutor(): void
    {
        $csv = $this->csvUpload([
            ['SKU', 'QTY'],
            ['SKU-1', '10'],
        ]);

        $run = $this->runner->parseAndMap($csv, ['sku' => 'SKU', 'qty' => 'QTY']);
        $this->runner->queueForExecution($run, 'ui');

        self::assertNotNull($run->getId());
        self::assertFalse($run->isValidationOnly());
        self::assertSame(ImportRunStatus::Queued, $run->getStatus());
        self::assertNotNull($run->getQueuedAt());
        self::assertSame('ui', $run->getSource());
        self::assertSame(0, $run->getExecutedCount(), 'queueing alone must never execute a row');
    }

    public function testExecuteQueuedRunsEveryValidatedRowEvenWhenOneRowFails(): void
    {
        // Mirrors D2: one row's executor failure must not stop the rest of the file.
        $csv = $this->csvUpload([
            ['SKU', 'QTY'],
            ['SKU-1', '10'],
            ['FAIL-ME', '5'],
            ['SKU-3', '7'],
            ['', '1'], // fails validation, never reaches the executor at all
        ]);

        $run = $this->runner->parseAndMap($csv, ['sku' => 'SKU', 'qty' => 'QTY']);
        $this->runner->queueForExecution($run);

        // Simulates what import:process does once it picks the run up under the lock.
        $this->runner->executeQueued($run);

        self::assertSame(4, $run->getRowCount());
        self::assertSame(2, $run->getExecutedCount(), 'SKU-1 and SKU-3 should have executed');
        self::assertSame(2, $run->getErrorCount(), 'FAIL-ME (executor) + blank sku (validation)');
        self::assertSame('append', $run->getAction());

        $rows = $run->getRows();
        self::assertSame(ImportRowStatus::Succeeded, $rows[0]->getStatus());
        self::assertSame(ImportAction::Append, $rows[0]->getAction());

        self::assertSame(ImportRowStatus::Failed, $rows[1]->getStatus());
        self::assertStringContainsString('Simulated executor failure', (string) $rows[1]->getError());

        self::assertSame(ImportRowStatus::Succeeded, $rows[2]->getStatus(), 'the row after the failure must still run');

        self::assertSame(ImportRowStatus::Failed, $rows[3]->getStatus());
        self::assertStringContainsString('sku required', (string) $rows[3]->getError());
    }
}

final class FakeImportDefinition implements ImportDefinitionInterface
{
    public function name(): string { return 'fake_import'; }
    public function label(): string { return 'Fake Import'; }
    public function entityType(): string { return 'FakeEntity'; }

    /** @return list<ImportTargetField> */
    public function targetFields(): array
    {
        return [
            new ImportTargetField('sku', 'SKU'),
            new ImportTargetField('qty', 'Quantity'),
        ];
    }

    /** @return list<ImportAction> */
    public function supportedActions(): array { return [ImportAction::Append, ImportAction::Update]; }
    public function supportsValidationOnly(): bool { return true; }
}

final class FakeImportValidator implements ImportValidatorInterface
{
    public function validate(array $mappedData): array
    {
        $errors = [];
        if (empty($mappedData['sku'])) {
            $errors[] = 'sku required';
        }
        if (!is_numeric($mappedData['qty'] ?? null)) {
            $errors[] = 'qty must be numeric';
        }

        return $errors;
    }
}

final class FakeImportRowExecutor implements ImportRowExecutorInterface
{
    public function execute(array $mappedData): ImportAction
    {
        if (($mappedData['sku'] ?? null) === 'FAIL-ME') {
            throw new \RuntimeException('Simulated executor failure for ' . $mappedData['sku']);
        }

        return ImportAction::Append;
    }
}
