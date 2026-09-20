<?php

declare(strict_types=1);

namespace App\Tests\Service\ProductImport;

use App\Entity\AppSetting;
use App\Entity\FulfillmentRegion;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use App\Entity\PriceList;
use App\Entity\ProductCategory;
use App\Entity\ProductCore;
use App\Entity\InventoryBucketChangeLog;
use App\Entity\ProductInventory;
use App\Entity\ProductPricing;
use App\Repository\BundleStatusRepository;
use App\Service\AppSettings;
use App\Service\Inventory\BackorderReleaseService;
use App\Service\Inventory\CoreInventoryTotalCountService;
use App\Service\Inventory\InventoryOperationContext;
use App\Service\Inventory\DimensionalImportResolver;
use App\Service\Inventory\InventoryModeResolver;
use App\Service\ProductImport\ProductImportService;
use App\Service\Uom\ProductBaseUnitService;
use App\Tests\DoctrineIntegrationTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ProductImportServiceTest extends DoctrineIntegrationTestCase
{
    private ProductImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ProductImportService(
            [],
            self::getContainer()->get(BundleStatusRepository::class),
            self::getContainer()->get(InventoryOperationContext::class),
            self::getContainer()->get(AppSettings::class),
            self::getContainer()->get(WarehouseFulfillmentRegionService::class),
            self::getContainer()->get(BackorderReleaseService::class),
            self::getContainer()->get(InventoryModeResolver::class),
            self::getContainer()->get(DimensionalImportResolver::class),
            self::getContainer()->get(ProductBaseUnitService::class),
            self::getContainer()->get(CoreInventoryTotalCountService::class),
        );
    }

    /** @param list<list<string>> $rows */
    private function csvUpload(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import_test_');
        $fp = fopen($path, 'w');
        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);

        return new UploadedFile($path, 'products.csv', 'text/csv', null, true);
    }

    public function testImportCreatesNewProductFromCsv(): void
    {
        $csv = $this->csvUpload([
            ['sku', 'name', 'status', 'visible'],
            ['SKU-1', 'Widget', 'Active', 'Yes'],
        ]);

        $result = $this->service->import($csv, $this->em, ['primary_key' => 'sku', 'missing_rows' => 'do_nothing']);

        self::assertSame(1, $result->totalRows);
        self::assertSame(1, $result->created);
        self::assertSame(0, $result->updated);
        self::assertSame([], $result->errors);

        $product = $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => 'SKU-1']);
        self::assertInstanceOf(ProductCore::class, $product);
        self::assertSame('Widget', $product->getName());
        self::assertSame('Active', $product->getStatus());
        self::assertTrue($product->isVisible());
    }

    /**
     * ## The five tests below are one decision, and it is a money decision
     *
     * An unset sales tax code maps to E — EXEMPT — at tax time. So "the import leaves the code
     * alone when the file does not mention it" is not a neutral default: it is a bulk-loaded
     * catalogue that charges no tax, on every line, silently, and looks completely normal on the
     * screen. That is what actually happened, and it is what #115 was raised to stop:
     *
     *     "Imported products were ending up Exempt, not taxable. The CSV importer only set a tax
     *      code when a non-empty sales_tax_code column was present, and the rim/API importer never
     *      set one at all — and an unset code maps to E (Exempt) at tax time. So bulk-imported
     *      products silently came in untaxed."
     *                                       — 44cd8750, "Default imported products to tax class S
     *                                          when none is given (#115)"
     *
     * The ruling that commit implements, and that the five tests below hold the import to:
     *
     *   1. An explicit E / G / S in the file always wins. An admin who says Exempt means Exempt.
     *   2. A blank cell, or no such column at all, takes the default_sales_tax_code SETTING
     *      (#419 made it configurable; the Number1 import screen's dropdown writes it).
     *   3. With nothing configured, that default is S — GST + PST taxable — and never E.
     *
     * Rule 3 is the one with teeth and the one to leave alone. "Leave it unset" and "default it to
     * E" are the same outcome at tax time, so a change that lands either is indistinguishable from
     * the bug #115 fixed, and neither the import screen nor the product list would show anything
     * wrong. If a future change makes an un-coded import land Exempt, it needs a ruling that says
     * so in as many words — not a passing test suite.
     *
     * One last thing for whoever finds these red. If the two "defaults to S" tests fail TOGETHER
     * and the ones that configure a setting explicitly stay green, suspect the ENVIRONMENT before
     * the import: that pattern means something is answering default_sales_tax_code = 'E' while the
     * app_setting table is empty, which is what a settings cache outliving its database looks like
     * rather than what a broken importer looks like. See
     * DoctrineIntegrationTestCase::resetTestSettingsCache().
     */
    public function testImportDefaultsSalesTaxCodeToSWhenColumnAbsent(): void
    {
        $csv = $this->csvUpload([
            ['sku', 'name'],
            ['SKU-TAX-1', 'No Tax Column'],
        ]);

        $this->service->import($csv, $this->em, ['primary_key' => 'sku', 'missing_rows' => 'do_nothing']);

        $product = $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => 'SKU-TAX-1']);
        self::assertInstanceOf(ProductCore::class, $product);
        self::assertSame(
            'S',
            $product->getSalesTaxCode(),
            'a missing sales_tax_code column defaults to S (GST + PST taxable) with nothing configured.'
            . ' E here would mean a bulk-imported catalogue that charges no tax and says nothing about it — #115.',
        );
    }

    public function testImportDefaultsSalesTaxCodeToSWhenCellBlank(): void
    {
        $csv = $this->csvUpload([
            ['sku', 'name', 'sales_tax_code'],
            ['SKU-TAX-2', 'Blank Tax', ''],
        ]);

        $this->service->import($csv, $this->em, ['primary_key' => 'sku', 'missing_rows' => 'do_nothing']);

        $product = $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => 'SKU-TAX-2']);
        self::assertInstanceOf(ProductCore::class, $product);
        self::assertSame(
            'S',
            $product->getSalesTaxCode(),
            'a blank sales_tax_code cell defaults to S, exactly as an absent column does: a vendor file'
            . ' that ships the header and leaves it empty must not import an untaxed catalogue — #115.',
        );
    }

    public function testImportUsesConfiguredDefaultSalesTaxCodeWhenColumnAbsent(): void
    {
        $appSettings = self::getContainer()->get(AppSettings::class);
        $this->em->persist((new AppSetting())->setSettingKey('default_sales_tax_code')->setName('Default Sales Tax Code')->setSettingValue('E'));
        $this->em->flush();
        $appSettings->clearCache();

        $csv = $this->csvUpload([
            ['sku', 'name'],
            ['SKU-TAX-4', 'No Tax Column'],
        ]);

        $this->service->import($csv, $this->em, ['primary_key' => 'sku', 'missing_rows' => 'do_nothing']);

        $product = $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => 'SKU-TAX-4']);
        self::assertInstanceOf(ProductCore::class, $product);
        self::assertSame('E', $product->getSalesTaxCode(), 'a missing sales_tax_code column defaults to the configured default_sales_tax_code setting');
    }

    /**
     * Rule 3 held against the setting being configured and then taken away again, in one test,
     * through the same service instance.
     *
     * The two tests above each prove half of this from a cold start, and a cold start is the one
     * condition under which a bug here hides: if anything — a cache, a memoised field, a static —
     * keeps answering 'E' after the row is gone, both of them still pass and this one fails. That
     * is not a hypothetical shape. It is exactly what was happening: AppSettings caches the whole
     * app_setting table for an hour in a pool that used to be swept between runs and silently
     * stopped being (see DoctrineIntegrationTestCase::resetTestSettingsCache()), so the configured
     * 'E' outlived its own database and the two tests above spent every run after the first
     * asserting a money bug the import does not have.
     *
     * It is also the real admin journey, not a contrivance: somebody sets the Number1 import screen's
     * dropdown to Exempt for one vendor load and sets it back afterwards. The next import must be
     * taxable again — S — and it must be taxable in the same process, not after a restart.
     */
    public function testRemovingTheConfiguredDefaultPutsTheImportBackOnS(): void
    {
        $appSettings = self::getContainer()->get(AppSettings::class);

        $setting = (new AppSetting())->setSettingKey('default_sales_tax_code')->setName('Default Sales Tax Code')->setSettingValue('E');
        $this->em->persist($setting);
        $this->em->flush();
        $appSettings->clearCache();

        $this->service->import(
            $this->csvUpload([['sku', 'name'], ['SKU-TAX-5', 'Imported While Exempt Was Configured']]),
            $this->em,
            ['primary_key' => 'sku', 'missing_rows' => 'do_nothing'],
        );

        self::assertSame(
            'E',
            $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => 'SKU-TAX-5'])?->getSalesTaxCode(),
            'with Exempt configured, an un-coded row honours the setting',
        );

        $this->em->remove($setting);
        $this->em->flush();
        $appSettings->clearCache();

        $this->service->import(
            $this->csvUpload([['sku', 'name'], ['SKU-TAX-6', 'Imported After The Setting Went Away']]),
            $this->em,
            ['primary_key' => 'sku', 'missing_rows' => 'do_nothing'],
        );

        self::assertSame(
            'S',
            $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => 'SKU-TAX-6'])?->getSalesTaxCode(),
            'with the setting removed the import falls back to S, not to the E that was configured a moment ago:'
            . ' the fallback is read per import, and an un-coded product is taxable unless somebody has said otherwise — #115.',
        );

        self::assertSame(
            'E',
            $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => 'SKU-TAX-5'])?->getSalesTaxCode(),
            'and the product imported while Exempt was configured keeps the code it was given',
        );
    }

    public function testImportKeepsExplicitSalesTaxCode(): void
    {
        $csv = $this->csvUpload([
            ['sku', 'name', 'sales_tax_code'],
            ['SKU-TAX-3', 'Exempt Product', 'E'],
        ]);

        $this->service->import($csv, $this->em, ['primary_key' => 'sku', 'missing_rows' => 'do_nothing']);

        $product = $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => 'SKU-TAX-3']);
        self::assertInstanceOf(ProductCore::class, $product);
        self::assertSame('E', $product->getSalesTaxCode(), 'an explicit tax code is preserved, not overridden to S');
    }

    public function testImportStampsTheCallerSuppliedSyncSourceOnANewProduct(): void
    {
        $csv = $this->csvUpload([
            ['sku', 'name'],
            ['SKU-SRC-1', 'Sourced Product'],
        ]);

        $this->service->import($csv, $this->em, [
            'primary_key' => 'sku',
            'missing_rows' => 'do_nothing',
            'sync_source' => 'some-caller',
        ]);

        $product = $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => 'SKU-SRC-1']);
        self::assertInstanceOf(ProductCore::class, $product);
        self::assertSame('some-caller', $product->getSyncSource());
    }

    /**
     * The shared importer serves several bundles, so it must never invent a provenance of its
     * own: a caller that says nothing leaves the column honestly empty rather than inheriting
     * whichever value happened to be hardcoded here. Issue #477.
     */
    public function testImportLeavesSyncSourceNullWhenTheCallerSuppliesNone(): void
    {
        $csv = $this->csvUpload([
            ['sku', 'name'],
            ['SKU-SRC-2', 'Unsourced Product'],
        ]);

        $this->service->import($csv, $this->em, ['primary_key' => 'sku', 'missing_rows' => 'do_nothing']);

        $product = $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => 'SKU-SRC-2']);
        self::assertInstanceOf(ProductCore::class, $product);
        self::assertNull($product->getSyncSource());
    }

    /**
     * syncSource records where a product came from, not who touched it last. Re-stamping on
     * update would also move a rim-API product out of RimApiImportService's syncSource-scoped
     * inactivate-missing set — a behaviour change #477 explicitly must not make.
     */
    public function testImportDoesNotRestampSyncSourceOnAnExistingProduct(): void
    {
        $existing = (new ProductCore())->setSku('SKU-SRC-3')->setName('Already Sourced')->setSyncSource('number1-rim-api');
        $this->em->persist($existing);
        $this->em->flush();

        $csv = $this->csvUpload([
            ['sku', 'name'],
            ['SKU-SRC-3', 'Renamed By Another Import'],
        ]);

        $this->service->import($csv, $this->em, [
            'primary_key' => 'sku',
            'missing_rows' => 'do_nothing',
            'sync_source' => 'number1-product-csv-import',
        ]);

        $product = $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => 'SKU-SRC-3']);
        self::assertInstanceOf(ProductCore::class, $product);
        self::assertSame('Renamed By Another Import', $product->getName(), 'the row itself is still updated');
        self::assertSame('number1-rim-api', $product->getSyncSource(), 'provenance survives a re-import by a different source');
    }

    public function testImportUpdatesExistingProductBySku(): void
    {
        $existing = (new ProductCore())->setSku('SKU-1')->setName('Old Name')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($existing);
        $this->em->flush();

        $csv = $this->csvUpload([
            ['sku', 'name'],
            ['SKU-1', 'New Name'],
        ]);

        $result = $this->service->import($csv, $this->em, ['primary_key' => 'sku', 'missing_rows' => 'do_nothing']);

        self::assertSame(0, $result->created);
        self::assertSame(1, $result->updated);

        $this->em->refresh($existing);
        self::assertSame('New Name', $existing->getName());
    }

    public function testImportSkipsRowMissingPrimaryKey(): void
    {
        $csv = $this->csvUpload([
            ['sku', 'name'],
            ['', 'No Sku Here'],
        ]);

        $result = $this->service->import($csv, $this->em, ['primary_key' => 'sku', 'missing_rows' => 'do_nothing']);

        self::assertSame(0, $result->created);
        self::assertSame(1, $result->skipped);
        self::assertCount(1, $result->errors);
        self::assertStringContainsString('Missing primary key "sku"', $result->errors[0]);
    }

    public function testImportCountsDuplicateSkuWithinSameCsvAndOnlyKeepsFirst(): void
    {
        $csv = $this->csvUpload([
            ['sku', 'name'],
            ['SKU-1', 'First'],
            ['SKU-1', 'Second'],
        ]);

        $result = $this->service->import($csv, $this->em, ['primary_key' => 'sku', 'missing_rows' => 'do_nothing']);

        self::assertSame(1, $result->created);
        self::assertSame(1, $result->duplicates);
        self::assertSame(1, $result->skipped);

        $product = $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => 'SKU-1']);
        self::assertSame('First', $product?->getName());
    }

    public function testImportRejectsNegativeCostPrice(): void
    {
        $csv = $this->csvUpload([
            ['sku', 'name', 'cost_price'],
            ['SKU-1', 'Widget', '-5.00'],
        ]);

        $result = $this->service->import($csv, $this->em, ['primary_key' => 'sku', 'missing_rows' => 'do_nothing']);

        self::assertSame(0, $result->created);
        self::assertSame(1, $result->skipped);
        self::assertCount(1, $result->errors);
        self::assertStringContainsString('Cost Price must be a number', $result->errors[0]);
        self::assertNull($this->em->getRepository(ProductCore::class)->findOneBy(['sku' => 'SKU-1']));
    }

    public function testImportRequiresNameForNewProductButNotForUpdate(): void
    {
        $existing = (new ProductCore())->setSku('SKU-1')->setName('Existing')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($existing);
        $this->em->flush();

        $csv = $this->csvUpload([
            ['sku', 'name', 'status'],
            ['SKU-NEW', '', 'Active'],
            ['SKU-1', '', 'Inactive'],
        ]);

        $result = $this->service->import($csv, $this->em, ['primary_key' => 'sku', 'missing_rows' => 'do_nothing']);

        self::assertSame(0, $result->created);
        self::assertSame(1, $result->updated);
        self::assertSame(1, $result->skipped);
        self::assertStringContainsString('Product name is required', $result->errors[0]);

        $this->em->refresh($existing);
        self::assertSame('Inactive', $existing->getStatus());
        self::assertSame('Existing', $existing->getName());
    }

    public function testImportAppliesInventoryAndPricingColumns(): void
    {
        $region = (new FulfillmentRegion())->setName('West');
        $priceList = (new PriceList())->setName('Standard')->setCurrency('USD');
        $this->em->persist($region);
        $this->em->persist($priceList);
        // The CSV column still names the REGION; the stock lands in the warehouse serving it (#546).
        $warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');
        $this->em->flush();

        $csv = $this->csvUpload([
            ['sku', 'name', 'fulfillment_region__west', 'price__standard'],
            ['SKU-1', 'Widget', '25', '19.99'],
        ]);

        $result = $this->service->import($csv, $this->em, ['primary_key' => 'sku', 'missing_rows' => 'do_nothing']);

        self::assertSame(1, $result->created);

        $product = $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => 'SKU-1']);
        $inventory = $this->em->getRepository(ProductInventory::class)->findOneBy(['product' => $product, 'warehouse' => $warehouse]);
        self::assertInstanceOf(ProductInventory::class, $inventory);
        self::assertSame('25.0000', $inventory->getQuantity());

        $pricing = $this->em->getRepository(ProductPricing::class)->findOneBy(['product' => $product, 'priceList' => $priceList]);
        self::assertInstanceOf(ProductPricing::class, $pricing);
        self::assertSame('19.99', $pricing->getPrice());
        self::assertSame('USD', $pricing->getCurrency());
    }

    public function testImportPreservesApprovedQuantityWhenOptionIsOmitted(): void
    {
        [$product, $warehouse] = $this->seedApprovedInventory();

        $csv = $this->csvUpload([
            ['sku', 'name', 'fulfillment_region__west'],
            ['SKU-1', 'Widget', '25'],
        ]);

        $this->service->import($csv, $this->em, ['primary_key' => 'sku', 'missing_rows' => 'do_nothing']);

        $inventory = $this->em->getRepository(ProductInventory::class)->findOneBy(['product' => $product, 'warehouse' => $warehouse]);
        self::assertInstanceOf(ProductInventory::class, $inventory);
        self::assertSame('25.0000', $inventory->getQuantity());
        self::assertSame('7.0000', $inventory->getApprovedQuantity());
    }

    public function testImportClearsApprovedQuantityWhenOptionIsExplicitlyTrue(): void
    {
        [$product, $warehouse] = $this->seedApprovedInventory();

        $csv = $this->csvUpload([
            ['sku', 'name', 'fulfillment_region__west'],
            ['SKU-1', 'Widget', '25'],
        ]);

        $this->service->import($csv, $this->em, [
            'primary_key' => 'sku',
            'missing_rows' => 'do_nothing',
            'clear_approved_balance' => true,
        ]);

        $inventory = $this->em->getRepository(ProductInventory::class)->findOneBy(['product' => $product, 'warehouse' => $warehouse]);
        self::assertInstanceOf(ProductInventory::class, $inventory);
        self::assertSame('25.0000', $inventory->getQuantity());
        self::assertSame('0.0000', $inventory->getApprovedQuantity());
    }

    /**
     * Every bucket a recount clears — `quantity` itself, and whichever of the fourteen were
     * explicitly named — signs the SAME action now, because they are the same action: one
     * declaration, one write, one name (#582, then CoreInventoryTotalCountService).
     *
     * This replaces the old guarantee ("approved" and "received" each kept their own name):
     * CoreInventoryTotalCountService::setCount() writes `quantity` and every named `clearBuckets`
     * entry together, in one call, so the change log has one action for all of them —
     * 'import_count_set' — not per-bucket ones a caller could accidentally bundle two unrelated
     * resets under. `import_approved_reset` still exists, but it no longer names a bucket change
     * at all: it is only the invoice-reservation-baseline side effect Approved/Shipped carry,
     * asserted separately below.
     */
    public function testEveryClearedBucketSignsTheSameCountAction(): void
    {
        [$product, $warehouse] = $this->seedApprovedInventory();

        $inventory = $this->em->getRepository(ProductInventory::class)->findOneBy(['product' => $product, 'warehouse' => $warehouse]);
        self::assertInstanceOf(ProductInventory::class, $inventory);
        $inventory->setReceivedQuantity(9);
        $this->em->flush();

        $this->em->createQuery('DELETE FROM App\\Entity\\InventoryBucketChangeLog l')->execute();

        $this->service->import(
            $this->csvUpload([['sku', 'name', 'fulfillment_region__west'], ['SKU-1', 'Widget', '25']]),
            $this->em,
            [
                'primary_key' => 'sku',
                'missing_rows' => 'do_nothing',
                'clear_approved_balance' => true,
                'clear_received_balance' => true,
            ],
        );

        $byBucket = [];
        foreach ($this->em->getRepository(InventoryBucketChangeLog::class)->findAll() as $entry) {
            $byBucket[$entry->getBucket()] = $entry->getAction();
        }

        self::assertSame(
            'import_count_set',
            $byBucket[InventoryBucketChangeLog::BUCKET_APPROVED] ?? null,
            'approved is cleared as part of the same count write, not a bucket-change entry of its own',
        );
        self::assertSame(
            'import_count_set',
            $byBucket[InventoryBucketChangeLog::BUCKET_RECEIVED] ?? null,
            'received is cleared as part of the same count write too',
        );
    }

    /** @return array{0: ProductCore, 1: Warehouse} */
    private function seedApprovedInventory(): array
    {
        $region = (new FulfillmentRegion())->setName('West');
        $this->em->persist($region);
        $warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $product = (new ProductCore())->setSku('SKU-1')->setName('Widget')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $inventory = (new ProductInventory())
            ->setProduct($product)
            ->setWarehouse($warehouse)
            ->setQuantity(10)
            ->setApprovedQuantity(7);
        $this->em->persist($product);
        $this->em->persist($inventory);
        $this->em->flush();

        return [$product, $warehouse];
    }

    public function testImportAppliesSimpleModeSalePriceViaPriceListColumn(): void
    {
        $priceList = (new PriceList())->setName('Standard')->setCurrency('USD');
        $this->em->persist($priceList);
        $this->em->flush();

        $csv = $this->csvUpload([
            ['sku', 'name', 'sale_price', 'price_list'],
            ['SKU-1', 'Widget', '9.99', 'Standard'],
        ]);

        $result = $this->service->import($csv, $this->em, ['primary_key' => 'sku', 'missing_rows' => 'do_nothing']);

        self::assertSame(1, $result->created);
        self::assertSame([], $result->warnings);

        $product = $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => 'SKU-1']);
        $pricing = $this->em->getRepository(ProductPricing::class)->findOneBy(['product' => $product, 'priceList' => $priceList]);
        self::assertInstanceOf(ProductPricing::class, $pricing);
        self::assertSame('9.99', $pricing->getPrice());
    }

    public function testImportInactivatesProductsMissingFromCsvWhenOptionSet(): void
    {
        $keep = (new ProductCore())->setSku('SKU-KEEP')->setName('Keep')->activate()->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $drop = (new ProductCore())->setSku('SKU-DROP')->setName('Drop')->activate()->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($keep);
        $this->em->persist($drop);
        $this->em->flush();

        $csv = $this->csvUpload([
            ['sku', 'name'],
            ['SKU-KEEP', 'Keep'],
        ]);

        $result = $this->service->import($csv, $this->em, ['primary_key' => 'sku', 'missing_rows' => 'inactive_missing']);

        self::assertSame(1, $result->inactivated);

        $this->em->refresh($keep);
        $this->em->refresh($drop);
        self::assertSame('Active', $keep->getStatus());
        self::assertSame('Inactive', $drop->getStatus());
    }

    public function testImportAppliesCategoryByName(): void
    {
        $category = (new ProductCategory())->setName('Beverages')->setStatus('Active');
        $this->em->persist($category);
        $this->em->flush();

        $csv = $this->csvUpload([
            ['sku', 'name', 'category'],
            ['SKU-1', 'Widget', 'Beverages'],
        ]);

        $this->service->import($csv, $this->em, ['primary_key' => 'sku', 'missing_rows' => 'do_nothing']);

        $product = $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => 'SKU-1']);
        self::assertSame($category, $product?->getCategory());
    }

    public function testReadProgressReturnsNotStartedForUnknownToken(): void
    {
        self::assertSame(['started' => false, 'complete' => false], $this->service->readProgress('does-not-exist'));
    }

    /**
     * Issue #211: no CSV column reached a fee provider's per-product checkbox at all —
     * only the whole-batch category default did. A FeeImportColumnProviderInterface
     * implementer must now receive an explicit per-row value.
     */
    public function testImportAppliesAFeeColumnToTheMatchingProvider(): void
    {
        $provider = new FakeFeeImportColumnProvider();
        $service = new ProductImportService(
            [$provider],
            self::getContainer()->get(BundleStatusRepository::class),
            self::getContainer()->get(InventoryOperationContext::class),
            self::getContainer()->get(AppSettings::class),
            self::getContainer()->get(WarehouseFulfillmentRegionService::class),
            self::getContainer()->get(BackorderReleaseService::class),
            self::getContainer()->get(InventoryModeResolver::class),
            self::getContainer()->get(DimensionalImportResolver::class),
            self::getContainer()->get(ProductBaseUnitService::class),
            self::getContainer()->get(CoreInventoryTotalCountService::class),
        );

        $csv = $this->csvUpload([
            ['sku', 'name', 'fee__BC-tsbc'],
            ['SKU-FEE-1', 'Tire Widget', '1'],
            ['SKU-FEE-2', 'Other Tire', 'yes'],
            ['SKU-FEE-3', 'No Fee Tire', '0'],
        ]);

        $result = $service->import($csv, $this->em, ['primary_key' => 'sku', 'missing_rows' => 'do_nothing']);

        self::assertSame([], $result->warnings);
        self::assertSame(['SKU-FEE-1' => true, 'SKU-FEE-2' => true, 'SKU-FEE-3' => false], $provider->appliedBySku);
    }

    public function testImportLeavesTheFeeValueAloneWhenTheCellIsBlank(): void
    {
        $provider = new FakeFeeImportColumnProvider();
        $service = new ProductImportService(
            [$provider],
            self::getContainer()->get(BundleStatusRepository::class),
            self::getContainer()->get(InventoryOperationContext::class),
            self::getContainer()->get(AppSettings::class),
            self::getContainer()->get(WarehouseFulfillmentRegionService::class),
            self::getContainer()->get(BackorderReleaseService::class),
            self::getContainer()->get(InventoryModeResolver::class),
            self::getContainer()->get(DimensionalImportResolver::class),
            self::getContainer()->get(ProductBaseUnitService::class),
            self::getContainer()->get(CoreInventoryTotalCountService::class),
        );

        $csv = $this->csvUpload([
            ['sku', 'name', 'fee__BC-tsbc'],
            ['SKU-FEE-4', 'Blank Fee Cell', ''],
        ]);

        $service->import($csv, $this->em, ['primary_key' => 'sku', 'missing_rows' => 'do_nothing']);

        self::assertArrayNotHasKey('SKU-FEE-4', $provider->appliedBySku);
    }

    public function testImportWarnsOnAnUnrecognizedFeeColumn(): void
    {
        $provider = new FakeFeeImportColumnProvider();
        $service = new ProductImportService(
            [$provider],
            self::getContainer()->get(BundleStatusRepository::class),
            self::getContainer()->get(InventoryOperationContext::class),
            self::getContainer()->get(AppSettings::class),
            self::getContainer()->get(WarehouseFulfillmentRegionService::class),
            self::getContainer()->get(BackorderReleaseService::class),
            self::getContainer()->get(InventoryModeResolver::class),
            self::getContainer()->get(DimensionalImportResolver::class),
            self::getContainer()->get(ProductBaseUnitService::class),
            self::getContainer()->get(CoreInventoryTotalCountService::class),
        );

        $csv = $this->csvUpload([
            ['sku', 'name', 'fee__unknown-fee'],
            ['SKU-FEE-5', 'Typo Column', '1'],
        ]);

        $result = $service->import($csv, $this->em, ['primary_key' => 'sku', 'missing_rows' => 'do_nothing']);

        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('Unknown fee column "fee__unknown_fee"', $result->warnings[0]);
        self::assertArrayNotHasKey('SKU-FEE-5', $provider->appliedBySku);
    }

    /**
     * Issue #212: import() periodically calls EntityManager::clear() to keep large imports
     * from going superlinear, and rebuilds the price-list/region/category lookup caches right
     * after. This drives a row count well past the batch boundary (CLEAR_BATCH_SIZE = 50) and
     * checks that rows on both sides of a clear() — including one that assigns a category, the
     * exact kind of cached-entity assignment a stale reference after clear() would corrupt —
     * still come out correct.
     */
    public function testImportStaysCorrectAcrossAClearBatchBoundary(): void
    {
        $category = (new ProductCategory())->setName('Beverages')->setStatus('Active');
        $this->em->persist($category);
        $this->em->flush();

        $rows = [['sku', 'name', 'category']];
        for ($i = 1; $i <= 120; $i++) {
            $rows[] = ["SKU-BATCH-{$i}", "Widget {$i}", $i % 10 === 0 ? 'Beverages' : ''];
        }

        $csv = $this->csvUpload($rows);
        $result = $this->service->import($csv, $this->em, ['primary_key' => 'sku', 'missing_rows' => 'do_nothing']);

        self::assertSame(120, $result->totalRows);
        self::assertSame(120, $result->created);
        self::assertSame([], $result->errors);

        foreach ([1, 49, 50, 51, 99, 100, 101, 120] as $i) {
            $product = $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => "SKU-BATCH-{$i}"]);
            self::assertInstanceOf(ProductCore::class, $product, "SKU-BATCH-{$i} should exist");
            self::assertSame("Widget {$i}", $product->getName());
        }

        $categorized = $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => 'SKU-BATCH-100']);
        self::assertNotNull($categorized);
        self::assertSame($category->getId(), $categorized->getCategory()?->getId());

        $uncategorized = $this->em->getRepository(ProductCore::class)->findOneBy(['sku' => 'SKU-BATCH-99']);
        self::assertNotNull($uncategorized);
        self::assertNull($uncategorized->getCategory());
    }

    /**
     * The re-baseline, through the REAL import (#564/#565).
     *
     * An earlier version of this test lived in the depth bundle and called a private helper that
     * re-implemented the three lines rebaselineCoreRow() writes. It asserted "if you set quantity
     * and zero received, quantity is set and received is zero" — it would have passed with
     * rebaselineCoreRow() deleted from production, and with the checkbox gate wired backwards,
     * which is precisely the wiring that was broken to begin with.
     *
     * So this one drives ProductImportService::import() with a CSV and the option, and asserts on
     * the database afterwards. Nothing between the file and the row is mocked or mimicked.
     */
    public function testAnImportWithTheBoxTickedRebaselinesADimensionalProduct(): void
    {
        [$product, $warehouse] = $this->dimensionalProductHolding(quantity: 100, received: 50, onShelf: 150);

        $this->service->import(
            $this->csvUpload([['sku', 'name', 'fulfillment_region__west'], ['DIM-1', 'Dimensional Widget', '150']]),
            $this->em,
            ['primary_key' => 'sku', 'missing_rows' => 'do_nothing', 'clear_received_balance' => true],
        );

        $this->em->clear();
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT quantity, received_quantity FROM product_inventory WHERE product_id = ?',
            [$product->getId()],
        );

        self::assertSame(150, (int) $row['quantity'], 'their figure becomes the new snapshot');
        self::assertSame(0, (int) $row['received_quantity'], 'the box says the file already includes what we booked');
    }

    /**
     * The other half, and the one that actually pins the gate: with the box UNTICKED, `received`
     * must survive untouched. An implementation that clears the bucket unconditionally passes the
     * test above and fails this one.
     *
     * `quantity` is asserted at the file's figure in BOTH tests, and that is the point of the pair
     * rather than an oversight (#572). The checkbox governs `received` and nothing else; `quantity`
     * is the client's own number and an import writes it every time, mechanically, whatever the box
     * says. This test previously asserted 100 here — that the unticked box also froze `quantity` at
     * its old value — which made the client's figure conditional on a checkbox about a different
     * column and let the two drift apart.
     */
    public function testAnImportWithTheBoxUntickedLeavesTheDimensionalBaselineAlone(): void
    {
        [$product] = $this->dimensionalProductHolding(quantity: 100, received: 50, onShelf: 150);

        $this->service->import(
            $this->csvUpload([['sku', 'name', 'fulfillment_region__west'], ['DIM-1', 'Dimensional Widget', '150']]),
            $this->em,
            ['primary_key' => 'sku', 'missing_rows' => 'do_nothing'],
        );

        $this->em->clear();
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT quantity, received_quantity FROM product_inventory WHERE product_id = ?',
            [$product->getId()],
        );

        self::assertSame(150, (int) $row['quantity'], 'their number, taken from the file, unconditionally');
        self::assertSame(50, (int) $row['received_quantity'], 'the delta since their last file is still true');
    }

    /**
     * A dimensional product with `quantity` from a past import, `received` already accrued, and
     * detail rows on the shelf holding the pair.
     *
     * @return array{0: ProductCore, 1: \App\Entity\Warehouse}
     */
    private function dimensionalProductHolding(int $quantity, int $received, int $onShelf): array
    {
        $region = (new FulfillmentRegion())->setName('West');
        $this->em->persist($region);
        $warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)->createWarehouseForRegion($region, 'BC', 'CA');

        $product = (new ProductCore())
            ->setSku('DIM-1')
            ->setName('Dimensional Widget')
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $this->em->persist($product);

        $this->em->persist(
            (new ProductInventory())
                ->setProduct($product)
                ->setWarehouse($warehouse)
                ->setQuantity($quantity)
                ->setReceivedQuantity($received)
        );
        $this->em->flush();

        $bin = (new \InventoryDepthBundle\Entity\WarehouseLocation())->setWarehouse($warehouse)->setCode('A-01');
        $this->em->persist($bin);
        $this->em->flush();

        $detail = (new \InventoryDepthBundle\Entity\InventoryDetail())
            ->setProduct($product)
            ->setWarehouse($warehouse)
            ->setLocation($bin)
            ->setStatus(\InventoryDepthBundle\Entity\InventoryDetail::STATUS_AVAILABLE);
        $detail->setQuantity($onShelf)->touch();
        $this->em->persist($detail);
        $this->em->flush();

        return [$product, $warehouse];
    }
}

final class FakeFeeImportColumnProvider implements \App\Contract\Fee\FeeImportColumnProviderInterface
{
    /** @var array<string, bool> */
    public array $appliedBySku = [];

    public function getImportColumnName(): string
    {
        return 'fee__BC-tsbc';
    }

    public function applyImportValue(ProductCore $product, bool $enabled): void
    {
        $this->appliedBySku[(string) $product->getSku()] = $enabled;
    }

}
