<?php

declare(strict_types=1);

namespace ProcurementBundle\Tests\Import;

use App\Entity\ImportRun;
use App\Entity\ProductCategory;
use App\Entity\ProductCore;
use App\Enum\ImportRunStatus;
use App\Repository\ProductCategoryRepository;
use App\Service\Import\ColumnMapper;
use App\Service\Import\CsvRowReader;
use App\Service\Import\ImportRunner;
use App\Tests\DoctrineIntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use ProcurementBundle\Entity\UnmatchedVendorSku;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorPrice;
use ProcurementBundle\Import\VendorSheetImportDefinition;
use ProcurementBundle\Import\VendorSheetImportRowExecutor;
use ProcurementBundle\Import\VendorSheetImportValidator;
use ProcurementBundle\Import\VendorSkuResolver;
use ProcurementBundle\Repository\UnmatchedVendorSkuRepository;
use ProcurementBundle\VendorPricing\VendorPriceUpserter;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\Process;

/**
 * Proves the vendor sheet importer (docs/plans/2026-09-15-vendor-sheet-and-po-csv-import.md, §2)
 * end to end through the REAL queue + a real `import:process` child process — same reasoning and
 * same pattern as tests/Command/ImportProcessCommandTest.php and
 * ProductImportUnitFlaggedSurfacesOnTheUnifiedRunTest: a spawned child opens its own connection and
 * cannot see a rolled-back Codeception transaction, so this needs DoctrineIntegrationTestCase
 * (persists for real).
 *
 * One file exercises all three per-row branches at once, mirroring a real mixed sheet:
 *  - our_sku present, no existing VendorPrice -> created (Append)
 *  - our_sku blank, vendor_sku matches an existing VendorPrice -> refreshed (Update)
 *  - our_sku blank, vendor_sku matches nothing -> UnmatchedVendorSku (Append)
 */
final class VendorSheetImportEndToEndTest extends DoctrineIntegrationTestCase
{
    protected function tearDown(): void
    {
        @unlink('/tmp/wholesale-imports.lock');
        parent::tearDown();
    }

    private function makeVendor(string $name): Vendor
    {
        $vendor = (new Vendor())->setName($name)->setStatus('Active')->setCurrency('CAD');
        $this->em->persist($vendor);
        $this->em->flush();

        return $vendor;
    }

    private function makeProduct(string $sku): ProductCore
    {
        $product = (new ProductCore())->setSku($sku)->setName('Product ' . $sku)->activate();
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    /** @param list<list<string>> $rows */
    private function csvUpload(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'vendor_sheet_e2e_') . '.csv';
        $fp = fopen($path, 'w');
        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);

        return new UploadedFile($path, 'vendor-sheet.csv', 'text/csv', null, true);
    }

    /**
     * Queues and REALLY drains one run through a spawned `import:process` child, the way every
     * test in this file does — factored out once three tests below needed the identical dozen
     * lines with only the context (allow_add/allow_update/allow_delete) differing.
     *
     * @param array<string, string> $columnMap
     * @param array<string, bool>   $context   allow_add/allow_update/allow_delete — read by the REAL
     *                                         VendorSheetImportRunnerFactory the spawned child uses,
     *                                         not by anything in this method or the throwaway
     *                                         parseAndMap()-only executor other tests build by hand.
     */
    private function runViaRealQueue(Vendor $vendor, UploadedFile $csv, array $columnMap, array $context = []): ImportRun
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $resolver = self::getContainer()->get(VendorSkuResolver::class);
        $unmatchedRepo = self::getContainer()->get(UnmatchedVendorSkuRepository::class);
        $categoryRepo = self::getContainer()->get(ProductCategoryRepository::class);
        $upserter = self::getContainer()->get(VendorPriceUpserter::class);
        $columnMapper = new ColumnMapper($em, self::getContainer()->get(\App\Repository\ImportColumnMappingRepository::class));

        $runner = new ImportRunner(
            new VendorSheetImportDefinition(),
            new VendorSheetImportValidator(),
            new VendorSheetImportRowExecutor($resolver, $unmatchedRepo, $categoryRepo, $em, $upserter),
            $columnMapper,
            new CsvRowReader(),
            $em,
        );

        $run = $runner->parseAndMap($csv, $columnMap);
        $run->setDescription('Vendor: ' . $vendor->getName());
        $run->setContext(array_merge(['vendor_id' => $vendor->getId()], $context));
        $runner->queueForExecution($run, 'ui');

        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $process = new Process(['php', $projectDir . '/bin/console', 'import:process', '--env=test', '--no-interaction'], $projectDir);
        $process->run();
        self::assertSame('', $process->getErrorOutput(), 'import:process wrote to stderr: ' . $process->getErrorOutput());

        $this->em->clear();
        $finished = $this->em->find(ImportRun::class, $run->getId());
        self::assertInstanceOf(ImportRun::class, $finished);

        return $finished;
    }

    public function testMixedSheetCreatesRefreshesAndFlagsAcrossARealDrain(): void
    {
        $vendor = $this->makeVendor('Acme Supply Co');
        $existingProduct = $this->makeProduct('OURSKU-EXISTING');
        $newProduct = $this->makeProduct('OURSKU-NEW');

        // Pre-existing VendorPrice this run should refresh, not duplicate.
        $existingPrice = (new VendorPrice())
            ->setVendor($vendor)
            ->setProduct($existingProduct)
            ->setVendorSku('VSKU-REFRESH')
            ->setUnitCost('10.0000');
        $this->em->persist($existingPrice);
        $this->em->flush();

        $csv = $this->csvUpload([
            ['vendor_sku', 'our_sku', 'vendor_price', 'vendor_quantity', 'vendor_name'],
            ['VSKU-NEW', 'OURSKU-NEW', '25.50', '100', 'Widget Deluxe'],
            ['VSKU-REFRESH', '', '12.75', '50', 'Refreshed Item'],
            ['VSKU-UNKNOWN', '', '5.00', '10', 'Mystery Item'],
        ]);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $resolver = self::getContainer()->get(VendorSkuResolver::class);
        $unmatchedRepo = self::getContainer()->get(UnmatchedVendorSkuRepository::class);
        $categoryRepo = self::getContainer()->get(ProductCategoryRepository::class);
        $upserter = self::getContainer()->get(VendorPriceUpserter::class);
        $columnMapper = new ColumnMapper($em, self::getContainer()->get(\App\Repository\ImportColumnMappingRepository::class));

        $runner = new ImportRunner(
            new VendorSheetImportDefinition(),
            new VendorSheetImportValidator(),
            new VendorSheetImportRowExecutor($resolver, $unmatchedRepo, $categoryRepo, $em, $upserter),
            $columnMapper,
            new CsvRowReader(),
            $em,
        );

        $run = $runner->parseAndMap($csv, [
            'vendor_sku' => 'vendor_sku',
            'our_sku' => 'our_sku',
            'vendor_price' => 'vendor_price',
            'vendor_quantity' => 'vendor_quantity',
            'vendor_name' => 'vendor_name',
        ]);
        $run->setDescription('Vendor: ' . $vendor->getName());
        $run->setContext(['vendor_id' => $vendor->getId()]);
        $runner->queueForExecution($run, 'ui');

        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $process = new Process(
            ['php', $projectDir . '/bin/console', 'import:process', '--env=test', '--no-interaction'],
            $projectDir,
        );
        $process->run();

        self::assertSame('', $process->getErrorOutput(), 'import:process wrote to stderr: ' . $process->getErrorOutput());

        $this->em->clear();
        $finishedRun = $this->em->find(ImportRun::class, $run->getId());
        self::assertSame(ImportRunStatus::Completed, $finishedRun->getStatus());
        self::assertSame(3, $finishedRun->getRowCount());
        self::assertSame(3, $finishedRun->getExecutedCount(), 'all three rows write SOMETHING (VendorPrice or UnmatchedVendorSku), zero errors');
        self::assertSame(0, $finishedRun->getErrorCount());

        // Row 1: our_sku present, no existing price -> created.
        $newPrice = $this->em->getRepository(VendorPrice::class)->findOneBy(['vendor' => $vendor, 'product' => $newProduct]);
        self::assertInstanceOf(VendorPrice::class, $newPrice);
        self::assertSame('VSKU-NEW', $newPrice->getVendorSku());
        self::assertEqualsWithDelta(25.5, (float) $newPrice->getUnitCost(), 0.0001, 'unit cost round-trips numerically (SQLite does not pad decimal-string trailing zeros)');
        self::assertEqualsWithDelta(100.0, (float) $newPrice->getAvailableQuantity(), 0.0001, 'available_quantity is the quantity type (NUMERIC(14,4), #601), read back as a decimal string');
        self::assertSame('Widget Deluxe', $newPrice->getVendorItemName());

        // Row 2: our_sku blank, vendor_sku matches existing -> refreshed in place, not duplicated.
        // (The earlier em->clear() detached $existingPrice, hence a fresh find() rather than
        // refresh() — refresh() requires a still-managed entity.)
        $reloadedExistingPrice = $this->em->find(VendorPrice::class, $existingPrice->getId());
        self::assertEqualsWithDelta(12.75, (float) $reloadedExistingPrice->getUnitCost(), 0.0001);
        self::assertEqualsWithDelta(50.0, (float) $reloadedExistingPrice->getAvailableQuantity(), 0.0001);
        self::assertSame('Refreshed Item', $reloadedExistingPrice->getVendorItemName());
        self::assertSame(
            1,
            (int) $this->em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM vendor_price WHERE vendor_id = ? AND vendor_sku = ?',
                [$vendor->getId(), 'VSKU-REFRESH'],
            ),
            'refreshing must never create a second row for the same vendor_sku',
        );

        // Row 3: our_sku blank, vendor_sku matches nothing -> flagged, never silently dropped.
        $unmatched = $this->em->getRepository(UnmatchedVendorSku::class)->findOneBy(['vendor' => $vendor, 'vendorSku' => 'VSKU-UNKNOWN']);
        self::assertInstanceOf(UnmatchedVendorSku::class, $unmatched);
        self::assertTrue($unmatched->isPending());
        self::assertSame('Mystery Item', $unmatched->getLastSeenName());
        self::assertEqualsWithDelta(10.0, (float) $unmatched->getLastSeenQuantity(), 0.0001);
    }

    public function testAnOurSkuRowLaterResolvesAPendingUnmatchedSkuForTheSameVendorSku(): void
    {
        $vendor = $this->makeVendor('Beta Distribution');
        $product = $this->makeProduct('OURSKU-LATE-LINK');

        // First sheet: vendor_sku with no our_sku -> flagged pending.
        $firstCsv = $this->csvUpload([
            ['vendor_sku', 'our_sku', 'vendor_price'],
            ['VSKU-LATE', '', '9.99'],
        ]);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $resolver = self::getContainer()->get(VendorSkuResolver::class);
        $unmatchedRepo = self::getContainer()->get(UnmatchedVendorSkuRepository::class);
        $categoryRepo = self::getContainer()->get(ProductCategoryRepository::class);
        $upserter = self::getContainer()->get(VendorPriceUpserter::class);
        $columnMapper = new ColumnMapper($em, self::getContainer()->get(\App\Repository\ImportColumnMappingRepository::class));

        $runner1 = new ImportRunner(
            new VendorSheetImportDefinition(),
            new VendorSheetImportValidator(),
            new VendorSheetImportRowExecutor($resolver, $unmatchedRepo, $categoryRepo, $em, $upserter),
            $columnMapper,
            new CsvRowReader(),
            $em,
        );
        $run1 = $runner1->parseAndMap($firstCsv, ['vendor_sku' => 'vendor_sku', 'our_sku' => 'our_sku', 'vendor_price' => 'vendor_price']);
        $run1->setContext(['vendor_id' => $vendor->getId()]);
        $runner1->queueForExecution($run1);

        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        (new Process(['php', $projectDir . '/bin/console', 'import:process', '--env=test', '--no-interaction'], $projectDir))->run();

        $this->em->clear();
        $pending = $this->em->getRepository(UnmatchedVendorSku::class)->findOneBy(['vendor' => $vendor, 'vendorSku' => 'VSKU-LATE']);
        self::assertInstanceOf(UnmatchedVendorSku::class, $pending);
        self::assertTrue($pending->isPending());

        // Second sheet: same vendor_sku, now WITH our_sku -> resolves the pending row.
        $secondCsv = $this->csvUpload([
            ['vendor_sku', 'our_sku', 'vendor_price'],
            ['VSKU-LATE', 'OURSKU-LATE-LINK', '9.99'],
        ]);

        $runner2 = new ImportRunner(
            new VendorSheetImportDefinition(),
            new VendorSheetImportValidator(),
            new VendorSheetImportRowExecutor($resolver, $unmatchedRepo, $categoryRepo, $em, $upserter),
            $columnMapper,
            new CsvRowReader(),
            $em,
        );
        $run2 = $runner2->parseAndMap($secondCsv, ['vendor_sku' => 'vendor_sku', 'our_sku' => 'our_sku', 'vendor_price' => 'vendor_price']);
        $run2->setContext(['vendor_id' => $vendor->getId()]);
        $runner2->queueForExecution($run2);

        (new Process(['php', $projectDir . '/bin/console', 'import:process', '--env=test', '--no-interaction'], $projectDir))->run();

        $this->em->clear();
        $resolved = $this->em->getRepository(UnmatchedVendorSku::class)->find($pending->getId());
        self::assertSame(UnmatchedVendorSku::STATUS_RESOLVED, $resolved->getStatus());
        self::assertSame($product->getId(), $resolved->getResolvedProduct()?->getId());
    }

    private function makeCategory(string $name, ?ProductCategory $parent = null): ProductCategory
    {
        $category = (new ProductCategory())->setName($name)->setParent($parent);
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    /**
     * Category 1/2 (App\Entity\ProductCategory, a real parent/child tree) are lookup-only,
     * optional target fields — an admin maps them when the vendor sheet carries a category path
     * that already exists in the catalog. Two rows in one mapped sheet cover the resolution rules
     * in VendorSheetImportRowExecutor::resolveCategory(): both levels match, and only the leaf is
     * unresolvable (falls back to the parent that DID match). Column mapping applies to the whole
     * file, not per row, so "the columns aren't mapped at all" is a second, separate run below.
     */
    public function testCategory1And2MapToTheProductsCategoryWhenMapped(): void
    {
        $vendor = $this->makeVendor('Category Sheet Vendor');
        $plumbing = $this->makeCategory('Plumbing');
        $this->makeCategory('Plumbing Fittings', $plumbing);
        $matchedProduct = $this->makeProduct('OURSKU-CATEGORY-MATCH');
        $fallbackProduct = $this->makeProduct('OURSKU-CATEGORY-FALLBACK');

        $csv = $this->csvUpload([
            ['our_sku', 'vendor_price', 'cat1', 'cat2'],
            // Row 1: both levels resolve -> product gets the leaf, Plumbing Fittings.
            ['OURSKU-CATEGORY-MATCH', '9.99', 'Plumbing', 'Plumbing Fittings'],
            // Row 2: Category 1 resolves, Category 2 does not -> falls back to Plumbing itself.
            ['OURSKU-CATEGORY-FALLBACK', '9.99', 'Plumbing', 'No Such Sub-Category'],
        ]);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $resolver = self::getContainer()->get(VendorSkuResolver::class);
        $unmatchedRepo = self::getContainer()->get(UnmatchedVendorSkuRepository::class);
        $categoryRepo = self::getContainer()->get(ProductCategoryRepository::class);
        $upserter = self::getContainer()->get(VendorPriceUpserter::class);
        $columnMapper = new ColumnMapper($em, self::getContainer()->get(\App\Repository\ImportColumnMappingRepository::class));

        $runner = new ImportRunner(
            new VendorSheetImportDefinition(),
            new VendorSheetImportValidator(),
            new VendorSheetImportRowExecutor($resolver, $unmatchedRepo, $categoryRepo, $em, $upserter),
            $columnMapper,
            new CsvRowReader(),
            $em,
        );

        $run = $runner->parseAndMap($csv, [
            'our_sku' => 'our_sku',
            'vendor_price' => 'vendor_price',
            'vendor_category1' => 'cat1',
            'vendor_category2' => 'cat2',
        ]);
        $run->setContext(['vendor_id' => $vendor->getId()]);
        $runner->queueForExecution($run);

        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $process = new Process(['php', $projectDir . '/bin/console', 'import:process', '--env=test', '--no-interaction'], $projectDir);
        $process->run();
        self::assertSame('', $process->getErrorOutput(), 'import:process wrote to stderr: ' . $process->getErrorOutput());

        $this->em->clear();

        $reloadedMatched = $this->em->find(ProductCore::class, $matchedProduct->getId());
        self::assertSame('Plumbing Fittings', $reloadedMatched->getCategory()?->getName());

        $reloadedFallback = $this->em->find(ProductCore::class, $fallbackProduct->getId());
        self::assertSame('Plumbing', $reloadedFallback->getCategory()?->getName(), 'an unresolved Category 2 falls back to the Category 1 that DID match');
    }

    public function testCategoryIsUntouchedWhenTheColumnsAreNotMapped(): void
    {
        $vendor = $this->makeVendor('No Category Mapping Vendor');
        $this->makeCategory('Plumbing');
        $product = $this->makeProduct('OURSKU-NO-CATEGORY-MAPPING');

        $csv = $this->csvUpload([
            ['our_sku', 'vendor_price', 'cat1'],
            ['OURSKU-NO-CATEGORY-MAPPING', '9.99', 'Plumbing'],
        ]);

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $resolver = self::getContainer()->get(VendorSkuResolver::class);
        $unmatchedRepo = self::getContainer()->get(UnmatchedVendorSkuRepository::class);
        $categoryRepo = self::getContainer()->get(ProductCategoryRepository::class);
        $upserter = self::getContainer()->get(VendorPriceUpserter::class);
        $columnMapper = new ColumnMapper($em, self::getContainer()->get(\App\Repository\ImportColumnMappingRepository::class));

        $runner = new ImportRunner(
            new VendorSheetImportDefinition(),
            new VendorSheetImportValidator(),
            new VendorSheetImportRowExecutor($resolver, $unmatchedRepo, $categoryRepo, $em, $upserter),
            $columnMapper,
            new CsvRowReader(),
            $em,
        );

        // 'cat1' exists in the file but is deliberately left out of the mapping — this vendor's
        // sheet convention doesn't use it for categorization.
        $run = $runner->parseAndMap($csv, ['our_sku' => 'our_sku', 'vendor_price' => 'vendor_price']);
        $run->setContext(['vendor_id' => $vendor->getId()]);
        $runner->queueForExecution($run);

        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $process = new Process(['php', $projectDir . '/bin/console', 'import:process', '--env=test', '--no-interaction'], $projectDir);
        $process->run();
        self::assertSame('', $process->getErrorOutput(), 'import:process wrote to stderr: ' . $process->getErrorOutput());

        $this->em->clear();
        $reloaded = $this->em->find(ProductCore::class, $product->getId());
        self::assertNull($reloaded->getCategory());
    }

    // ---------------------------------------------------------------------------------------
    // Add / Update / Delete switches (2026-09-21).
    // ---------------------------------------------------------------------------------------

    public function testAddOffRefusesAnUnmatchedOurSkuExactlyAsBefore(): void
    {
        $vendor = $this->makeVendor('Add Off Vendor');

        $csv = $this->csvUpload([
            ['our_sku', 'vendor_price'],
            ['NEVER-SEEN-SKU', '9.99'],
        ]);

        $run = $this->runViaRealQueue($vendor, $csv, ['our_sku' => 'our_sku', 'vendor_price' => 'vendor_price']);

        self::assertSame(1, $run->getErrorCount(), 'the default — add off — must still refuse, not silently skip or create');
        self::assertNull($this->em->getRepository(ProductCore::class)->findOneBy(['sku' => 'NEVER-SEEN-SKU']), 'and definitely must not create anything');
    }

    public function testAddOnCreatesANewProductAndPricesItInOneRun(): void
    {
        $vendor = $this->makeVendor('Add On Vendor');

        $csv = $this->csvUpload([
            ['our_sku', 'vendor_sku', 'vendor_price', 'vendor_quantity', 'vendor_name'],
            ['NEW-FROM-SHEET', 'VSKU-NEW-1', '14.50', '30', 'Widget Never Seen Before'],
        ]);

        $run = $this->runViaRealQueue(
            $vendor,
            $csv,
            ['our_sku' => 'our_sku', 'vendor_sku' => 'vendor_sku', 'vendor_price' => 'vendor_price', 'vendor_quantity' => 'vendor_quantity', 'vendor_name' => 'vendor_name'],
            ['allow_add' => true],
        );

        self::assertSame(0, $run->getErrorCount());
        self::assertSame(1, $run->getExecutedCount());

        $product = $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => 'NEW-FROM-SHEET']);
        self::assertInstanceOf(ProductCore::class, $product, 'add=on must create the product ADD alone is for');
        self::assertSame('Widget Never Seen Before', $product->getName(), 'named from vendor_name — a bare sku is a worse product than none of this test asks for');
        self::assertSame('Active', $product->getStatus());

        $price = $this->em->getRepository(VendorPrice::class)->findOneBy(['vendor' => $vendor, 'product' => $product]);
        self::assertInstanceOf(VendorPrice::class, $price, 'and price it in the SAME run, not leave a bare product with nothing to sell it at');
        self::assertEqualsWithDelta(14.50, (float) $price->getUnitCost(), 0.0001);
        self::assertSame('VSKU-NEW-1', $price->getVendorSku());
    }

    /**
     * Adversarial: our_sku BLANK, vendor_sku unmatched — add=on must NOT invent a product with no
     * sku to create it under. This is exactly the case VendorSkuResolver routes to isUnmatched,
     * never to a failure add=on could intercept, and the worklist stays the only path for it.
     */
    public function testAddOnNeverCreatesAProductForAVendorSkuOnlyRow(): void
    {
        $vendor = $this->makeVendor('Add On No Our Sku Vendor');

        $csv = $this->csvUpload([
            ['vendor_sku', 'vendor_price'],
            ['VSKU-NO-OUR-SKU', '9.99'],
        ]);

        $run = $this->runViaRealQueue($vendor, $csv, ['vendor_sku' => 'vendor_sku', 'vendor_price' => 'vendor_price'], ['allow_add' => true]);

        self::assertSame(0, $run->getErrorCount(), 'unmatched, not an error — the worklist case, unaffected by add');
        self::assertCount(0, $this->em->getRepository(ProductCore::class)->findBy(['syncSource' => ProductCore::SYNC_SOURCE_MANUAL]), 'nothing was created — there was no our_sku to create it under');
        $unmatched = $this->em->getRepository(UnmatchedVendorSku::class)->findOneBy(['vendor' => $vendor, 'vendorSku' => 'VSKU-NO-OUR-SKU']);
        self::assertInstanceOf(UnmatchedVendorSku::class, $unmatched, 'still flagged to the worklist, exactly as add=off would have');
    }

    public function testUpdateOffRefusesToTouchAnAlreadyExistingProductsPrice(): void
    {
        $vendor = $this->makeVendor('Update Off Vendor');
        $product = $this->makeProduct('UPDATE-OFF-SKU');
        $existing = (new VendorPrice())->setVendor($vendor)->setProduct($product)->setVendorSku('VSKU-STABLE')->setUnitCost('5.0000');
        $this->em->persist($existing);
        $this->em->flush();

        $csv = $this->csvUpload([
            ['our_sku', 'vendor_price'],
            ['UPDATE-OFF-SKU', '999.99'],
        ]);

        $run = $this->runViaRealQueue(
            $vendor,
            $csv,
            ['our_sku' => 'our_sku', 'vendor_price' => 'vendor_price'],
            ['allow_update' => false],
        );

        self::assertSame(1, $run->getErrorCount(), 'update=off must refuse a row that resolves to a product already priced, not silently no-op it as a success');

        $this->em->clear();
        $reloaded = $this->em->find(VendorPrice::class, $existing->getId());
        self::assertEqualsWithDelta(5.0, (float) $reloaded->getUnitCost(), 0.0001, 'the existing price must be exactly what it was — nothing update=off touches gets touched');
    }

    /**
     * Adversarial: update=off must still let ADD's own brand-new product through in the SAME run
     * — creating a price for a product this run just made is not "updating" a price that existed
     * before the run started, and refusing it would make add=on+update=off unable to ever price
     * what it just created.
     */
    public function testUpdateOffDoesNotBlockAddOnCreatingAndPricingANewProductInTheSameRun(): void
    {
        $vendor = $this->makeVendor('Add With Update Off Vendor');

        $csv = $this->csvUpload([
            ['our_sku', 'vendor_price'],
            ['ADD-WITH-UPDATE-OFF', '7.25'],
        ]);

        $run = $this->runViaRealQueue(
            $vendor,
            $csv,
            ['our_sku' => 'our_sku', 'vendor_price' => 'vendor_price'],
            ['allow_add' => true, 'allow_update' => false],
        );

        self::assertSame(0, $run->getErrorCount());
        $product = $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => 'ADD-WITH-UPDATE-OFF']);
        self::assertInstanceOf(ProductCore::class, $product);
        $price = $this->em->getRepository(VendorPrice::class)->findOneBy(['vendor' => $vendor, 'product' => $product]);
        self::assertInstanceOf(VendorPrice::class, $price, 'a product ADD just created is priceable in the same run regardless of update');
    }

    public function testDeleteOnZeroesAndDeactivatesVendorPricesThisSheetDoesNotName(): void
    {
        $vendor = $this->makeVendor('Delete On Vendor');
        $keptProduct = $this->makeProduct('DELETE-ON-KEPT');
        $droppedProduct = $this->makeProduct('DELETE-ON-DROPPED');

        $kept = (new VendorPrice())->setVendor($vendor)->setProduct($keptProduct)->setVendorSku('VSKU-KEPT')->setUnitCost('1.0000')->setAvailableQuantity('40');
        $dropped = (new VendorPrice())->setVendor($vendor)->setProduct($droppedProduct)->setVendorSku('VSKU-DROPPED')->setUnitCost('2.0000')->setAvailableQuantity('15');
        $this->em->persist($kept);
        $this->em->persist($dropped);
        $this->em->flush();

        // This sheet only names DELETE-ON-KEPT — DELETE-ON-DROPPED is the one no longer listed.
        $csv = $this->csvUpload([
            ['our_sku', 'vendor_price', 'vendor_quantity'],
            ['DELETE-ON-KEPT', '1.10', '45'],
        ]);

        $this->runViaRealQueue(
            $vendor,
            $csv,
            ['our_sku' => 'our_sku', 'vendor_price' => 'vendor_price', 'vendor_quantity' => 'vendor_quantity'],
            ['allow_delete' => true],
        );

        $this->em->clear();
        $reloadedKept = $this->em->find(VendorPrice::class, $kept->getId());
        self::assertTrue($reloadedKept->isActive(), 'named in this sheet — untouched by the delete pass');
        self::assertEqualsWithDelta(45.0, (float) $reloadedKept->getAvailableQuantity(), 0.0001);

        $reloadedDropped = $this->em->find(VendorPrice::class, $dropped->getId());
        self::assertFalse($reloadedDropped->isActive(), 'not named in this sheet — delete=on deactivates it');
        self::assertEqualsWithDelta(0.0, (float) $reloadedDropped->getAvailableQuantity(), 0.0001, 'and zeroes the quantity — soft, not a real DELETE FROM');
        self::assertEqualsWithDelta(2.0, (float) $reloadedDropped->getUnitCost(), 0.0001, 'the price ITSELF is never touched — only availability, so a debit memo or PO line referencing this row still has real history');
    }

    public function testDeleteOffLeavesUnnamedVendorPricesCompletelyAlone(): void
    {
        $vendor = $this->makeVendor('Delete Off Vendor');
        $keptProduct = $this->makeProduct('DELETE-OFF-KEPT');
        $untouchedProduct = $this->makeProduct('DELETE-OFF-UNTOUCHED');

        $untouched = (new VendorPrice())->setVendor($vendor)->setProduct($untouchedProduct)->setVendorSku('VSKU-UNTOUCHED')->setUnitCost('3.0000')->setAvailableQuantity('12');
        $this->em->persist($untouched);
        $this->em->flush();

        $csv = $this->csvUpload([
            ['our_sku', 'vendor_price'],
            ['DELETE-OFF-KEPT', '1.00'],
        ]);

        // allow_delete deliberately omitted — off is the default.
        $this->runViaRealQueue($vendor, $csv, ['our_sku' => 'our_sku', 'vendor_price' => 'vendor_price']);

        $this->em->clear();
        $reloaded = $this->em->find(VendorPrice::class, $untouched->getId());
        self::assertTrue($reloaded->isActive(), 'delete=off (the default) must leave a row this sheet never named exactly as it was');
        self::assertEqualsWithDelta(12.0, (float) $reloaded->getAvailableQuantity(), 0.0001);
    }

    /** A previously delete-deactivated row must come back to life the moment the vendor supplies it again. */
    public function testARowDeleteDeactivatedEarlierRevivesWhenTheVendorNamesItAgain(): void
    {
        $vendor = $this->makeVendor('Revival Vendor');
        $product = $this->makeProduct('REVIVAL-SKU');
        $price = (new VendorPrice())->setVendor($vendor)->setProduct($product)->setVendorSku('VSKU-REVIVAL')->setUnitCost('1.0000')->setAvailableQuantity('0');
        $price->setIsActive(false);
        $this->em->persist($price);
        $this->em->flush();

        $csv = $this->csvUpload([
            ['our_sku', 'vendor_price', 'vendor_quantity'],
            ['REVIVAL-SKU', '1.25', '20'],
        ]);

        $this->runViaRealQueue($vendor, $csv, ['our_sku' => 'our_sku', 'vendor_price' => 'vendor_price', 'vendor_quantity' => 'vendor_quantity']);

        $this->em->clear();
        $reloaded = $this->em->find(VendorPrice::class, $price->getId());
        self::assertTrue($reloaded->isActive(), 'named again in a real sheet — must come back to life, not stay dead forever');
        self::assertEqualsWithDelta(20.0, (float) $reloaded->getAvailableQuantity(), 0.0001);
    }
}
