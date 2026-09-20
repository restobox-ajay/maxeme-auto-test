<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\ImportRun;
use ProcurementBundle\Entity\Vendor;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Covers ProcurementBundle\Controller\Admin\VendorSheetImportController on the real HTTP path —
 * upload, mapping (including the Category 1/Category 2 fields), and confirm — the same shape as
 * tests/Functional/AdminProductImportCest.php for core's product importer.
 *
 * This is the gap that let a real bug reach the browser: VendorSheetImportRowExecutor's
 * constructor grew a ProductCategoryRepository argument, and every OTHER construction site
 * (VendorSheetImportRunnerFactory, the PHPUnit end-to-end tests) was fixed, but the confirm()
 * controller action's own direct `new VendorSheetImportRowExecutor(...)` call was not — a
 * TypeError on every real submission that PHPUnit's direct-construction tests could never catch,
 * because they never went through the controller. confirmingTheMappingQueuesTheRealRun() below is
 * what would have failed.
 *
 * Row-by-row execution (and therefore the actual Category 1/2 resolution) is proven separately in
 * ProcurementBundle\Tests\Import\VendorSheetImportEndToEndTest — a spawned `import:process` child
 * cannot see this suite's still-uncommitted wrapping transaction, so this suite only asserts what
 * the web requests themselves do synchronously: rendering the forms and queueing the run.
 */
final class AdminVendorSheetImportCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-vendor-sheet-import-functional-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amLoggedInAs($admin, 'admin');
    }

    private function makeVendor(FunctionalTester $I, string $name): Vendor
    {
        $vendor = (new Vendor())->setName($name)->setCurrency('CAD');
        $I->haveInRepository($vendor);

        return $vendor;
    }

    private function throwawayCsv(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vendor_sheet_functional_') . '.csv';
        $fp = fopen($path, 'w');
        fputcsv($fp, ['vendor_sku', 'our_sku', 'vendor_price', 'cat1', 'cat2']);
        fputcsv($fp, ['VSKU-FUNCTIONAL-1', '', '9.99', 'Plumbing', 'Plumbing Fittings']);
        fclose($fp);

        return $path;
    }

    /** Same our_sku, two different vendor_name values — the exact shape checkWholeRun() refuses. */
    private function conflictingCsv(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vendor_sheet_conflict_') . '.csv';
        $fp = fopen($path, 'w');
        fputcsv($fp, ['vendor_sku', 'our_sku', 'vendor_price', 'name']);
        fputcsv($fp, ['V-1', 'CONFLICT-SKU', '9.99', 'Widget A']);
        fputcsv($fp, ['V-2', 'CONFLICT-SKU', '4.50', 'Widget B']);
        fclose($fp);

        return $path;
    }

    private function grabUploadToken(FunctionalTester $I): string
    {
        $I->amOnPage('/admin/bundles/procurement/vendor-sheet-import');

        return (string) $I->grabAttributeFrom('input[name="_token"]', 'value');
    }

    public function indexRendersTheUploadForm(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->makeVendor($I, 'Upload Form Vendor');

        $I->amOnPage('/admin/bundles/procurement/vendor-sheet-import');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Vendor Sheet Import');
        $I->seeElement('select[name="vendor_id"]');
        $I->seeElement('input[name="csv_file"]');
        $I->seeLink('Unmatched vendor SKUs');
    }

    public function uploadingAValidCsvGoesToAMappingScreenOfferingCategory1And2(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->makeVendor($I, 'Mapping Screen Vendor');

        $csvPath = $this->throwawayCsv();
        $I->sendMultipartPostRequest('/admin/bundles/procurement/vendor-sheet-import', [
            '_token' => $this->grabUploadToken($I),
            'vendor_id' => (string) $vendor->getId(),
        ], [
            'csv_file' => [
                'name' => 'vendor-sheet.csv',
                'type' => 'text/csv',
                'tmp_name' => $csvPath,
                'error' => \UPLOAD_ERR_OK,
                'size' => filesize($csvPath),
            ],
        ]);

        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlMatches('#/admin/bundles/procurement/vendor-sheet-import/mapping/[a-f0-9]{32}#');

        // Same crawler-staleness workaround as AdminProductImportCest: sendMultipartPostRequest()
        // never updates the module's own crawler, so seeElement() below needs an explicit re-fetch
        // of the page the redirect already proved we landed on.
        $mappingUrl = $I->grabFromCurrentUrl('#(/admin/bundles/procurement/vendor-sheet-import/mapping/[a-f0-9]{32}[^"\']*)#');
        $I->amOnPage($mappingUrl);
        $I->seeResponseCodeIsSuccessful();

        $I->see('Category 1 (optional)');
        $I->see('Category 2 (optional)');
        $I->seeElement('select[name="column_map[vendor_category1]"] option[value="cat1"]');
        $I->seeElement('select[name="column_map[vendor_category2]"] option[value="cat2"]');
    }

    public function confirmingTheMappingQueuesTheRealRun(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->makeVendor($I, 'Confirm Queue Vendor');

        $csvPath = $this->throwawayCsv();
        $I->sendMultipartPostRequest('/admin/bundles/procurement/vendor-sheet-import', [
            '_token' => $this->grabUploadToken($I),
            'vendor_id' => (string) $vendor->getId(),
        ], [
            'csv_file' => [
                'name' => 'vendor-sheet.csv',
                'type' => 'text/csv',
                'tmp_name' => $csvPath,
                'error' => \UPLOAD_ERR_OK,
                'size' => filesize($csvPath),
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $mappingUrl = $I->grabFromCurrentUrl('#(/admin/bundles/procurement/vendor-sheet-import/mapping/[a-f0-9]{32}[^"\']*)#');
        $I->amOnPage($mappingUrl);
        $I->seeResponseCodeIsSuccessful();

        $token = (string) $I->grabValueFrom('input[name="token"]');
        $I->assertNotSame('', $token);

        $I->sendFormPostRequest('/admin/bundles/procurement/vendor-sheet-import/confirm', [
            '_token' => $I->csrfToken(),
            'token' => $token,
            'filename' => 'vendor-sheet.csv',
            'vendor_id' => (string) $vendor->getId(),
            'column_map' => [
                'vendor_sku' => 'vendor_sku',
                'our_sku' => 'our_sku',
                'vendor_price' => 'vendor_price',
                'vendor_category1' => 'cat1',
                'vendor_category2' => 'cat2',
            ],
        ]);

        // This is the line that catches VendorSheetImportRowExecutor's constructor regression:
        // a TypeError in confirm() surfaces as a 500, never as this redirect.
        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlMatches('#/admin/imports/\d+#');

        $run = $I->grabEntityFromRepository(ImportRun::class, ['importType' => 'vendor_sheet', 'description' => 'Vendor: ' . $vendor->getName()]);
        $I->assertInstanceOf(ImportRun::class, $run);
        $I->assertFalse($run->isValidationOnly());
    }

    public function submittingWithoutAFileShowsAValidationErrorAndDoesNotImport(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->makeVendor($I, 'No File Vendor');

        $I->sendMultipartPostRequest('/admin/bundles/procurement/vendor-sheet-import', [
            '_token' => $this->grabUploadToken($I),
            'vendor_id' => (string) $vendor->getId(),
        ], []);

        $I->dontSeeCurrentUrlMatches('#/vendor-sheet-import/mapping/#');
        $I->dontSeeInRepository(ImportRun::class, ['importType' => 'vendor_sheet', 'description' => 'Vendor: ' . $vendor->getName()]);
    }

    /** The upload form's three on/off switches — Add/Update/Delete — and their real defaults. */
    public function theUploadFormOffersTheThreeAddUpdateDeleteSwitchesWithTheirRealDefaults(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->makeVendor($I, 'Switches Vendor');

        $I->amOnPage('/admin/bundles/procurement/vendor-sheet-import');
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement('input[type="checkbox"][name="allow_add"]');
        $I->dontSeeCheckboxIsChecked('input[name="allow_add"]');

        $I->seeElement('input[type="checkbox"][name="allow_update"]');
        $I->seeCheckboxIsChecked('input[name="allow_update"]');

        $I->seeElement('input[type="checkbox"][name="allow_delete"]');
        $I->dontSeeCheckboxIsChecked('input[name="allow_delete"]');
    }

    /**
     * Turning Add and Delete on, and Update off (an unchecked checkbox — nothing sent for it, same
     * as a real browser), at upload time survives the mapping screen as hidden fields and lands on
     * the queued ImportRun's own context — the same context VendorSheetImportRunnerFactory reads to
     * configure the REAL executor a spawned `import:process` worker uses
     * (modules/ProcurementBundle/src/Import/VendorSheetImportRunnerFactory.php). This suite cannot
     * see that worker run (see class docblock), so it stops at proving the context is exactly what
     * the admin chose.
     */
    public function turningAddOnAndUpdateOffCarriesThroughMappingIntoTheRunsContext(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->makeVendor($I, 'Switches Carry Vendor');

        $csvPath = $this->throwawayCsv();
        $I->sendMultipartPostRequest('/admin/bundles/procurement/vendor-sheet-import', [
            '_token' => $this->grabUploadToken($I),
            'vendor_id' => (string) $vendor->getId(),
            'allow_add' => '1',
            'allow_delete' => '1',
        ], [
            'csv_file' => [
                'name' => 'vendor-sheet.csv',
                'type' => 'text/csv',
                'tmp_name' => $csvPath,
                'error' => \UPLOAD_ERR_OK,
                'size' => filesize($csvPath),
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        // Update is genuinely off here — a real unchecked checkbox sends nothing at all, exactly
        // like allow_update above, so this is not a query default kicking in.
        $mappingUrl = $I->grabFromCurrentUrl('#(/admin/bundles/procurement/vendor-sheet-import/mapping/[a-f0-9]{32}[^"\']*)#');
        $I->assertStringContainsString('allow_add=1', $mappingUrl);
        $I->assertStringContainsString('allow_update=0', $mappingUrl);
        $I->assertStringContainsString('allow_delete=1', $mappingUrl);

        $I->amOnPage($mappingUrl);
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[type="hidden"][name="allow_add"][value="1"]');
        $I->seeElement('input[type="hidden"][name="allow_update"][value="0"]');
        $I->seeElement('input[type="hidden"][name="allow_delete"][value="1"]');

        $token = (string) $I->grabValueFrom('input[name="token"]');
        $I->assertNotSame('', $token);

        $I->sendFormPostRequest('/admin/bundles/procurement/vendor-sheet-import/confirm', [
            '_token' => $I->csrfToken(),
            'token' => $token,
            'filename' => 'vendor-sheet.csv',
            'vendor_id' => (string) $vendor->getId(),
            'allow_add' => '1',
            'allow_delete' => '1',
            'column_map' => [
                'vendor_sku' => 'vendor_sku',
                'our_sku' => 'our_sku',
                'vendor_price' => 'vendor_price',
                'vendor_category1' => 'cat1',
                'vendor_category2' => 'cat2',
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlMatches('#/admin/imports/\d+#');

        $run = $I->grabEntityFromRepository(ImportRun::class, ['importType' => 'vendor_sheet', 'description' => 'Vendor: ' . $vendor->getName()]);
        $I->assertInstanceOf(ImportRun::class, $run);
        $I->assertSame(
            ['vendor_id' => $vendor->getId(), 'allow_add' => true, 'allow_update' => false, 'allow_delete' => true],
            $run->getContext(),
        );
    }

    /**
     * The real-world incident this guards against: a vendor sheet reused the same our_sku for two
     * unrelated products partway through the file. Nothing about either row is individually
     * invalid, so per-row validation alone lets confirm() queue the run — VendorSheetImportValidator
     * ::checkWholeRun() is what has to catch it, and this proves the controller actually calls it
     * (see ImportRunner::wholeRunError(), invoked in VendorSheetImportController::confirm() right
     * after parseAndMap(), before anything is ever queued).
     */
    public function submittingASheetThatReusesOurSkuForDifferentItemsRefusesTheWholeRun(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->makeVendor($I, 'Conflicting Sku Vendor');

        $csvPath = $this->conflictingCsv();
        $I->sendMultipartPostRequest('/admin/bundles/procurement/vendor-sheet-import', [
            '_token' => $this->grabUploadToken($I),
            'vendor_id' => (string) $vendor->getId(),
        ], [
            'csv_file' => [
                'name' => 'conflicting.csv',
                'type' => 'text/csv',
                'tmp_name' => $csvPath,
                'error' => \UPLOAD_ERR_OK,
                'size' => filesize($csvPath),
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        $mappingUrl = $I->grabFromCurrentUrl('#(/admin/bundles/procurement/vendor-sheet-import/mapping/[a-f0-9]{32}[^"\']*)#');
        $I->amOnPage($mappingUrl);
        $I->seeResponseCodeIsSuccessful();

        $token = (string) $I->grabValueFrom('input[name="token"]');
        $I->assertNotSame('', $token);

        $I->sendFormPostRequest('/admin/bundles/procurement/vendor-sheet-import/confirm', [
            '_token' => $I->csrfToken(),
            'token' => $token,
            'filename' => 'conflicting.csv',
            'vendor_id' => (string) $vendor->getId(),
            'column_map' => [
                'vendor_sku' => 'vendor_sku',
                'our_sku' => 'our_sku',
                'vendor_price' => 'vendor_price',
                'vendor_name' => 'name',
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        // Refused, not queued: back on the mapping screen, never redirected to a run's own page.
        $I->seeCurrentUrlMatches('#/admin/bundles/procurement/vendor-sheet-import/mapping/#');

        // In the VISIBLE page body, not only the flash bag — base.html.twig's .flash-messages div
        // is `hidden` until app.js's showToast() reads it, so a no-JS admin sees nothing there. The
        // whole point of this refusal is that the admin finds out why; asserting only $I->see() on
        // the page text would pass even if the explanation lived exclusively in that hidden div.
        $I->seeElement('.panel-error');
        $I->see('CONFLICT-SKU', '.panel-error');
        $I->see('Widget A', '.panel-error');
        $I->see('Widget B', '.panel-error');

        $I->dontSeeInRepository(ImportRun::class, ['importType' => 'vendor_sheet', 'description' => 'Vendor: ' . $vendor->getName()]);
    }
}
