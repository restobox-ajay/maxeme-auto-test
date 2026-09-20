<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\AppSetting;
use App\Entity\BundleStatus;
use App\Entity\CustomFieldDefinition;
use App\Entity\Fee;
use App\Entity\ProductCategory;
use App\Entity\ProductCore;
use App\EventSubscriber\BundleBucketAvailabilityGate;
use App\Repository\BundleStatusRepository;
use App\Repository\CustomFieldValueRepository;
use App\Repository\ProductFeeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Number1ProductImportBundle\Service\ProductCsvTransformer;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers Number1ProductImportBundle's Admin/ProductImportController: the CSV import
 *  form render (store_vendor_meta default, the Tire fallback-category default, the
 *  TSBC checkbox's availability, and the clear-received checkbox's bundle gate), the
 *  vendor-metadata custom fields it writes when that checkbox is on vs. left at its
 *  default, and the TSBC eligibility it stamps onto newly-created Tire products. */
final class AdminNumber1ProductImportCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-number1-import-functional-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amLoggedInAs($admin, 'admin');
    }

    private function grabCsrfToken(FunctionalTester $I): string
    {
        $I->amOnPage('/admin/bundles/number1-product-import');

        return (string) $I->grabAttributeFrom('input[name="_token"]', 'value');
    }

    /** The controller moves() the uploaded file off its tmp_name path, so each upload
     *  needs its own disposable copy rather than reusing the checked-in fixture path. */
    private function copyVendorCsvFixture(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'n1pi_test_');
        copy(codecept_data_dir('product_import/number1_vendor_products.csv'), $path);

        return $path;
    }

    /** The CSV fixture's NAME is "TIRE:...", so its first colon segment slug-matches this
     *  category and the imported product lands in it without the fallback dropdown. */
    private function haveTireCategory(FunctionalTester $I): ProductCategory
    {
        return $this->haveCategory($I, 'Tire');
    }

    /** Built through the constructor, which is what populates the non-nullable createdAt —
     *  same shape AdminCategoryCest::makeCategory() uses. */
    private function haveCategory(FunctionalTester $I, string $name): ProductCategory
    {
        $category = (new ProductCategory())->setName($name);
        $I->haveInRepository($category);

        return $category;
    }

    /** FeeBCTireBundle seeds this row lazily, so the checkbox's availability check keys off
     *  its presence — tests that exercise TSBC have to stand it up themselves. */
    private function haveTsbcFee(FunctionalTester $I): Fee
    {
        $fee = (new Fee())
            ->setSlug('BC-tsbc')
            ->setName('BC Tire Stewardship (TSBC)')
            ->setTaxClass('G')
            ->setDefaultValue(5.0)
            ->setSource('FeeBCTireBundle');
        $I->haveInRepository($fee);

        return $fee;
    }

    /** @param array<string, string> $extraFields */
    private function uploadVendorCsv(FunctionalTester $I, array $extraFields = []): void
    {
        $token = $this->grabCsrfToken($I);
        $csvPath = $this->copyVendorCsvFixture();

        $I->sendMultipartPostRequest('/admin/bundles/number1-product-import', $extraFields + [
            '_token' => $token,
            'missing_rows' => 'do_nothing',
            'fallback_region_id' => '0',
        ], [
            'csv_file' => [
                'name' => 'number1_vendor_products.csv',
                'type' => 'text/csv',
                'tmp_name' => $csvPath,
                'error' => \UPLOAD_ERR_OK,
                'size' => filesize($csvPath),
            ],
        ]);
    }

    public function indexPreselectsTheTireFallbackCategory(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $tire = $this->haveTireCategory($I);

        $I->amOnPage('/admin/bundles/number1-product-import');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement(sprintf('select[name="fallback_category_id"] option[value="%d"][selected]', $tire->getId()));
    }

    public function indexLeavesTheFallbackCategoryOnNoneWithoutATireCategory(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->haveCategory($I, 'Wheels');

        $I->amOnPage('/admin/bundles/number1-product-import');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('select[name="fallback_category_id"] option[value=""][selected]');
    }

    public function indexRendersTheDefaultSalesTaxCodeDropdownDefaultingToS(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/bundles/number1-product-import');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('select[name="default_sales_tax_code"] option[value="S"][selected]');
    }

    public function importingPersistsTheChosenDefaultSalesTaxCode(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $this->uploadVendorCsv($I, ['default_sales_tax_code' => 'E']);

        $I->seeResponseCodeIsSuccessful();
        $I->seeInRepository(AppSetting::class, ['settingKey' => 'default_sales_tax_code', 'settingValue' => 'E']);

        $I->amOnPage('/admin/bundles/number1-product-import');
        $I->seeElement('select[name="default_sales_tax_code"] option[value="E"][selected]');
    }

    public function indexRendersTheTsbcCheckboxCheckedByDefault(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->haveTsbcFee($I);

        $I->amOnPage('/admin/bundles/number1-product-import');
        $I->seeResponseCodeIsSuccessful();
        $I->seeCheckboxIsChecked('input[name="enable_tire_tsbc"]');
    }

    /** No BC-tsbc fee row means FeeBCTireBundle isn't installed — offering a checkbox that
     *  can't do anything would be worse than hiding it. */
    public function indexOmitsTheTsbcCheckboxWhenTheFeeBundleIsAbsent(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/bundles/number1-product-import');
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('input[name="enable_tire_tsbc"]');
    }

    public function importingWithTsbcCheckedMarksNewTireProductsEligible(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->haveTireCategory($I);
        $fee = $this->haveTsbcFee($I);

        $this->uploadVendorCsv($I, ['enable_tire_tsbc' => 'yes']);

        $I->seeResponseCodeIsSuccessful();
        $product = $I->grabEntityFromRepository(ProductCore::class, ['sku' => 'N1-IMPORT-SKU']);
        $I->assertSame('Tire', $product->getCategory()?->getName());

        $productFeeRepo = $I->grabService(ProductFeeRepository::class);
        $I->assertSame(1.0, $productFeeRepo->getValueForProduct($product, $fee));
    }

    public function importingWithoutTsbcLeavesNewTireProductsIneligible(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->haveTireCategory($I);
        $fee = $this->haveTsbcFee($I);

        $this->uploadVendorCsv($I);

        $I->seeResponseCodeIsSuccessful();
        $product = $I->grabEntityFromRepository(ProductCore::class, ['sku' => 'N1-IMPORT-SKU']);

        $productFeeRepo = $I->grabService(ProductFeeRepository::class);
        $I->assertNull($productFeeRepo->getValueForProduct($product, $fee));
    }

    /** Scoped to the Tire category, so a row that resolved somewhere else stays ineligible
     *  even with the checkbox on. */
    public function importingWithTsbcCheckedSkipsNonTireProducts(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $fee = $this->haveTsbcFee($I);
        $wheels = $this->haveCategory($I, 'Wheels');

        // No Tire category exists, so the TIRE segment matches nothing and the fallback
        // dropdown drops the row into Wheels instead.
        $this->uploadVendorCsv($I, [
            'enable_tire_tsbc' => 'yes',
            'fallback_category_id' => (string) $wheels->getId(),
        ]);

        $I->seeResponseCodeIsSuccessful();
        $product = $I->grabEntityFromRepository(ProductCore::class, ['sku' => 'N1-IMPORT-SKU']);
        $I->assertSame('Wheels', $product->getCategory()?->getName());

        $productFeeRepo = $I->grabService(ProductFeeRepository::class);
        $I->assertNull($productFeeRepo->getValueForProduct($product, $fee));
    }

    /** Same shape InventoryReceivedBucketToggleCest uses to flip the bundle that fills the bucket. */
    private function setProcurementBundle(FunctionalTester $I, string $status): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $repo = $I->grabService(BundleStatusRepository::class);

        $em->persist($repo->ensureBySource(BundleBucketAvailabilityGate::WRITING_BUNDLE)->setStatus($status));
        $em->flush();
        $em->clear();
    }

    /**
     * The box was wired through the controller and the importer but never rendered, so it was
     * unreachable and always false. Unchecked by default: ticking it asserts the uploaded file
     * already includes the deliveries booked since the last one, which is not safe to assume.
     */
    public function indexRendersTheClearReceivedCheckboxWhenProcurementIsActive(FunctionalTester $I): void
    {
        $this->setProcurementBundle($I, BundleStatus::STATUS_ACTIVE);
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/bundles/number1-product-import');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[name="clear_received_balance"]');
        $I->dontSeeCheckboxIsChecked('input[name="clear_received_balance"]');
    }

    /** ProcurementBundle off means nothing fills the received bucket, so a box that clears it
     *  would be a lie about what the import did. */
    public function indexOmitsTheClearReceivedCheckboxWhenProcurementIsInactive(FunctionalTester $I): void
    {
        $this->setProcurementBundle($I, BundleStatus::STATUS_INACTIVE);
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/bundles/number1-product-import');
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeElement('input[name="clear_received_balance"]');
    }

    public function indexRendersTheStoreVendorMetaCheckboxUncheckedByDefault(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/bundles/number1-product-import');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[name="store_vendor_meta"]');
        $I->dontSeeCheckboxIsChecked('input[name="store_vendor_meta"]');
    }

    public function importingWithoutCheckingStoreVendorMetaDoesNotCreateCustomFields(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $token = $this->grabCsrfToken($I);

        $csvPath = $this->copyVendorCsvFixture();
        $I->sendMultipartPostRequest('/admin/bundles/number1-product-import', [
            '_token' => $token,
            'missing_rows' => 'do_nothing',
            'fallback_region_id' => '0',
        ], [
            'csv_file' => [
                'name' => 'number1_vendor_products.csv',
                'type' => 'text/csv',
                'tmp_name' => $csvPath,
                'error' => \UPLOAD_ERR_OK,
                'size' => filesize($csvPath),
            ],
        ]);

        $I->seeResponseCodeIsSuccessful();
        $I->seeInRepository(ProductCore::class, ['sku' => 'N1-IMPORT-SKU']);

        $product = $I->grabEntityFromRepository(ProductCore::class, ['sku' => 'N1-IMPORT-SKU']);
        $fieldValueRepo = $I->grabService(CustomFieldValueRepository::class);
        $values = $fieldValueRepo->getValuesForObject(CustomFieldDefinition::OBJECT_TYPE_PRODUCT, $product->getId());

        $I->assertArrayNotHasKey('number1_qb_item_name', $values);
        $I->assertArrayNotHasKey('number1_supplier', $values);
        $I->assertArrayNotHasKey('number1_gl_accounts', $values);
        $I->assertArrayNotHasKey('number1_notes', $values);
    }

    public function importingWithStoreVendorMetaCheckedCreatesCustomFields(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $token = $this->grabCsrfToken($I);

        $csvPath = $this->copyVendorCsvFixture();
        $I->sendMultipartPostRequest('/admin/bundles/number1-product-import', [
            '_token' => $token,
            'missing_rows' => 'do_nothing',
            'fallback_region_id' => '0',
            'store_vendor_meta' => 'yes',
        ], [
            'csv_file' => [
                'name' => 'number1_vendor_products.csv',
                'type' => 'text/csv',
                'tmp_name' => $csvPath,
                'error' => \UPLOAD_ERR_OK,
                'size' => filesize($csvPath),
            ],
        ]);

        $I->seeResponseCodeIsSuccessful();

        $product = $I->grabEntityFromRepository(ProductCore::class, ['sku' => 'N1-IMPORT-SKU']);
        $fieldValueRepo = $I->grabService(CustomFieldValueRepository::class);
        $values = $fieldValueRepo->getValuesForObject(CustomFieldDefinition::OBJECT_TYPE_PRODUCT, $product->getId());

        $I->assertSame('Wheel Supplier Ltd.', $values['number1_supplier'] ?? null);
        $I->assertSame('Imported wheel product', $values['number1_notes'] ?? null);
    }

    /**
     * Issue #477: this import used to leave sync_source null, which is how production ended up
     * unable to tell 223 tire products apart from anything else that was never stamped. The
     * value is the bundle's own constant, passed through core's shared importer rather than
     * hardcoded inside it.
     */
    public function importingStampsTheBundlesSyncSourceOnNewProducts(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $this->uploadVendorCsv($I);

        $I->seeResponseCodeIsSuccessful();
        $product = $I->grabEntityFromRepository(ProductCore::class, ['sku' => 'N1-IMPORT-SKU']);
        $I->assertSame('number1-product-csv-import', $product->getSyncSource());
        $I->assertSame(ProductCsvTransformer::SYNC_SOURCE, $product->getSyncSource());
    }

    /**
     * Provenance is where a product came from, not who last wrote to it. Re-running this import
     * over a rim-API product must not claim it — RimApiImportService::inactivateMissing() is
     * scoped to syncSource, so stealing the stamp would quietly change which products a rim sync
     * is allowed to deactivate.
     */
    public function importingDoesNotRestampAProductAnotherSourceAlreadyOwns(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $existing = (new ProductCore())
            ->setSku('N1-IMPORT-SKU')
            ->setName('Already Owned By The Rim API')
            ->setSyncSource('number1-rim-api');
        $I->haveInRepository($existing);

        $this->uploadVendorCsv($I);

        $I->seeResponseCodeIsSuccessful();
        $product = $I->grabEntityFromRepository(ProductCore::class, ['sku' => 'N1-IMPORT-SKU']);
        $I->assertSame('number1-rim-api', $product->getSyncSource());
    }
}
