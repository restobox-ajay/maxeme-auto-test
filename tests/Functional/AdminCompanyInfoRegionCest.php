<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\AppSetting;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The seller's own state/province and country on /admin/settings/company-information were the last
 * two free-text region fields left after the region single-source-of-truth work — every address form
 * became a dropdown, this page was missed. Free text here reaches invoices, where it is rendered
 * through province_name(), which echoes back anything it cannot resolve.
 *
 * Like the other config Cests, this is one round-trip per behaviour rather than a "shows default"
 * test: the suite shares one SQLite connection across the run, so asserting a literal starting value
 * would depend on execution order relative to anything else that writes these settings.
 */
final class AdminCompanyInfoRegionCest
{
    private const URL = '/admin/settings/company-information';

    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('company-info-region-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    /** @param array<string, string> $overrides */
    private function post(FunctionalTester $I, array $overrides): void
    {
        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage(self::URL);
        $I->seeResponseCodeIsSuccessful();

        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        // Relative-path POST rather than submitForm: with a custom Host header the browser module
        // resolves a crawled form action as an absolute URL and trips its external-URL guard.
        $I->sendAjaxPostRequest(self::URL, $overrides + ['_token' => $token]);
    }

    public function bothRegionFieldsAreDropdownsNotFreeText(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage(self::URL);
        $I->seeResponseCodeIsSuccessful();

        $I->seeElement('select[name="company_state"]');
        $I->seeElement('select[name="company_country"]');
        $I->dontSeeElement('input[name="company_state"]');
        $I->dontSeeElement('input[name="company_country"]');
    }

    public function aValidSelectionIsStoredAsACode(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $this->post($I, ['company_country' => 'CA', 'company_state' => 'BC']);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(AppSetting::class, ['settingKey' => 'company_country', 'settingValue' => 'CA']);
        $I->seeInRepository(AppSetting::class, ['settingKey' => 'company_state', 'settingValue' => 'BC']);
    }

    public function aDisplayNameIsNormalisedToItsCode(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        // What the old free-text field would have left behind, arriving via a hand-built POST.
        $this->post($I, ['company_country' => 'Canada', 'company_state' => 'British Columbia']);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(AppSetting::class, ['settingKey' => 'company_country', 'settingValue' => 'CA']);
        $I->seeInRepository(AppSetting::class, ['settingKey' => 'company_state', 'settingValue' => 'BC']);
    }

    public function aProvinceFromTheWrongCountryIsRejected(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $this->post($I, ['company_country' => 'CA', 'company_state' => 'BC']);

        // Oregon is a US state; with Canada selected it must not be stored.
        $this->post($I, ['company_country' => 'CA', 'company_state' => 'OR', 'company_city' => 'Portland']);

        $I->seeInRepository(AppSetting::class, ['settingKey' => 'company_state', 'settingValue' => 'BC']);
        $I->dontSeeInRepository(AppSetting::class, ['settingKey' => 'company_state', 'settingValue' => 'OR']);

        // The whole submission is refused, not partially applied — otherwise the city would have
        // been saved against a province that was thrown away.
        $I->dontSeeInRepository(AppSetting::class, ['settingKey' => 'company_city', 'settingValue' => 'Portland']);
    }

    public function anUnknownCountryIsRejected(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $this->post($I, ['company_country' => 'CA', 'company_state' => 'BC']);
        $this->post($I, ['company_country' => 'Freedonia', 'company_state' => 'BC']);

        $I->seeInRepository(AppSetting::class, ['settingKey' => 'company_country', 'settingValue' => 'CA']);
        $I->dontSeeInRepository(AppSetting::class, ['settingKey' => 'company_country', 'settingValue' => 'Freedonia']);
    }

    public function clearingBothIsAllowed(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $this->post($I, ['company_country' => 'CA', 'company_state' => 'BC']);
        $this->post($I, ['company_country' => '', 'company_state' => '']);
        $I->seeResponseCodeIsSuccessful();

        // Blank is a legitimate answer: the invoice omits the line rather than printing a
        // placeholder. upsertAppSetting() stores an empty submission as NULL, not ''.
        $I->seeInRepository(AppSetting::class, ['settingKey' => 'company_country', 'settingValue' => null]);
        $I->seeInRepository(AppSetting::class, ['settingKey' => 'company_state', 'settingValue' => null]);
    }

    public function aProvinceWithNoCountryIsNotStored(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $this->post($I, ['company_country' => '', 'company_state' => 'BC']);
        $I->seeResponseCodeIsSuccessful();

        // A province is only meaningful inside a country, and the form renders an empty province
        // list until a country is picked.
        $I->seeInRepository(AppSetting::class, ['settingKey' => 'company_state', 'settingValue' => null]);
    }

    public function theStoredValueComesBackSelected(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $this->post($I, ['company_country' => 'US', 'company_state' => 'WA']);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage(self::URL);
        $I->seeOptionIsSelected('select[name="company_country"]', 'United States');
        $I->seeOptionIsSelected('select[name="company_state"]', 'Washington');
    }
}
