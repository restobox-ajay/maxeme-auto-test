<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\ProductCore;
use ProcurementBundle\Entity\Vendor;
use ProcurementBundle\Entity\VendorPrice;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * VendorPriceController's manual Add/Edit screen, on the real HTTP path.
 *
 * saveOnAnExistingRowDoesNotThrowOnTheDefaultOrderMultiple() is the one that would have caught a
 * real regression: every existing VendorPrice's order_multiple is the canonical decimal string
 * "1.0000" (VendorPrice::$orderMultiple's own default), and the edit form pre-fills that value
 * straight back into its <input>. save() used to read it with Request::getInt(), which 400s on
 * "1.0000" — not a clean integer string — so saving ANY existing row, unchanged, was broken. A
 * PHPUnit test constructing the controller directly never posts real request data and would not
 * have seen it; only a real form submission does.
 */
final class AdminVendorPriceCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-vendor-price-functional-test@example.test');
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

    private function makeProduct(FunctionalTester $I, string $sku): ProductCore
    {
        $product = (new ProductCore())->setSku($sku)->setName('Vendor Price Test Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        return $product;
    }

    public function creatingANewPriceThroughTheFormSavesTheVendorStockField(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->makeVendor($I, 'New Price Vendor');
        $product = $this->makeProduct($I, 'VPRICE-NEW-1');

        $I->amOnPage('/admin/bundles/procurement/vendor-prices/new?vendor_id=' . $vendor->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[name="available_quantity"]');

        $I->sendFormPostRequest('/admin/bundles/procurement/vendor-prices/save', [
            '_token' => $I->csrfToken(),
            'id' => '0',
            'vendor_id' => (string) $vendor->getId(),
            'product_id' => (string) $product->getId(),
            'vendor_sku' => 'VS-NEW-1',
            'unit_cost' => '4.5000',
            'currency' => 'CAD',
            'order_multiple' => '1',
            'available_quantity' => '30',
            'is_active' => '1',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $price = $I->grabEntityFromRepository(VendorPrice::class, ['vendor' => $vendor, 'product' => $product]);
        $I->assertInstanceOf(VendorPrice::class, $price);
        $I->assertEqualsWithDelta(30.0, (float) $price->getAvailableQuantity(), 0.0001);
    }

    /** The exact scenario that used to 400: an existing row's own pre-filled order_multiple. */
    public function savingAnExistingRowUnchangedDoesNotThrowOnTheDefaultOrderMultiple(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->makeVendor($I, 'Existing Price Vendor');
        $product = $this->makeProduct($I, 'VPRICE-EXISTING-1');
        $price = (new VendorPrice())->setVendor($vendor)->setProduct($product)->setUnitCost('2.0000');
        $I->haveInRepository($price);
        // Real default, never explicitly set — this is what the form pre-fills and posts back.
        $I->assertSame('1.0000', $price->getOrderMultiple());

        $I->amOnPage('/admin/bundles/procurement/vendor-prices/' . $price->getId() . '/edit');
        $I->seeResponseCodeIsSuccessful();
        $I->seeInField('order_multiple', '1.0000');

        $I->sendFormPostRequest('/admin/bundles/procurement/vendor-prices/save', [
            '_token' => $I->csrfToken(),
            'id' => (string) $price->getId(),
            'vendor_id' => (string) $vendor->getId(),
            'product_id' => (string) $product->getId(),
            'vendor_sku' => '',
            'unit_cost' => '2.0000',
            'currency' => 'CAD',
            'order_multiple' => '1.0000',
            'available_quantity' => '85',
            'is_active' => '1',
        ]);

        // The regression: this used to be a 400 BadRequestException, never reaching the redirect.
        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlMatches('#/admin/bundles/procurement/vendor-prices#');

        $reloaded = $I->grabEntityFromRepository(VendorPrice::class, ['id' => $price->getId()]);
        $I->assertEqualsWithDelta(85.0, (float) $reloaded->getAvailableQuantity(), 0.0001);
    }

    public function theVendorPricesGridShowsTheVendorStockColumn(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $vendor = $this->makeVendor($I, 'Grid Stock Vendor');
        $product = $this->makeProduct($I, 'VPRICE-GRID-1');
        $price = (new VendorPrice())->setVendor($vendor)->setProduct($product)->setUnitCost('1.0000')->setAvailableQuantity('42');
        $I->haveInRepository($price);

        $I->amOnPage('/admin/bundles/procurement/vendor-prices?filters[vendor_id]=' . $vendor->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('Vendor stock');
        $I->see('42.0000');
    }
}
