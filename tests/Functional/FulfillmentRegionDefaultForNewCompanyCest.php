<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\AppSetting;
use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\CustomerUser;
use App\Entity\FulfillmentRegion;
use App\Entity\PriceList;
use App\Service\AppSettings;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Covers FulfillmentRegion::$defaultForNewCompany end to end — the flag itself on the region form,
 * and the two consumers that read it: the /admin/company/create region checklist and customer
 * registration.
 *
 * The flag is a convenience, not an automation: it ticks the box so an admin does not forget a
 * region, and pairs it with the store-wide company_registration_default_price_list_id because a
 * CompanyFulfillmentRegion cannot be Active without a price list
 * (CompanyFulfillmentRegionService::validateActivation()). That pairing is what the price-list
 * assertions below are about — a ticked box with an empty select is a form that will not save.
 */
final class FulfillmentRegionDefaultForNewCompanyCest
{
    /**
     * AppSettings caches its rows in a pool outside the per-test transaction, so the registration
     * default price list one test writes would still be visible to the next after the row is gone.
     * The rate limiter's store is a filesystem cache pool with the same problem, and every
     * functional test presents the same client IP — see CustomerAuthCest.
     */
    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
        $I->grabService('cache.rate_limiter')->clear();
    }

    public function _after(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-region-default-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    private function makeRegion(FunctionalTester $I, string $name, bool $defaultForNewCompany): FulfillmentRegion
    {
        $region = (new FulfillmentRegion())->setName($name)->setStatus('Active')->setDefaultForNewCompany($defaultForNewCompany);
        $I->haveInRepository($region);

        return $region;
    }

    private function makePriceList(FunctionalTester $I, string $name): PriceList
    {
        $priceList = (new PriceList())->setName($name)->setCurrency('CAD')->setStatus('Active');
        $I->haveInRepository($priceList);

        return $priceList;
    }

    private function setSetting(FunctionalTester $I, string $key, string $value): void
    {
        $setting = (new AppSetting())->setSettingKey($key)->setName($key)->setSettingValue($value);
        $I->haveInRepository($setting);
        $I->grabService(AppSettings::class)->clearCache();
    }

    /** @return array<string, string> */
    private function validRegistrationPayload(string $userEmail): array
    {
        return [
            'company_name' => 'Region Default Supply Co',
            'company_email' => 'orders@region-default-supply.test',
            'first_name' => 'Pat',
            'last_name' => 'Owner',
            'user_email' => $userEmail,
            'user_phone' => '555-0150',
            'password' => 'a-strong-password-1',
            'confirm_password' => 'a-strong-password-1',
            'agree_terms' => '1',
            'ship_address1' => '100 Main St',
            'ship_city' => 'Calgary',
            'ship_province' => 'Alberta',
            'ship_country' => 'Canada',
            'ship_postal' => 'T2P 1J9',
            'bill_same' => '1',
        ];
    }

    /** Round trip through the region form, on and back off — the "off" half is what the paired
     *  hidden input exists for, since an unchecked box posts nothing at all without JavaScript. */
    public function theRegionFormSavesAndClearsTheFlag(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/fulfillment-region/create');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[type="checkbox"][name="default_for_new_company"]');

        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/fulfillment-region/create', [
            '_token' => $token,
            'name' => 'Region Default Form Test',
            'status' => 'Active',
            'default_for_new_company' => '1',
            // Creating a region creates the warehouse it draws stock from, and since queue item 61
            // a warehouse may not exist without a province — so the create form asks for one and
            // refuses without it. Nothing about the region is stored from these two; they are the
            // building's address, and a region may legitimately span several provinces.
            'warehouse_province' => 'BC',
            'warehouse_country' => 'CA',
        ]);
        $I->seeResponseCodeIsSuccessful();

        /** @var FulfillmentRegion $region */
        $region = $I->grabEntityFromRepository(FulfillmentRegion::class, ['name' => 'Region Default Form Test']);
        $I->assertTrue($region->isDefaultForNewCompany());

        // The list column, its filter and its sort field all moved off the old "Default?" column,
        // which showed the lowest-id region and had nothing to do with any stored flag.
        $I->amOnPage('/admin/fulfillment-region?sort=defaultForNewCompany&dir=desc&filters[defaultForNewCompany]=Yes');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Default On For New Customer');
        $I->see('Region Default Form Test');
        $I->dontSee('Default?');

        $I->amOnPage('/admin/fulfillment-region/' . $region->getId() . '/update');
        $I->seeCheckboxIsChecked('input[type="checkbox"][name="default_for_new_company"]');

        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/fulfillment-region/' . $region->getId() . '/update', [
            '_token' => $token,
            'name' => 'Region Default Form Test',
            'status' => 'Active',
            // Exactly what the browser posts with the box cleared: the hidden input's value alone.
            'default_for_new_company' => '0',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(FulfillmentRegion::class, ['id' => $region->getId(), 'defaultForNewCompany' => false]);
    }

    public function companyCreatePreChecksAFlaggedRegionWithTheDefaultPriceList(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $priceList = $this->makePriceList($I, 'Region Default Create List');
        $this->setSetting($I, 'company_registration_default_price_list_id', (string) $priceList->getId());
        $flagged = $this->makeRegion($I, 'Region Default Create Flagged', true);
        $plain = $this->makeRegion($I, 'Region Default Create Plain', false);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/company/create');
        $I->seeResponseCodeIsSuccessful();

        $I->seeCheckboxIsChecked('input[name="regions[' . $flagged->getId() . '][active]"]');
        $I->seeElement('select[name="regions[' . $flagged->getId() . '][price_list_id]"] option[value="' . $priceList->getId() . '"][selected]');

        $I->dontSeeCheckboxIsChecked('input[name="regions[' . $plain->getId() . '][active]"]');
        $I->dontSeeElement('select[name="regions[' . $plain->getId() . '][price_list_id]"] option[selected]');
    }

    /** Editing a company reflects the pivots it already has; the flag is about *new* companies and
     *  must not silently re-tick a region an admin turned off. */
    public function editingAnExistingCompanyIsNotOverriddenByTheFlag(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $priceList = $this->makePriceList($I, 'Region Default Edit List');
        $this->setSetting($I, 'company_registration_default_price_list_id', (string) $priceList->getId());
        $flagged = $this->makeRegion($I, 'Region Default Edit Flagged', true);

        $company = (new Company())->setName('Region Default Edit Co')->setCode('RDEDIT01');
        $I->haveInRepository($company);
        $I->haveInRepository((new CompanyFulfillmentRegion())
            ->setCompany($company)
            ->setFulfillmentRegion($flagged)
            ->setStatus('Inactive'));

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/company/update/' . $company->getId());
        $I->seeResponseCodeIsSuccessful();

        $I->dontSeeCheckboxIsChecked('input[name="regions[' . $flagged->getId() . '][active]"]');
        $I->dontSeeElement('select[name="regions[' . $flagged->getId() . '][price_list_id]"] option[selected]');
    }

    /** The review path used to attach no regions at all — the whole point of the flag is that a
     *  company waiting for approval already carries the regions the admin said everyone gets. */
    public function aRegistrationHeldForReviewGetsTheFlaggedRegionAttached(FunctionalTester $I): void
    {
        $priceList = $this->makePriceList($I, 'Region Default Register List');
        $this->setSetting($I, 'company_registration_default_price_list_id', (string) $priceList->getId());
        $flagged = $this->makeRegion($I, 'Region Default Register Flagged', true);
        $this->makeRegion($I, 'Region Default Register Plain', false);

        $I->amOnPage('/auth/register');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendFormPostRequest('/auth/register', array_merge(
            $this->validRegistrationPayload('region-default-review-test@example.test'),
            ['_token' => $token],
        ));
        $I->seeResponseCodeIsSuccessful();

        /** @var CustomerUser $user */
        $user = $I->grabEntityFromRepository(CustomerUser::class, ['email' => 'region-default-review-test@example.test']);
        $company = $user->getCompany();
        $I->assertSame('Review', $company->getStatus());

        $rows = $I->grabEntitiesFromRepository(CompanyFulfillmentRegion::class, ['company' => $company->getId()]);
        $I->assertCount(1, $rows);
        $I->assertSame($flagged->getId(), $rows[0]->getFulfillmentRegion()->getId());
        $I->assertSame('Active', $rows[0]->getStatus());
        $I->assertSame($priceList->getId(), $rows[0]->getPriceList()?->getId());
    }

    /** No flag anywhere: the auto-approve settings still drive the one row they always did. */
    public function withNothingFlaggedTheAutoApproveFallbackStillAttachesItsRegion(FunctionalTester $I): void
    {
        $priceList = $this->makePriceList($I, 'Region Default Fallback List');
        $region = $this->makeRegion($I, 'Region Default Fallback Region', false);
        $this->setSetting($I, 'company_registration_mode', 'auto');
        $this->setSetting($I, 'company_registration_default_price_list_id', (string) $priceList->getId());
        $this->setSetting($I, 'company_registration_default_fulfillment_region_id', (string) $region->getId());

        $I->amOnPage('/auth/register');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendFormPostRequest('/auth/register', array_merge(
            $this->validRegistrationPayload('region-default-fallback-test@example.test'),
            ['_token' => $token],
        ));
        $I->seeResponseCodeIsSuccessful();

        /** @var CustomerUser $user */
        $user = $I->grabEntityFromRepository(CustomerUser::class, ['email' => 'region-default-fallback-test@example.test']);
        $company = $user->getCompany();
        $I->assertSame('Active', $company->getStatus());

        $rows = $I->grabEntitiesFromRepository(CompanyFulfillmentRegion::class, ['company' => $company->getId()]);
        $I->assertCount(1, $rows);
        $I->assertSame($region->getId(), $rows[0]->getFulfillmentRegion()->getId());
        $I->assertSame('Active', $rows[0]->getStatus());
        $I->assertSame($priceList->getId(), $rows[0]->getPriceList()?->getId());
    }
}
