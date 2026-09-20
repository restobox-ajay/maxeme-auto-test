<?php

declare(strict_types=1);

namespace App\Service\ProductImport;

use App\Contract\Import\ImportRunnerFactoryInterface;
use App\Entity\ImportRun;
use App\Service\Import\ColumnMapper;
use App\Service\Import\CsvRowReader;
use App\Service\Import\ImportRunner;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Registered as `app.import_runner_factory` (see config/services.yaml) so import:process can find
 * this without core needing a special case — same seam every other import type uses.
 */
final class ProductImportRunnerFactory implements ImportRunnerFactoryInterface
{
    public function __construct(
        private readonly ProductImportService $productImportService,
        private readonly ColumnMapper $columnMapper,
        private readonly CsvRowReader $csvReader,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function importType(): string { return ProductImportDefinition::NAME; }

    public function create(ImportRun $run): ImportRunner
    {
        $context = $run->getContext();
        $primaryKey = ($context['primary_key'] ?? 'sku') === 'id' ? 'id' : 'sku';

        // Prepares $productImportService's per-run state (lookups, sync source, importedKeys,
        // ...) before ImportRunner starts calling execute() per row — see
        // ProductImportService::beginRun()'s own docblock.
        $this->productImportService->beginRun($context, $this->em);

        return new ImportRunner(
            new ProductImportDefinition($this->em, $this->productImportService),
            new ProductImportRowValidator($primaryKey),
            $this->productImportService,
            $this->columnMapper,
            $this->csvReader,
            $this->em,
        );
    }
}
