<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\CustomerUser;
use App\Entity\FulfillmentRegion;
use App\Entity\ProductCategory;
use App\Entity\ProductCore;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Covers HomeController's real HTTP path ('/' => customer_home): the guest-blocked vs
 * guest-visible-region branches, and the logged-in-customer path with an active company
 * fulfillment region, including the featured category/uncategorized-count roll-up that
 * AbstractCustomerController builds from the real database.
 */
final class CustomerHomeCest
{
    public function guestWithNoVisibleRegionSeesBlockedMessage(FunctionalTester $I): void
    {
        $I->amOnPage('/');
        $I->seeResponseCodeIsSuccessful();
        $I->see('No fulfillment region is currently enabled for your account.');
        $I->see('No default category');
    }

    public function guestWithVisibleRegionSeesFeaturedCategoryAndProducts(FunctionalTester $I): void
    {
        $region = (new FulfillmentRegion())->setName('Guest Warehouse')->setStatus('Active')->setGuestVisible(true);
        $I->haveInRepository($region);

        $category = (new ProductCategory())->setName('Widgets')->setStatus('Visible');
        $I->haveInRepository($category);

        $product = (new ProductCore())
            ->setSku('HOME-FUNC-SKU')
            ->setName('Home Page Functional Product')
            ->setCategory($category)
            ->setFeatured(true)
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $I->amOnPage('/');
        $I->seeResponseCodeIsSuccessful();
        $I->dontSee('No fulfillment region is currently enabled for your account.');
        // Read out of the featured-category panel (#627): see('1 active wholesale products') is a
        // substring match, so '11 active wholesale products' — or '101' — satisfied it too.
        $I->assertSame('Widgets', trim($I->grabTextFrom('.market-feature strong')), 'product_category.name');
        $I->assertSame(
            '1 active wholesale products',
            trim($I->grabTextFrom('.market-feature p')),
            'one product_core row in that category',
        );
        $I->see('Home Page Functional Product');
    }

    public function loggedInCustomerWithActiveRegionSeesCatalog(FunctionalTester $I): void
    {
        $region = (new FulfillmentRegion())->setName('East Warehouse')->setStatus('Active');
        $I->haveInRepository($region);

        $company = (new Company())->setName('Acme Co')->setCode('ACME-' . uniqid());
        $I->haveInRepository($company);

        $companyRegion = (new CompanyFulfillmentRegion())
            ->setCompany($company)
            ->setFulfillmentRegion($region)
            ->setStatus('Active');
        $I->haveInRepository($companyRegion);

        $product = (new ProductCore())->setSku('HOME-FUNC-SKU-2')->setName('Customer Home Product')->setFeatured(true)->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $customer = (new CustomerUser())->setEmail('home-functional-test@example.test')->setCompany($company);
        $customer->setPassword($hasher->hashPassword($customer, 'test-password-123'));
        $I->haveInRepository($customer);

        $I->amLoggedInAs($customer, 'main');

        $I->amOnPage('/');
        $I->seeResponseCodeIsSuccessful();
        $I->dontSee('No fulfillment region is currently enabled for your account.');
        $I->see('Customer Home Product');
    }
}
