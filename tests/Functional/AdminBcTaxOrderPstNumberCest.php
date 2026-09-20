<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\SalesOrder;
use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CustomFieldDefinition;
use App\Entity\ProductCore;
use App\Repository\BundleStatusRepository;
use App\Repository\CustomFieldDefinitionRepository;
use App\Repository\CustomFieldValueRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use TaxBCBundle\EventSubscriber\BCPstNumberFieldSubscriber;
use TaxBCBundle\Tax\BCTaxCalculator;
use Tests\Support\FunctionalTester;

/**
 * PST # moved off Company/core entirely (#124) into TaxBCBundle's own bc_pst_number
 * (company, source of truth) and bc_pst_number_on_order (order, frozen snapshot) custom
 * fields — the same shape FeeBCTireBundle already uses for TSBC #. These tests exercise
 * it over real HTTP through OrderController::create()/edit(), the same way
 * AdminSalesOrderSaveDraftCest does, so they catch wiring mistakes a unit test on the
 * calculator alone would miss (TaxCalculatorResolver::applyOrderSnapshots() actually
 * being called, the admin_order_detail_info injection point actually being registered).
 *
 * Every test bails out early if TaxBCBundle is Inactive: nothing here is this bundle's
 * fault if an operator has switched it off, so there's nothing to assert.
 */
final class AdminBcTaxOrderPstNumberCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-bctax-order-functional-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        // BCPstNumberFieldSubscriber registers its custom field definitions lazily on
        // kernel.request — amLoggedInAs() is a fake login that doesn't route through the
        // kernel, so a real page visit is needed before any findBySlug() lookup below.
        $I->amOnPage('/admin/order');
    }

    /** TaxBCBundle's Active/Inactive kill-switch (Bundle Management) — skip everything if it's off. */
    private function bcTaxBundleIsActive(FunctionalTester $I): bool
    {
        return $I->grabService(BundleStatusRepository::class)->isActive('TaxBCBundle');
    }

    private function makeCompany(FunctionalTester $I): Company
    {
        $company = (new Company())
            ->setName('BC Tax Order Test Co')
            ->setCode('BCTAXORD-' . uniqid())
            ->setPrimaryEmail('buyer@bctax-order.example');
        $I->haveInRepository($company);
        // Creating an order needs an active fulfillment region since #237 — it resolves the
        // company's price list, and a company without one cannot be priced.
        $I->haveActiveFulfillmentRegionFor($company);

        return $company;
    }

    private function makeAddress(FunctionalTester $I, Company $company, string $province): CompanyAddress
    {
        $address = (new CompanyAddress())
            ->setCompany($company)
            ->setLabel('Main')
            ->setFirstName('Ada')
            ->setLastName('Lovelace')
            ->setAddressLine1('1 Dock Road')
            ->setCity('Test City')
            ->setProvince($province)
            ->setCountry('CA')
            ->setIsDefaultShipping(true)
            ->setIsDefaultBilling(true);
        $I->haveInRepository($address);

        return $address;
    }

    /**
     * Seeds the company's own bc_pst_number custom field value directly — the definition is
     * registered lazily by BCPstNumberFieldSubscriber on kernel.request, so a page must be
     * hit at least once first (loginAsAdmin() already does this).
     */
    private function setCompanyPstNumber(FunctionalTester $I, Company $company, string $pstNumber): void
    {
        // grabEntityFromRepository() (not $I->grabService(...)->findBySlug()) so the definition
        // is fetched through Codeception's own entity manager handle — reusing an entity fetched
        // via the app's injected EntityManagerInterface across an HTTP request boundary (a prior
        // sendAjaxPostRequest()) makes haveInRepository() below see it as an unmanaged new entity.
        $definition = $I->grabEntityFromRepository(CustomFieldDefinition::class, [
            'objectType' => CustomFieldDefinition::OBJECT_TYPE_COMPANY,
            'slug' => BCPstNumberFieldSubscriber::SLUG,
        ]);
        $I->assertNotNull($definition, 'BCPstNumberFieldSubscriber should have registered bc_pst_number by now');

        // setValue() resolves the company by id through its own EntityManager reference rather
        // than needing $company attached to Codeception's current EM handle — the same reason
        // $definition above is re-fetched instead of reused across the request boundary.
        $I->grabService(CustomFieldValueRepository::class)->setValue($definition, (int) $company->getId(), $pstNumber);
        $I->grabService(EntityManagerInterface::class)->flush();
    }

    private function createOrderViaHttp(FunctionalTester $I, Company $company, CompanyAddress $address, ProductCore $product): SalesOrder
    {
        $I->amOnPage('/admin/order/create?OrderSearch[company_id]=' . $company->getId());
        $I->sendAjaxPostRequest('/admin/order/create', [
            '_token' => $I->csrfToken(),
            'company_id' => (string) $company->getId(),
            'billing_address_id' => (string) $address->getId(),
            'shipping_address_id' => (string) $address->getId(),
            'lines' => [[
                'product_id' => (string) $product->getId(),
                'qty' => '1',
                'price' => '10.00',
            ]],
            'save_mode' => 'draft_exit',
        ]);

        return $I->grabEntityFromRepository(SalesOrder::class, ['company' => $company->getId()]);
    }

    public function bcOrderForACompanyWithNoPstNumberShowsADashOnTheAdminDetailPage(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        if (!$this->bcTaxBundleIsActive($I)) {
            return;
        }

        $company = $this->makeCompany($I);
        $address = $this->makeAddress($I, $company, 'BC');
        $product = (new ProductCore())->setSku('BCTAXORD-SKU-1')->setName('BC Tax Order Test Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $order = $this->createOrderViaHttp($I, $company, $address, $product);

        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('PST #:');
        // Asserted against the row's own markup, not `see('-')` (#594). The page is full of
        // hyphens — the fixture SKU is BCTAXORD-SKU-1, the company code carries one, so do the
        // dates and the order number — so `see('-')` was satisfied by every possible rendering,
        // including a blank cell, the string "null", or another company's PST number.
        $I->seeInSource('<label>PST #:</label><strong>-</strong>');
    }

    public function bcOrderForACompanyWithAPstNumberShowsItOnTheAdminDetailPage(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        if (!$this->bcTaxBundleIsActive($I)) {
            return;
        }

        $company = $this->makeCompany($I);
        $this->setCompanyPstNumber($I, $company, 'PST-777888');
        $address = $this->makeAddress($I, $company, 'BC');
        $product = (new ProductCore())->setSku('BCTAXORD-SKU-2')->setName('BC Tax Order Test Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $order = $this->createOrderViaHttp($I, $company, $address, $product);

        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('PST #:');
        $I->see('PST-777888');
    }

    public function nonBcOrderShowsNoPstNumberRowAtAll(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        if (!$this->bcTaxBundleIsActive($I)) {
            return;
        }

        $company = $this->makeCompany($I);
        $this->setCompanyPstNumber($I, $company, 'PST-777888');
        $address = $this->makeAddress($I, $company, 'ON');
        $product = (new ProductCore())->setSku('BCTAXORD-SKU-3')->setName('BC Tax Order Test Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $order = $this->createOrderViaHttp($I, $company, $address, $product);

        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->dontSee('PST #:');
    }

    /**
     * BCTaxCalculator charges PST only when the customer has no PST number on file, so the
     * order-time snapshot has to stay frozen — otherwise a customer registering for PST later
     * would silently rewrite what every past order's detail page claims explains its tax.
     */
    public function thePstNumberOnTheOrderStaysFrozenAfterTheCompanysOwnNumberChanges(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        if (!$this->bcTaxBundleIsActive($I)) {
            return;
        }

        $company = $this->makeCompany($I);
        $this->setCompanyPstNumber($I, $company, 'PST-ORIGINAL');
        $address = $this->makeAddress($I, $company, 'BC');
        $product = (new ProductCore())->setSku('BCTAXORD-SKU-4')->setName('BC Tax Order Test Product')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $I->haveInRepository($product);

        $order = $this->createOrderViaHttp($I, $company, $address, $product);

        // The company re-registers under a different number after the order was placed.
        $this->setCompanyPstNumber($I, $company, 'PST-CHANGED-LATER');

        $I->amOnPage('/admin/order/detail/' . $order->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('PST-ORIGINAL');
        $I->dontSee('PST-CHANGED-LATER');
    }

    /** Confirms BCTaxCalculator::SOURCE lines up with what BCPstNumberFieldSubscriber registers under. */
    public function theCustomFieldDefinitionsAreOwnedByTheBcTaxBundle(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        if (!$this->bcTaxBundleIsActive($I)) {
            return;
        }

        $definitionRepo = $I->grabService(CustomFieldDefinitionRepository::class);

        $company = $definitionRepo->findBySlug(CustomFieldDefinition::OBJECT_TYPE_COMPANY, BCPstNumberFieldSubscriber::SLUG);
        $order = $definitionRepo->findBySlug(CustomFieldDefinition::OBJECT_TYPE_ORDER, BCPstNumberFieldSubscriber::ORDER_SNAPSHOT_SLUG);

        $I->assertNotNull($company);
        $I->assertSame(BCTaxCalculator::SOURCE, $company->getSource());
        $I->assertTrue($company->isVisibleOnAdd());
        $I->assertTrue($company->isVisibleOnEdit());

        $I->assertNotNull($order);
        $I->assertSame(BCTaxCalculator::SOURCE, $order->getSource());
        $I->assertFalse($order->isVisibleOnAdd());
        $I->assertFalse($order->isVisibleOnEdit());
        $I->assertFalse($order->isVisibleOnListing());
    }
}
