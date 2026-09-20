<?php

declare(strict_types=1);

namespace App\Tests\Service\ProductImport;

use App\Entity\ImportRun;
use App\Enum\ImportRunStatus;
use App\Repository\ImportColumnMappingRepository;
use App\Service\Import\ColumnMapper;
use App\Service\Import\CsvRowReader;
use App\Service\Import\ImportRunner;
use App\Service\ProductImport\ProductImportDefinition;
use App\Service\ProductImport\ProductImportRowValidator;
use App\Service\ProductImport\ProductImportRunnerFactory;
use App\Service\ProductImport\ProductImportService;
use App\Tests\DoctrineIntegrationTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * #769: a run driven through the real ImportRunner/ProductImportRunnerFactory path, past
 * ProductImportService::CLEAR_BATCH_SIZE (50) rows, must still reach ImportRunStatus::Completed
 * with the right executedCount — not silently stall at Started/0 forever.
 *
 * ## What was wrong
 *
 * ImportRunner owns one `ImportRun` (and its `ImportRunRow`s) on the SAME EntityManager for the
 * whole run, flushing writes to it after every row — including the terminal status write
 * `ImportProcessCommand::runOne()`'s `finally` block makes once `executeQueued()` returns.
 * `ProductImportService::processDataRow()` calls `$entityManager->clear()` every 50th row to bound
 * memory on a large file; this app's Doctrine ORM version has no way to clear only its own
 * entities, so that call detaches ImportRunner's `$run` and every `ImportRunRow` too. Every write to
 * either from that point on — `$row->setStatus()`, `$run->setExecutedCount()`, and the run's
 * eventual `Completed` status — silently never reaches the database, even though each row's own
 * product data keeps being written correctly (which is why the CSV clearly "did something" while
 * the run itself looked stuck at Started/0 executed/Finished N/A forever).
 *
 * This test proves the fix by driving 55 rows — one full batch past the 50-row clear boundary —
 * through the exact same object sequence `import:process` uses (a hand-built ImportRunner for
 * parseAndMap()/queueForExecution(), then a SEPARATE ImportRunner from
 * ProductImportRunnerFactory::create() for executeQueued(), then the same terminal-status write
 * ImportProcessCommand::runOne()'s finally block makes) and reading the run's status back with a
 * fresh, non-identity-mapped query — the only way to catch a write that looked fine in PHP-object
 * land but never reached flush()'s UnitOfWork.
 */
final class ProductImportRunnerClearBoundaryTest extends DoctrineIntegrationTestCase
{
    public function testARunOfFiftyFiveRowsReachesCompletedWithEveryRowExecuted(): void
    {
        $rowCount = 55;

        $path = tempnam(sys_get_temp_dir(), 'clear_boundary_import_');
        $fp = fopen($path, 'w');
        fputcsv($fp, ['sku', 'name']);
        for ($i = 1; $i <= $rowCount; $i++) {
            fputcsv($fp, ['CLR-' . $i, 'Clear Boundary Widget ' . $i]);
        }
        fclose($fp);
        $file = new UploadedFile($path, 'clear-boundary.csv', 'text/csv', null, true);

        $productImportService = self::getContainer()->get(ProductImportService::class);
        $columnMapper = new ColumnMapper($this->em, self::getContainer()->get(ImportColumnMappingRepository::class));
        $csvReader = new CsvRowReader();
        $definition = new ProductImportDefinition($this->em, $productImportService);

        // Step 1, mirroring ProductImportController::confirm(): parse/map and queue.
        $parsingRunner = new ImportRunner($definition, new ProductImportRowValidator('sku'), $productImportService, $columnMapper, $csvReader, $this->em);
        $run = $parsingRunner->parseAndMap($file, ['sku' => 'sku', 'name' => 'name']);
        $run->setContext(['primary_key' => 'sku', 'missing_rows' => 'do_nothing']);
        $parsingRunner->queueForExecution($run, 'test');

        self::assertSame($rowCount, $run->getRowCount(), 'guard: every row was parsed');

        // Step 2, mirroring ImportProcessCommand::runOne(): a fresh ImportRunner off the factory,
        // then the exact same try/finally shape that command uses.
        $factory = self::getContainer()->get(ProductImportRunnerFactory::class);
        $executionRunner = $factory->create($run);

        $run->setStatus(ImportRunStatus::Started);
        $run->setStartedAt(new \DateTimeImmutable());
        $this->em->flush();

        try {
            $executionRunner->executeQueued($run);
        } finally {
            $run->setStatus(ImportRunStatus::Completed);
            $run->setFinishedAt(new \DateTimeImmutable());
            $this->em->flush();
        }

        self::assertSame($rowCount, $run->getExecutedCount(), 'the in-memory object should show every row executed');

        // The real proof: read the row back with a query the identity map cannot satisfy from
        // memory, so a write that never reached flush()'s UnitOfWork shows up as a failure here
        // even though the in-memory assertion above already passed.
        $connection = $this->em->getConnection();
        $row = $connection->fetchAssociative(
            'SELECT status, executed_count, finished_at FROM import_run WHERE id = ?',
            [$run->getId()],
        );

        self::assertIsArray($row, 'guard: the run row exists at all');
        self::assertSame(ImportRunStatus::Completed->value, $row['status'], 'the run is stuck at a non-terminal status in the database');
        self::assertSame($rowCount, (int) $row['executed_count'], 'executed_count never reached the database');
        self::assertNotNull($row['finished_at'], 'finished_at is still NULL in the database');

        self::assertSame($rowCount, (int) $connection->fetchOne("SELECT COUNT(*) FROM product_core WHERE sku LIKE 'CLR-%'"), 'every row\'s own product should still have been created regardless');
    }
}
