<?php

declare(strict_types=1);

namespace App\Tests\Service\ProductImport;

use App\Entity\ImportRun;
use App\Entity\UnitOfMeasure;
use App\Enum\ImportRunStatus;
use App\Service\Import\ColumnMapper;
use App\Service\Import\CsvRowReader;
use App\Service\Import\ImportRunner;
use App\Service\ProductImport\ProductImportDefinition;
use App\Service\ProductImport\ProductImportRowValidator;
use App\Service\ProductImport\ProductImportService;
use App\Tests\DoctrineIntegrationTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\Process;

/**
 * The other half of ImportUnitIsNotAPackSizeCest's "the person running it is told... cannot hide
 * inside a green result" requirement (#624) — the half that only runs through a real queue +
 * import:process, which needs DoctrineIntegrationTestCase (persists for real; see that class's own
 * docblock and tests/Command/ImportProcessCommandTest.php, which this mirrors) rather than Codeception.
 *
 * Proves ProductImportService::finalize() — added when this importer moved onto the unified
 * framework — actually appends the unitFlagged count onto ImportRun::description via the real
 * ImportRunner -> queue -> import:process -> ProductImportRunnerFactory path, not just in the
 * unit-level plumbing.
 */
final class ProductImportUnitFlaggedSurfacesOnTheUnifiedRunTest extends DoctrineIntegrationTestCase
{
    protected function tearDown(): void
    {
        @unlink('/tmp/wholesale-imports.lock');
        parent::tearDown();
    }

    private function seedUnits(): void
    {
        foreach (['EA' => 'Each', 'BOX' => 'Box'] as $code => $name) {
            $this->em->persist(
                (new UnitOfMeasure())
                    ->setCode($code)
                    ->setName($name)
                    ->setFamily(UnitOfMeasure::FAMILY_QUANTITY)
                    ->setFactorToFamilyBase('1')
                    ->setRoundingPrecision('1'),
            );
        }
        $this->em->flush();
    }

    /** @param list<list<string>> $rows */
    private function csvUpload(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'unit_flag_unified_') . '.csv';
        $fp = fopen($path, 'w');
        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);

        return new UploadedFile($path, 'products.csv', 'text/csv', null, true);
    }

    public function testUnitFlaggedCountAppearsInTheQueuedRunsDescriptionAfterARealDrain(): void
    {
        $this->seedUnits();

        $csv = $this->csvUpload([
            ['sku', 'name', 'status', 'unit'],
            ['UNIFIED-UNIT-1', 'Good Row', 'Active', 'EA'],
            ['UNIFIED-UNIT-2', 'Pack Size Row', 'Active', '12/Case'],
        ]);

        $definition = new ProductImportDefinition($this->em, self::getContainer()->get(ProductImportService::class));
        $runner = new ImportRunner(
            $definition,
            new ProductImportRowValidator('sku'),
            self::getContainer()->get(ProductImportService::class),
            new ColumnMapper($this->em, new \App\Repository\ImportColumnMappingRepository(self::getContainer()->get('doctrine'))),
            new CsvRowReader(),
            $this->em,
        );

        $run = $runner->parseAndMap($csv, ['sku' => 'sku', 'name' => 'name', 'status' => 'status', 'unit' => 'unit']);
        $run->setDescription('Source: products.csv');
        $run->setContext(['primary_key' => 'sku', 'missing_rows' => 'do_nothing']);
        $runner->queueForExecution($run, 'ui');

        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $process = new Process(
            ['php', $projectDir . '/bin/console', 'import:process', '--env=test', '--no-interaction'],
            $projectDir,
        );
        $process->run();

        $this->em->clear();
        $finished = $this->em->find(ImportRun::class, $run->getId());
        self::assertSame(ImportRunStatus::Completed, $finished->getStatus());
        self::assertSame(2, $finished->getExecutedCount());
        self::assertStringContainsString('1 unit(s) flagged for review', $finished->getDescription());
    }
}
