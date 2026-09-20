<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CustomerUser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Province/country validation on the customer address form.
 *
 * The dropdown is only a suggestion — any client can post whatever it likes — so these tests post
 * directly rather than through the form. That matters because a province the app does not recognise
 * reaches no tax calculator, and OrderTaxBreakdownService::safeCalculateTax() then swallows the
 * failure and invoices the order at $0 tax. Rejecting it on write is what actually closes that.
 */
final class CustomerAddressRegionValidationCest
{
    private Company $company;

    public function _before(FunctionalTester $I): void
    {
        $this->company = (new Company())
            ->setName('Region Validation Co')
            ->setCode('RGN-' . uniqid());
        $I->haveInRepository($this->company);

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        // Owner: /company-addresses is owner-only since #522 §3, and these cases are about province
        // and country validation, not about the role gate.
        $customer = (new CustomerUser())
            ->setEmail('region-validation-test@example.test')
            ->setCompany($this->company)
            ->setRoles(['ROLE_COMPANY_OWNER']);
        $customer->setPassword($hasher->hashPassword($customer, 'test-password-123'));
        $I->haveInRepository($customer);

        $I->amLoggedInAs($customer, 'main');
    }

    /** @param array<string, string> $overrides */
    private function post(FunctionalTester $I, array $overrides): void
    {
        $I->amOnPage('/company-addresses/new');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/company-addresses/new', array_merge([
            '_token' => $token,
            'label' => 'Posted',
            'address1' => '1 Test Way',
            'city' => 'Vancouver',
            'postal_code' => 'V5K0A1',
        ], $overrides));
    }

    public function theFormOffersProvincesAsADropdownNotFreeText(FunctionalTester $I): void
    {
        $I->amOnPage('/company-addresses/new');
        $I->seeResponseCodeIsSuccessful();

        // Was an <input> — a customer could type anything, which is how unrecognised provinces got in.
        $I->seeElement('select[name="province"]');
        $I->dontSeeElement('input[name="province"]');
        $I->seeElement('select[name="country"]');
    }

    public function theDropdownIsBuiltFromTheReferenceTable(FunctionalTester $I): void
    {
        $I->amOnPage('/company-addresses/new');

        $I->see('British Columbia');
        $I->see('Nunavut');
        $I->seeElement('select[name="province"] option[value="BC"]');
        $I->seeElement('select[name="country"] option[value="CA"]');
        $I->seeElement('select[name="country"] option[value="US"]');
    }

    public function aValidProvinceIsStoredAsItsCode(FunctionalTester $I): void
    {
        $this->post($I, ['country' => 'CA', 'province' => 'BC']);

        $I->seeInRepository(CompanyAddress::class, [
            'company' => $this->company,
            'label' => 'Posted',
            'province' => 'BC',
            'country' => 'CA',
        ]);
    }

    public function aDisplayNameIsAcceptedAndNormalisedToACode(FunctionalTester $I): void
    {
        // Anything already stored as a name, or any older client, still works — and lands as a code.
        $this->post($I, ['country' => 'Canada', 'province' => 'British Columbia']);

        $I->seeInRepository(CompanyAddress::class, ['label' => 'Posted', 'province' => 'BC', 'country' => 'CA']);
    }

    public function anUnrecognisedProvinceIsRejected(FunctionalTester $I): void
    {
        $this->post($I, ['country' => 'CA', 'province' => 'B.C.']);

        $I->dontSeeInRepository(CompanyAddress::class, ['label' => 'Posted']);
        $I->see('Please choose a province or state from the list.');
    }

    public function aProvinceFromTheWrongCountryIsRejected(FunctionalTester $I): void
    {
        // Texas is real, but not in Canada — and it would silently match no Canadian tax calculator.
        $this->post($I, ['country' => 'CA', 'province' => 'TX']);

        $I->dontSeeInRepository(CompanyAddress::class, ['label' => 'Posted']);
        $I->see('Please choose a province or state from the list.');
    }

    public function anUnrecognisedCountryIsRejected(FunctionalTester $I): void
    {
        $this->post($I, ['country' => 'Atlantis', 'province' => 'BC']);

        $I->dontSeeInRepository(CompanyAddress::class, ['label' => 'Posted']);
        $I->see('Please choose a country.');
    }

    public function anOmittedCountryDefaultsToCanadaRatherThanFailing(FunctionalTester $I): void
    {
        // The form always preselects Canada, and the normalisation migration treats blank rows the
        // same way, so a client that never sends the field must still validate its province.
        $this->post($I, ['province' => 'ON']);

        $I->seeInRepository(CompanyAddress::class, ['label' => 'Posted', 'province' => 'ON', 'country' => 'CA']);
    }

    public function aUsStateIsAcceptedForAUsAddress(FunctionalTester $I): void
    {
        $this->post($I, ['country' => 'US', 'province' => 'Texas']);

        $I->seeInRepository(CompanyAddress::class, ['label' => 'Posted', 'province' => 'TX', 'country' => 'US']);
    }
}
