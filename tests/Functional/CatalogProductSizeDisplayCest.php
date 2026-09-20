<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\CustomerUser;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Covers the "Size" column on the customer catalog listing (customer/catalog/_products.html.twig,
 * `product.size`, only rendered for a logged-in customer — `{% if app.user %}`). number1_inventory
 * shows a real populated size value for tire products, but our ProductCore has no distinct size
 * column of its own. AbstractCustomerController::customerProductRow() previously always fell back
 * to the unrelated `weight` field, which reads blank for these products.
 *
 * Confirmed by comparing live output against the reference app: its `size` column is NOT a
 * human "255/45R19"-style substring — it's the all-digits concatenation of the WHOLE product
 * text (model number and load/speed index included), e.g. "DURINGON DP810 255/45ZR19 104W"
 * shows as "8102554519104" (see App\Service\TireSizeExtractor). This is now reproduced here,
 * falling back to `weight` only when the text doesn't look like a tire spec at all.
 */
final class CatalogProductSizeDisplayCest
{
    private FulfillmentRegion $region;
    private Company $company;

    public function _before(FunctionalTester $I): void
    {
        $this->region = (new FulfillmentRegion())->setName('Tire Size Display Warehouse')->setStatus('Active');
        $I->haveInRepository($this->region);

        $this->company = (new Company())->setName('Size Display Co')->setCode('SIZE-DISPLAY-CO');
        $I->haveInRepository($this->company);

        $companyRegion = (new CompanyFulfillmentRegion())
            ->setCompany($this->company)
            ->setFulfillmentRegion($this->region)
            ->setStatus('Active');
        $I->haveInRepository($companyRegion);
    }

    private function loginAsCustomer(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $customer = (new CustomerUser())->setEmail('catalog-size-display-test@example.test')->setCompany($this->company);
        $customer->setPassword($hasher->hashPassword($customer, 'test-password-123'));
        $I->haveInRepository($customer);

        $I->amLoggedInAs($customer, 'main');
    }

    public function sizeColumnShowsTheReferenceAppsDigitCodeNotTheHumanFormattedSubstring(FunctionalTester $I): void
    {
        $product = (new ProductCore())
            ->setSku('TIRE-SIZE-DISPLAY-SKU')
            ->setName('DURINGON DP810 255/45ZR19 104W')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $this->loginAsCustomer($I);

        $I->amOnPage('/product/index?q=DURINGON');
        $I->seeResponseCodeIsSuccessful();
        // Read out of the Size cell, not off the page (#627). The whole claim of this test is
        // WHICH column the figure lands in, and see() cannot say — product.size is also emitted
        // into the card's data-search and data-size attributes, and the SKU column sits beside it.
        $I->assertSame(
            '8102554519104',
            trim($I->grabTextFrom('.product-card .product-size')),
            'product.size derived from the tire spec in product.name',
        );
    }

    public function sizeColumnFallsBackToWeightWhenTheTextDoesNotLookLikeATireSpec(FunctionalTester $I): void
    {
        $product = (new ProductCore())
            ->setSku('SIZELESS-SKU')
            ->setName('Sizeless Widget')
            ->setWeight('5kg')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $this->loginAsCustomer($I);

        $I->amOnPage('/product/index?q=Sizeless');
        $I->seeResponseCodeIsSuccessful();
        // Same again (#627): see('5kg') is satisfied by '15kg', and by a '5kg' rendered in the
        // Unit or Remarks cell beside it — which is the fallback this test exists to pin down.
        $I->assertSame(
            '5kg',
            trim($I->grabTextFrom('.product-card .product-size')),
            'product.weight standing in for product.size when the name is not a tire spec',
        );
    }
}
