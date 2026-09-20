<?php

declare(strict_types=1);

namespace ProcurementBundle\Tests\Import;

use App\Entity\ImportRun;
use App\Entity\ImportRunRow;
use PHPUnit\Framework\TestCase;
use ProcurementBundle\Import\VendorSheetImportValidator;

/**
 * checkWholeRun() specifically — the per-row validate() rules already have coverage through
 * VendorSheetImportEndToEndTest. This is the guard added after a real vendor sheet reused its SKU
 * column for unrelated products partway through the file: nothing about any ONE row was invalid,
 * but rows further down silently overwrote 203 of 204 freshly-created products' prices with
 * garbage belonging to a different item entirely.
 */
final class VendorSheetImportValidatorTest extends TestCase
{
    private function runWithRows(array $rowsMappedData): ImportRun
    {
        $run = new ImportRun('vendor_sheet', 'VendorPrice');
        $rowNumber = 1;
        foreach ($rowsMappedData as $mappedData) {
            $run->addRow(new ImportRunRow($run, $rowNumber, $mappedData, $mappedData));
            ++$rowNumber;
        }

        return $run;
    }

    public function testTheSameOurSkuNamingTwoDifferentItemsIsRefused(): void
    {
        $run = $this->runWithRows([
            ['our_sku' => '100199', 'vendor_name' => '1/2 in Copper Press Cap'],
            ['our_sku' => '100199', 'vendor_name' => '2 in x 3/4 in Copper Press Reducer'],
        ]);

        $error = (new VendorSheetImportValidator())->checkWholeRun($run);

        self::assertNotNull($error);
        self::assertStringContainsString('our_sku "100199"', $error);
        self::assertStringContainsString('1/2 in Copper Press Cap', $error);
        self::assertStringContainsString('2 in x 3/4 in Copper Press Reducer', $error);
    }

    public function testTheSameOurSkuNamingTheSameItemTwiceIsFine(): void
    {
        $run = $this->runWithRows([
            ['our_sku' => '100199', 'vendor_name' => '1/2 in Copper Press Cap'],
            ['our_sku' => '100199', 'vendor_name' => '1/2 in Copper Press Cap', 'vendor_price' => '2.10'],
        ]);

        self::assertNull((new VendorSheetImportValidator())->checkWholeRun($run), 'a later row restating the same item — even with a new price — is an update, not corruption');
    }

    public function testARowWithNoNameNeverConflicts(): void
    {
        $run = $this->runWithRows([
            ['our_sku' => '100199', 'vendor_name' => '1/2 in Copper Press Cap'],
            ['our_sku' => '100199', 'vendor_name' => ''],
        ]);

        self::assertNull((new VendorSheetImportValidator())->checkWholeRun($run), 'a stock/price-only refresh row names no item, so it cannot disagree with one');
    }

    public function testDifferentSkusWithTheSameNameAreUnrelatedProductsNotAConflict(): void
    {
        $run = $this->runWithRows([
            ['our_sku' => '100199', 'vendor_name' => '3/4 in Copper Press Cap'],
            ['our_sku' => '100200', 'vendor_name' => '3/4 in Copper Press Cap'],
        ]);

        self::assertNull((new VendorSheetImportValidator())->checkWholeRun($run), 'two different our_sku values are two different products even if named the same');
    }

    public function testABlankOurSkuNeverConflicts(): void
    {
        $run = $this->runWithRows([
            ['our_sku' => '', 'vendor_name' => 'Widget A', 'vendor_sku' => 'V-1'],
            ['our_sku' => '', 'vendor_name' => 'Widget B', 'vendor_sku' => 'V-2'],
        ]);

        self::assertNull((new VendorSheetImportValidator())->checkWholeRun($run), 'our_sku-less rows resolve by vendor_sku, not this key');
    }

    public function testACleanRunIsUntouched(): void
    {
        $run = $this->runWithRows([
            ['our_sku' => '100199', 'vendor_name' => '1/2 in Copper Press Cap'],
            ['our_sku' => '100200', 'vendor_name' => '3/4 in Copper Press Cap'],
            ['our_sku' => '100201', 'vendor_name' => '1 in Copper Press Cap'],
        ]);

        self::assertNull((new VendorSheetImportValidator())->checkWholeRun($run));
    }
}
