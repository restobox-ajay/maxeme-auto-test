<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\ImportRun;
use App\Entity\ProductCore;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Covers Admin\ProductImportController on the unified import framework (see
 * docs/plans/2026-09-18-unified-import-framework.md): the upload form, the mapping screen
 * (ColumnMapper, auto-preselected via ProductImportService::normalizeHeaderKey()), queueing, and
 * the template download routes.
 *
 * The actual row-by-row execution happens in a spawned `import:process` child (see
 * tests/Command/ImportProcessCommandTest.php) — a real spawned OS process opens its own DB
 * connection and can't see this suite's still-uncommitted wrapping transaction (Functional.suite.yml),
 * so this suite only asserts what the web requests themselves do synchronously: validating input,
 * building the mapping screen, and queueing the run.
 */
final class AdminProductImportCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-product-import-functional-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amLoggedInAs($admin, 'admin');
    }

    private function grabImportToken(FunctionalTester $I): string
    {
        $I->amOnPage('/admin/product/import');

        return (string) $I->grabAttributeFrom('input[name="_token"]', 'value');
    }

    /**
     * ProductImportController durably persists the uploaded CSV (for the mapping screen, a second
     * GET, to read) by calling UploadedFile::move() — and in the Symfony test client's test mode,
     * move() does a real rename() of whatever path is given as tmp_name. Posting the checked-in
     * fixture path directly would let a successful upload rename it clean out of the repo, so
     * every test hands the controller a throwaway copy instead.
     */
    private function throwawayCopyOfFixture(string $relativePath): string
    {
        $copy = tempnam(sys_get_temp_dir(), 'product_import_fixture_') . '.csv';
        copy(codecept_data_dir($relativePath), $copy);

        return $copy;
    }

    public function indexRendersTheImportForm(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/product/import');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Product Import');
        $I->seeElement('input[name="csv_file"]');
        $I->seeElement('select[name="primary_key"]');
        $I->seeElement('input[name="_token"]');
        $I->seeLink('Import log');
    }

    public function uploadingAValidCsvGoesToAPreFilledMappingScreen(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $csvPath = $this->throwawayCopyOfFixture('product_import/valid_products.csv');
        $I->sendMultipartPostRequest('/admin/product/import', [
            '_token' => $this->grabImportToken($I),
            'primary_key' => 'sku',
            'missing_rows' => 'do_nothing',
        ], [
            'csv_file' => [
                'name' => 'valid_products.csv',
                'type' => 'text/csv',
                'tmp_name' => $csvPath,
                'error' => \UPLOAD_ERR_OK,
                'size' => filesize($csvPath),
            ],
        ]);

        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlMatches('#/admin/product/import/mapping/[a-f0-9]{32}#');
        $I->see('valid_products.csv');

        // sendMultipartPostRequest() posts via _request() directly, which never updates the
        // module's own crawler (same known issue Tests\Support\Helper\Functional's own docblock
        // describes for sendAjaxPostRequest) — an explicit re-fetch of the page the redirect
        // already proved we landed on is what makes seeElement() below assert against it rather
        // than the pre-POST upload form.
        $mappingUrl = $I->grabFromCurrentUrl('#(/admin/product/import/mapping/[a-f0-9]{32})#');
        $I->amOnPage($mappingUrl);
        $I->seeResponseCodeIsSuccessful();

        // The fixture's own headers (sku, name, ...) match this importer's target field keys
        // exactly, so normalizeHeaderKey()'s auto-preselect should already have them chosen.
        $I->seeElement('select[name="column_map[sku]"] option[value="sku"][selected]');
        $I->seeElement('select[name="column_map[name]"] option[value="name"][selected]');
    }

    public function confirmingTheMappingQueuesTheRunAndRedirectsToTheUnifiedDetailPage(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $csvPath = $this->throwawayCopyOfFixture('product_import/valid_products.csv');
        $I->sendMultipartPostRequest('/admin/product/import', [
            '_token' => $this->grabImportToken($I),
            'primary_key' => 'sku',
            'missing_rows' => 'do_nothing',
        ], [
            'csv_file' => [
                'name' => 'valid_products.csv',
                'type' => 'text/csv',
                'tmp_name' => $csvPath,
                'error' => \UPLOAD_ERR_OK,
                'size' => filesize($csvPath),
            ],
        ]);
        $I->seeResponseCodeIsSuccessful();

        // See the sibling test above for why the crawler needs an explicit re-fetch here.
        $mappingUrl = $I->grabFromCurrentUrl('#(/admin/product/import/mapping/[a-f0-9]{32})#');
        $I->amOnPage($mappingUrl);
        $I->seeResponseCodeIsSuccessful();

        $token = (string) $I->grabValueFrom('input[name="token"]');
        $I->assertNotSame('', $token);

        $I->sendFormPostRequest('/admin/product/import/confirm', [
            '_token' => $I->csrfToken(),
            'token' => $token,
            'filename' => 'valid_products.csv',
            'primary_key' => 'sku',
            'missing_rows' => 'do_nothing',
            'column_map' => ['sku' => 'sku', 'name' => 'name', 'status' => 'status'],
        ]);

        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlMatches('#/admin/imports/\d+#');

        $run = $I->grabEntityFromRepository(ImportRun::class, ['importType' => 'product']);
        $I->assertInstanceOf(ImportRun::class, $run);
        $I->assertFalse($run->isValidationOnly());
        $I->assertSame('Source: valid_products.csv', $run->getDescription());
    }

    public function submittingWithoutAFileShowsAValidationErrorAndDoesNotImport(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->sendMultipartPostRequest('/admin/product/import', [
            '_token' => $this->grabImportToken($I),
            'primary_key' => 'sku',
            'missing_rows' => 'do_nothing',
        ], []);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Please choose a valid CSV file to import.');
        $I->dontSeeInRepository(ProductCore::class, ['sku' => 'IMPORT-NEW-SKU']);
    }

    /**
     * The forged-token refusal, asserted against the thing the upload actually DOES (#594).
     *
     * A valid upload does not write a product row either — it moves the file and redirects to the
     * mapping screen — so the absent SKU alone would be satisfied by a refused upload, an accepted
     * one, a 404 and a 500 alike. What a forged token must actually prevent is landing on that
     * mapping screen (i.e. the file never being accepted for processing) and no ImportRun ever
     * being created — checked directly against the table now that it is a real one, not a run-log
     * directory that survives the per-test rollback.
     */
    public function uploadingWithoutAValidTokenIsRejectedAndImportsNothing(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $csvPath = $this->throwawayCopyOfFixture('product_import/valid_products.csv');
        $I->sendMultipartPostRequest('/admin/product/import', [
            '_token' => 'forged',
            'primary_key' => 'sku',
            'missing_rows' => 'do_nothing',
        ], [
            'csv_file' => [
                'name' => 'valid_products.csv',
                'type' => 'text/csv',
                'tmp_name' => $csvPath,
                'error' => \UPLOAD_ERR_OK,
                'size' => filesize($csvPath),
            ],
        ]);

        // A browser form post is refused with flash-and-redirect rather than a bare 403
        // (CsrfProtectionSubscriber), so the proof is where it did NOT land.
        $I->dontSeeCurrentUrlMatches('#/admin/product/import/mapping/#');
        $I->dontSeeInRepository(ImportRun::class, ['importType' => 'product']);
        $I->dontSeeInRepository(ProductCore::class, ['sku' => 'IMPORT-NEW-SKU']);
    }

    public function templateRouteDownloadsACsvWithTheCoreColumns(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/product/import/template');
        $I->seeResponseCodeIsSuccessful();

        $csv = $I->grabPageSource();
        $I->assertStringContainsString('sku', $csv);
        $I->assertStringContainsString('name', $csv);
    }

    public function mappingScreenRedirectsWithAFlashForAnUnknownToken(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/product/import/mapping/' . str_repeat('0', 32));
        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlEquals('/admin/product/import');
        $I->see('could not be found');
    }
}
