<?php

declare(strict_types=1);

namespace App\Tests\Support\Fixtures;

use App\Contract\Import\ImportRunnerFactoryInterface;
use App\Entity\ImportRun;
use App\Service\Import\ColumnMapper;
use App\Service\Import\CsvRowReader;
use App\Service\Import\ImportRunner;
use Doctrine\ORM\EntityManagerInterface;

final class TestImportRunnerFactory implements ImportRunnerFactoryInterface
{
    public function __construct(
        private readonly ColumnMapper $columnMapper,
        private readonly CsvRowReader $csvReader,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function importType(): string { return TestImportDefinition::NAME; }

    public function create(ImportRun $run): ImportRunner
    {
        return new ImportRunner(
            new TestImportDefinition(),
            new TestImportValidator(),
            new TestImportRowExecutor(),
            $this->columnMapper,
            $this->csvReader,
            $this->em,
        );
    }
}
