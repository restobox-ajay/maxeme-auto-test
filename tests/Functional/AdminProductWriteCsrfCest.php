<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\Estimate;
use App\Entity\EstimateLine;
use App\Entity\PriceList;
use App\Entity\ProductCore;
use App\Entity\ProductImage;
use App\Entity\ProductPricing;
use App\Service\DocumentActor;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers the CSRF gate on Admin\ProductController's seven write endpoints: the two product
 *  form posts, the hard delete, and the four inline-fetch price writes on the price overview.
 *
 *  Each endpoint is checked both ways — the token as the page actually renders it gets through,
 *  a forged one does not and leaves the data untouched. */
final class AdminProductWriteCsrfCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-product-csrf-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function makeProduct(FunctionalTester $I, string $sku, string $name): ProductCore
    {
        $product = (new ProductCore())->setSku($sku)->setName($name)->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        return $product;
    }

    private function makePriceList(FunctionalTester $I, string $name): PriceList
    {
        $priceList = (new PriceList())->setName($name)->setCurrency('USD')->setStatus('Active');
        $I->haveInRepository($priceList);

        return $priceList;
    }

    /** The price page mints one token per write endpoint onto a single hidden element. */
    private function grabPriceToken(FunctionalTester $I, string $name): string
    {
        $I->amOnPage('/admin/product/price/index');

        return (string) $I->grabAttributeFrom('#price-write-tokens', 'data-' . $name . '-token');
    }

    /** CSRF here is one global token (App\Security\Csrf\Csrf), not one per form, so a real token
     *  read off any rendered admin page is valid for the delete endpoint too — there is no rendered
     *  delete button on a product row to read one off directly. */
    private function grabRealToken(FunctionalTester $I): string
    {
        $I->amOnPage('/admin/product/detail/index');

        return (string) $I->grabAttributeFrom('input[name="_token"]', 'value');
    }

    private function uploadDirForTest(): string
    {
        return codecept_root_dir('public/uploads/products');
    }

    public function corePriceUpdateAcceptsTheRenderedTokenAndRefusesAForgedOne(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $product = $this->makeProduct($I, 'CSRF-CORE-PRICE', 'Csrf Core Price Product');
        $token = $this->grabPriceToken($I, 'core-price-update');

        $I->sendAjaxPostRequest('/admin/product/core-price/update', [
            '_token' => $token,
            'product_id' => (string) $product->getId(),
            'field' => 'default_price',
            'price' => '12.50',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->seeInRepository(ProductCore::class, ['id' => $product->getId(), 'defaultPrice' => '12.50']);

        $I->sendAjaxPostRequest('/admin/product/core-price/update', [
            '_token' => 'forged',
            'product_id' => (string) $product->getId(),
            'field' => 'default_price',
            'price' => '99.99',
        ]);
        $I->seeResponseCodeIs(403);
        $I->seeInRepository(ProductCore::class, ['id' => $product->getId(), 'defaultPrice' => '12.50']);
    }

    public function priceListPriceUpdateAcceptsTheRenderedTokenAndRefusesAForgedOne(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $product = $this->makeProduct($I, 'CSRF-LIST-PRICE', 'Csrf List Price Product');
        $priceList = $this->makePriceList($I, 'Csrf Price Update List');
        $token = $this->grabPriceToken($I, 'price-update');

        $I->sendAjaxPostRequest('/admin/product/price/update', [
            '_token' => $token,
            'product_id' => (string) $product->getId(),
            'price_list_id' => (string) $priceList->getId(),
            'price' => '20.00',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->seeInRepository(ProductPricing::class, [
            'product' => $product->getId(),
            'priceList' => $priceList->getId(),
            'price' => '20.00',
        ]);

        $I->sendAjaxPostRequest('/admin/product/price/update', [
            '_token' => 'forged',
            'product_id' => (string) $product->getId(),
            'price_list_id' => (string) $priceList->getId(),
            'price' => '77.00',
        ]);
        $I->seeResponseCodeIs(403);
        $I->seeInRepository(ProductPricing::class, [
            'product' => $product->getId(),
            'priceList' => $priceList->getId(),
            'price' => '20.00',
        ]);
    }

    public function suggestedPriceUpdateAcceptsTheRenderedTokenAndRefusesAForgedOne(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $product = $this->makeProduct($I, 'CSRF-SUGGESTED', 'Csrf Suggested Price Product');
        $token = $this->grabPriceToken($I, 'suggested-price-update');

        $I->sendAjaxPostRequest('/admin/product/suggested-price/update', [
            '_token' => $token,
            'product_id' => (string) $product->getId(),
            'type' => 'Markup%',
            'value' => '10',
        ]);
        $I->seeResponseCodeIsSuccessful();

        // Both halves are read off the row (#594). A 200 is not evidence the accepted write landed,
        // and a 403 is not evidence the refused one did not: a controller that flushed the
        // suggested price BEFORE checking the token would store 90 and still answer 403, which is
        // the exact shape of a CSRF hole. Every sibling in this file reads the row back; this one
        // asserted only the response envelope.
        $I->seeInRepository(ProductCore::class, [
            'id' => $product->getId(),
            'suggestedPriceType' => 'Markup%',
            'suggestedPriceValue' => '10.00',
        ]);

        $I->sendAjaxPostRequest('/admin/product/suggested-price/update', [
            '_token' => 'forged',
            'product_id' => (string) $product->getId(),
            'type' => 'Markup%',
            'value' => '90',
        ]);
        $I->seeResponseCodeIs(403);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['ok']);

        $I->seeInRepository(ProductCore::class, [
            'id' => $product->getId(),
            'suggestedPriceValue' => '10.00',
        ]);
    }

    public function bulkPriceApplyRefusesAForgedTokenBeforeTouchingAnyRow(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $product = $this->makeProduct($I, 'CSRF-BULK', 'Csrf Bulk Apply Product');
        $priceList = $this->makePriceList($I, 'Csrf Bulk Apply List');

        $I->haveInRepository((new ProductPricing())
            ->setProduct($product)
            ->setPriceList($priceList)
            ->setPrice('30.00')
            ->setCurrency('USD')
            ->setRuleType('Number')
            ->setRuleValue('30'));

        $I->sendAjaxPostRequest('/admin/product/price/bulk-apply', [
            '_token' => 'forged',
            'price_list_id' => (string) $priceList->getId(),
            'field' => 'both',
            'type' => 'Discount%',
            'value' => '50',
        ]);
        $I->seeResponseCodeIs(403);

        $I->seeInRepository(ProductPricing::class, [
            'product' => $product->getId(),
            'priceList' => $priceList->getId(),
            'ruleType' => 'Number',
        ]);
    }

    public function bulkPriceApplyAcceptsTheRenderedToken(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $product = $this->makeProduct($I, 'CSRF-BULK-OK', 'Csrf Bulk Apply Ok Product');
        $priceList = $this->makePriceList($I, 'Csrf Bulk Apply Ok List');

        $I->haveInRepository((new ProductPricing())
            ->setProduct($product)
            ->setPriceList($priceList)
            ->setPrice('30.00')
            ->setCurrency('USD')
            ->setRuleType('Number')
            ->setRuleValue('30'));

        $token = $this->grabPriceToken($I, 'bulk-apply');
        $I->sendAjaxPostRequest('/admin/product/price/bulk-apply', [
            '_token' => $token,
            'price_list_id' => (string) $priceList->getId(),
            'field' => 'type',
            'type' => 'Hide',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(ProductPricing::class, [
            'product' => $product->getId(),
            'priceList' => $priceList->getId(),
            'ruleType' => 'Hide',
        ]);
    }

    public function deleteRefusesAForgedTokenAndKeepsTheProduct(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $product = $this->makeProduct($I, 'CSRF-DELETE-FORGED', 'Csrf Delete Forged Product');

        $I->sendAjaxPostRequest('/admin/product/delete/' . $product->getId(), ['_token' => 'forged']);
        $I->seeResponseCodeIs(403);

        $I->seeInRepository(ProductCore::class, ['id' => $product->getId()]);
    }

    /** Issue: a hard delete on a product a document still lines up against (here, an estimate line)
     *  must refuse with a 409 the admin can read, never a bare 500 from an uncaught
     *  ForeignKeyConstraintViolationException — and, since ProductController::delete() unlinks image
     *  files from disk, a refused delete must leave those files in place too. */
    public function deleteRefusesAProductStillLinedUpOnAnEstimateAndKeepsItsImage(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $product = $this->makeProduct($I, 'CSRF-DELETE-REFERENCED', 'Csrf Delete Referenced Product');
        $imageFilename = 'csrf-delete-referenced.jpg';
        $I->haveInRepository((new ProductImage())->setProduct($product)->setFilename($imageFilename)->setPrimaryImage(true));

        $imagePath = $this->uploadDirForTest() . '/' . $imageFilename;
        file_put_contents($imagePath, 'not-a-real-image');
        $I->assertFileExists($imagePath);

        $company = (new Company())->setName('Csrf Delete Referenced Co')->setCode('CSRFDELREF');
        $I->haveInRepository($company);
        $estimate = (new Estimate())
            ->setCompany($company)
            ->setDocumentNumber('EST-CSRF-DELETE-REF')
            ->setSource('Admin')
            ->setDocumentDate('2026-08-18')
            ->setUserName('Quinn Quoter')
            ->setSubtotal('50.00')
            ->setTax('0.00')
            ->setTotal('50.00');
        $estimate->setStatus('Priced', DocumentActor::system());
        $estimate->addLine(
            (new EstimateLine())->setProduct($product)->setName('Csrf Delete Referenced Product')->setQuantity('1.00')->setPrice('50.00')->setSubtotal('50.00'),
        );
        $I->haveInRepository($estimate);

        // A sibling with no document referencing it, so the refused delete can be proven not to
        // poison the next request.
        $sibling = $this->makeProduct($I, 'CSRF-DELETE-SIBLING', 'Csrf Delete Sibling Product');

        $token = $this->grabRealToken($I);

        $I->sendAjaxPostRequest('/admin/product/delete/' . $product->getId(), ['_token' => $token]);
        $I->seeResponseCodeIs(409);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['ok']);
        $I->assertStringContainsString('Csrf Delete Referenced Product', $response['message']);
        $I->assertStringContainsString('Mark it as deleted', $response['message']);

        // The FK exception closes the entity manager; get a fresh one before reading the database
        // again.
        $I->grabService('doctrine')->resetManager();

        $I->seeInRepository(ProductCore::class, ['id' => $product->getId()]);
        $I->seeInRepository(EstimateLine::class, ['product' => $product->getId()]);
        $I->assertFileExists($imagePath, 'a failed flush must not have already deleted the image file');

        // The sibling is untouched and can still be deleted.
        $I->seeInRepository(ProductCore::class, ['id' => $sibling->getId()]);
        $I->sendAjaxPostRequest('/admin/product/delete/' . $sibling->getId(), ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeInRepository(ProductCore::class, ['id' => $sibling->getId()]);

        // Repeating the original delete is still a 409, not a 500 the second time either.
        $I->sendAjaxPostRequest('/admin/product/delete/' . $product->getId(), ['_token' => $token]);
        $I->seeResponseCodeIs(409);
        $I->seeInRepository(ProductCore::class, ['id' => $product->getId()]);

        @unlink($imagePath);
    }

    public function theProductFormRendersATokenAndRefusesAForgedUpdate(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $product = $this->makeProduct($I, 'CSRF-FORM-UPDATE', 'Csrf Form Update Original');

        $I->amOnPage('/admin/product/inventory/update/' . $product->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[name="_token"]');

        $I->sendAjaxPostRequest('/admin/product/inventory/update/' . $product->getId(), [
            '_token' => 'forged',
            'name' => 'Csrf Form Update Renamed',
            'sku' => 'CSRF-FORM-UPDATE',
        ]);

        $I->seeInRepository(ProductCore::class, [
            'id' => $product->getId(),
            'name' => 'Csrf Form Update Original',
        ]);
    }

    public function theCreateFormRendersATokenAndRefusesAForgedCreate(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/product/inventory/create');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[name="_token"]');

        $I->sendAjaxPostRequest('/admin/product/inventory/create', [
            '_token' => 'forged',
            'name' => 'Csrf Forged Create Product',
            'sku' => 'CSRF-FORGED-CREATE',
        ]);

        $I->dontSeeInRepository(ProductCore::class, ['sku' => 'CSRF-FORGED-CREATE']);
    }

    public function theProductFormsTokenIsAcceptedWhenTakenFromThePage(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $product = $this->makeProduct($I, 'CSRF-FORM-OK', 'Csrf Form Ok Original');

        $I->amOnPage('/admin/product/inventory/update/' . $product->getId());
        $token = (string) $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/product/inventory/update/' . $product->getId(), [
            '_token' => $token,
            'name' => 'Csrf Form Ok Renamed',
            'sku' => 'CSRF-FORM-OK',
        ]);

        $I->seeInRepository(ProductCore::class, [
            'id' => $product->getId(),
            'name' => 'Csrf Form Ok Renamed',
        ]);
    }
}
