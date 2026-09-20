<?php

declare(strict_types=1);

namespace ProcurementBundle\Import;

use App\Contract\Import\ImportRunnerFactoryInterface;
use App\Entity\ImportRun;
use App\Repository\ProductCategoryRepository;
use App\Service\Import\ColumnMapper;
use App\Service\Import\CsvRowReader;
use App\Service\Import\ImportRunner;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Repository\UnmatchedVendorSkuRepository;
use ProcurementBundle\VendorPricing\VendorPriceUpserter;

/**
 * Registered as `app.import_runner_factory` in this bundle's own services.yaml — core never
 * references this class; it finds it through the tag, same seam every other provider interface in
 * this app uses to let a bundle supply something core drives (see ImportRunnerFactoryInterface's
 * own docblock).
 */
final class VendorSheetImportRunnerFactory implements ImportRunnerFactoryInterface
{
    public function __construct(
        private readonly VendorSkuResolver $resolver,
        private readonly UnmatchedVendorSkuRepository $unmatched,
        private readonly ProductCategoryRepository $categories,
        private readonly ColumnMapper $columnMapper,
        private readonly CsvRowReader $csvReader,
        private readonly EntityManagerInterface $em,
        private readonly VendorPriceUpserter $upserter,
    ) {
    }

    public function importType(): string { return VendorSheetImportDefinition::NAME; }

    public function create(ImportRun $run): ImportRunner
    {
        $vendorId = (int) ($run->getContext()['vendor_id'] ?? 0);
        $vendor = $vendorId > 0 ? $this->em->find(Vendor::class, $vendorId) : null;
        if (!$vendor instanceof Vendor) {
            throw new \RuntimeException("Vendor #{$vendorId} not found for this import run.");
        }

        $context = $run->getContext();
        $executor = (new VendorSheetImportRowExecutor($this->resolver, $this->unmatched, $this->categories, $this->em, $this->upserter))
            ->forVendor($vendor)
            ->forOptions(
                (bool) ($context['allow_add'] ?? false),
                (bool) ($context['allow_update'] ?? true),
                (bool) ($context['allow_delete'] ?? false),
            );

        return new ImportRunner(
            new VendorSheetImportDefinition(),
            new VendorSheetImportValidator(),
            $executor,
            $this->columnMapper,
            $this->csvReader,
            $this->em,
        );
    }
}
