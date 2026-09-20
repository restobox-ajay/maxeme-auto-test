<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CustomerUser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers Customer\CompanyAddressController — /company-addresses create/edit/delete and the
 *  default-shipping/default-billing toggle for the logged-in customer's own company. */
final class CustomerCompanyAddressCest
{
    private function makeCompany(FunctionalTester $I): Company
    {
        $company = (new Company())
            ->setName('Acme Co')
            ->setCode('ACME-' . uniqid());
        $I->haveInRepository($company);

        return $company;
    }

    /**
     * Logs in as an owner by default: since #522 every mutating action on this controller requires
     * ROLE_COMPANY_OWNER, so an actor with no explicit role would fail the gate before reaching any
     * of the behaviour the cases below are actually about. Pass ROLE_COMPANY_STAFF to exercise the
     * gate itself — see the staff cases at the bottom of this file.
     */
    private function loginAs(FunctionalTester $I, Company $company, string $role = 'ROLE_COMPANY_OWNER'): CustomerUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $customer = (new CustomerUser())
            ->setEmail('cca-' . uniqid() . '@example.test')
            ->setFirstName('Jane')
            ->setLastName('Doe')
            ->setCompany($company)
            ->setRoles([$role]);
        $customer->setPassword($hasher->hashPassword($customer, 'current-password-123'));
        $I->haveInRepository($customer);

        $I->amLoggedInAs($customer, 'main');

        return $customer;
    }

    private function makeAddress(FunctionalTester $I, Company $company, array $overrides = []): CompanyAddress
    {
        $address = (new CompanyAddress())
            ->setCompany($company)
            ->setLabel($overrides['label'] ?? 'Warehouse')
            ->setAddressLine1($overrides['address1'] ?? '1 Main St')
            ->setCity($overrides['city'] ?? 'Vancouver')
            ->setProvince($overrides['province'] ?? 'BC')
            ->setIsDefaultShipping($overrides['isDefaultShipping'] ?? false)
            ->setIsDefaultBilling($overrides['isDefaultBilling'] ?? false);
        $I->haveInRepository($address);

        return $address;
    }

    public function guestIsRedirectedToLoginFromCreate(FunctionalTester $I): void
    {
        // access_control (config/packages/security.yaml) requires ROLE_CUSTOMER on this route,
        // so the firewall redirects before the controller's own not-logged-in branch ever runs.
        $I->amOnPage('/company-addresses/new');
        $I->seeCurrentUrlEquals('/auth/login');
    }

    public function createRendersAFormForALoggedInCustomer(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $this->loginAs($I, $company);

        $I->amOnPage('/company-addresses/new');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Add Address');
    }

    public function creatingWithBlankRequiredFieldsShowsValidationErrors(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $this->loginAs($I, $company);

        $I->amOnPage('/company-addresses/new');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/company-addresses/new', [
            '_token' => $token,
            'label' => '',
            'address1' => '',
            'city' => '',
            'province' => '',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Address name is required.');
        $I->see('Address line 1 is required.');
        $I->see('City is required.');
        $I->see('Province is required.');
    }

    public function creatingWithAnInvalidCsrfTokenRedirectsWithAnErrorFlash(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $this->loginAs($I, $company);

        $I->sendAjaxPostRequest('/company-addresses/new', [
            '_token' => 'not-a-real-token',
            'label' => 'Warehouse',
            'address1' => '1 Main St',
            'city' => 'Vancouver',
            'province' => 'BC',
        ]);
        $I->seeResponseCodeIs(403);
        $I->assertStringContainsString('Your session expired', $I->grabPageSource());

        $I->dontSeeInRepository(CompanyAddress::class, ['label' => 'Warehouse']);
    }

    public function firstAddressCreatedBecomesDefaultShippingButNotDefaultBilling(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $this->loginAs($I, $company);

        $I->amOnPage('/company-addresses/new');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/company-addresses/new', [
            '_token' => $token,
            'label' => 'Main Warehouse',
            'first_name' => 'Sam',
            'last_name' => 'Receiver',
            'address1' => '100 Industrial Way',
            'city' => 'Burnaby',
            'province' => 'BC',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlEquals('/company-profile');

        $I->seeInRepository(CompanyAddress::class, [
            'company' => $company->getId(),
            'label' => 'Main Warehouse',
            'isDefaultShipping' => true,
            'isDefaultBilling' => false,
        ]);
    }

    public function creatingASecondAddressDoesNotBecomeDefaultShippingWhenOneAlreadyExists(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $this->makeAddress($I, $company, ['label' => 'Existing Default', 'isDefaultShipping' => true]);
        $this->loginAs($I, $company);

        $I->amOnPage('/company-addresses/new');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/company-addresses/new', [
            '_token' => $token,
            'label' => 'Second Address',
            'address1' => '2 Second St',
            'city' => 'Richmond',
            'province' => 'BC',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(CompanyAddress::class, [
            'company' => $company->getId(),
            'label' => 'Second Address',
            'isDefaultShipping' => false,
        ]);
    }

    public function editPrefillsTheExistingAddressFields(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $address = $this->makeAddress($I, $company, ['label' => 'Downtown Office', 'city' => 'Vancouver']);
        $this->loginAs($I, $company);

        $I->amOnPage('/company-addresses/' . $address->getId() . '/edit');
        $I->seeResponseCodeIsSuccessful();
        $I->seeInField('label', 'Downtown Office');
        $I->seeInField('city', 'Vancouver');
    }

    public function editingAnAddressFromAnotherCompanyRedirectsWithNotFound(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $otherCompany = $this->makeCompany($I);
        $otherAddress = $this->makeAddress($I, $otherCompany);
        $this->loginAs($I, $company);

        $I->amOnPage('/company-addresses/' . $otherAddress->getId() . '/edit');
        $I->seeCurrentUrlEquals('/company-profile');
        $I->see('Address not found.');
    }

    public function updatingAddressFieldsPersistsAndRedirects(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $address = $this->makeAddress($I, $company, ['label' => 'Old Label', 'city' => 'Surrey']);
        $this->loginAs($I, $company);

        $I->amOnPage('/company-addresses/' . $address->getId() . '/edit');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/company-addresses/' . $address->getId() . '/edit', [
            '_token' => $token,
            'label' => 'New Label',
            'address1' => '5 New Rd',
            'city' => 'Coquitlam',
            'province' => 'BC',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(CompanyAddress::class, [
            'id' => $address->getId(),
            'label' => 'New Label',
            'city' => 'Coquitlam',
        ]);
    }

    public function updatingWithAnInvalidCsrfTokenLeavesTheAddressUnchanged(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $address = $this->makeAddress($I, $company, ['label' => 'Unchanged Label']);
        $this->loginAs($I, $company);

        $I->sendAjaxPostRequest('/company-addresses/' . $address->getId() . '/edit', [
            '_token' => 'not-a-real-token',
            'label' => 'Hacked Label',
            'address1' => '1 Main St',
            'city' => 'Vancouver',
            'province' => 'BC',
        ]);
        $I->seeResponseCodeIs(403);
        $I->assertStringContainsString('Your session expired', $I->grabPageSource());

        $I->seeInRepository(CompanyAddress::class, ['id' => $address->getId(), 'label' => 'Unchanged Label']);
    }

    /** Scrapes a per-row action's CSRF token out of the /company-profile "All Addresses" list,
     *  since that's the only place these tokens are rendered (there's no full-page equivalent).
     *  The template (company.html.twig) always renders data on inline forms whose action ends
     *  with the given suffix, so matching on that is enough. */
    private function grabRowActionToken(FunctionalTester $I, string $actionSuffix): string
    {
        $I->amOnPage('/company-profile');
        $html = $I->grabPageSource();
        preg_match('/<form[^>]*action="[^"]*' . preg_quote($actionSuffix, '/') . '"[^>]*>\s*<input type="hidden" name="_token" value="([^"]+)"/', $html, $m);

        return $m[1] ?? '';
    }

    public function deletingAnAddressRemovesIt(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $address = $this->makeAddress($I, $company);
        $this->loginAs($I, $company);

        $csrfToken = $this->grabRowActionToken($I, '/' . $address->getId() . '/delete');

        $I->sendAjaxPostRequest('/company-addresses/' . $address->getId() . '/delete', ['_token' => $csrfToken]);
        $I->seeResponseCodeIsSuccessful();

        $I->dontSeeInRepository(CompanyAddress::class, ['id' => $address->getId()]);
    }

    public function deletingWithAnInvalidCsrfTokenLeavesTheAddressIntact(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $address = $this->makeAddress($I, $company);
        $this->loginAs($I, $company);

        $I->sendAjaxPostRequest('/company-addresses/' . $address->getId() . '/delete', ['_token' => 'not-a-real-token']);
        $I->seeResponseCodeIs(403);

        $I->seeInRepository(CompanyAddress::class, ['id' => $address->getId()]);
    }

    public function deletingTheDefaultShippingAddressPromotesAnotherNonBillingAddress(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $shipping = $this->makeAddress($I, $company, ['label' => 'Shipping', 'isDefaultShipping' => true]);
        $other = $this->makeAddress($I, $company, ['label' => 'Other']);
        $this->loginAs($I, $company);

        $csrfToken = $this->grabRowActionToken($I, '/' . $shipping->getId() . '/delete');

        $I->sendAjaxPostRequest('/company-addresses/' . $shipping->getId() . '/delete', ['_token' => $csrfToken]);
        $I->seeResponseCodeIsSuccessful();

        $I->dontSeeInRepository(CompanyAddress::class, ['id' => $shipping->getId()]);
        $I->seeInRepository(CompanyAddress::class, ['id' => $other->getId(), 'isDefaultShipping' => true]);
    }

    public function settingDefaultBillingMarksOnlyThatAddressAsDefaultBilling(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $current = $this->makeAddress($I, $company, ['label' => 'Current Billing', 'isDefaultBilling' => true]);
        $candidate = $this->makeAddress($I, $company, ['label' => 'New Billing']);
        $this->loginAs($I, $company);

        $csrfToken = $this->grabRowActionToken($I, '/' . $candidate->getId() . '/default/billing');

        $I->sendAjaxPostRequest('/company-addresses/' . $candidate->getId() . '/default/billing', ['_token' => $csrfToken]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(CompanyAddress::class, ['id' => $candidate->getId(), 'isDefaultBilling' => true]);
        $I->seeInRepository(CompanyAddress::class, ['id' => $current->getId(), 'isDefaultBilling' => false]);
    }

    public function settingDefaultWithAnInvalidTypeShowsAnErrorFlashAndDoesNotChangeAnything(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $address = $this->makeAddress($I, $company);
        $this->loginAs($I, $company);

        // The CSRF token id is keyed only by address id ('company_address_default_'.$id), not by
        // the {type} route segment, so the token rendered for the billing form is equally valid
        // against an arbitrary/invalid type — the controller checks CSRF before the type value.
        $csrfToken = $this->grabRowActionToken($I, '/' . $address->getId() . '/default/billing');

        $I->sendAjaxPostRequest('/company-addresses/' . $address->getId() . '/default/carrierpigeon', ['_token' => $csrfToken]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Address not found.');

        $I->seeInRepository(CompanyAddress::class, [
            'id' => $address->getId(),
            'isDefaultBilling' => false,
            'isDefaultShipping' => false,
        ]);
    }

    public function settingDefaultWithAnInvalidCsrfTokenLeavesAddressesUnchanged(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $address = $this->makeAddress($I, $company);
        $this->loginAs($I, $company);

        $I->sendAjaxPostRequest('/company-addresses/' . $address->getId() . '/default/billing', ['_token' => 'not-a-real-token']);
        $I->seeResponseCodeIs(403);
        $I->assertStringContainsString('Your session expired', $I->grabPageSource());

        $I->seeInRepository(CompanyAddress::class, ['id' => $address->getId(), 'isDefaultBilling' => false]);
    }

    // ---------------------------------------------------------------------------------------
    // #522 §3: the address book is owner-managed. Staff read it — on /company-profile and when
    // picking a destination at checkout — but cannot create, edit, delete or re-default, from
    // any entry point. Every case above is the matching positive: it logs in as an owner.
    // ---------------------------------------------------------------------------------------

    /**
     * CSRF runs in a subscriber ahead of the controller, so a junk token would 403 before the role
     * gate is ever reached. Staff can still load /company-profile, and tokens are global, so the
     * page they can open supplies a real one.
     */
    private function grabCsrfTokenAsStaff(FunctionalTester $I): string
    {
        $I->amOnPage('/company-profile');
        preg_match('/name="_token" value="([^"]+)"/', $I->grabPageSource(), $m);

        return $m[1] ?? '';
    }

    public function staffCannotCreateAnAddress(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $this->loginAs($I, $company, 'ROLE_COMPANY_STAFF');

        $I->amOnPage('/company-addresses/new');
        $I->seeCurrentUrlEquals('/company-profile');
        $I->see('Only a company owner can manage company addresses.');

        $I->sendAjaxPostRequest('/company-addresses/new', [
            '_token' => $this->grabCsrfTokenAsStaff($I),
            'label' => 'Smuggled',
            'address1' => '1 Evil St',
            'city' => 'Vancouver',
            'province' => 'BC',
            'country' => 'CA',
            'postal_code' => 'V5K0A1',
        ]);
        $I->dontSeeInRepository(CompanyAddress::class, ['label' => 'Smuggled']);
    }

    /** The checkout entry point is locked too, not just the one on /company-profile. */
    public function staffCannotCreateAnAddressViaTheCheckoutEntryPoint(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $this->loginAs($I, $company, 'ROLE_COMPANY_STAFF');

        $I->sendAjaxPostRequest('/company-addresses/new', [
            '_token' => $this->grabCsrfTokenAsStaff($I),
            'return_to' => 'checkout',
            'label' => 'Smuggled At Checkout',
            'address1' => '1 Evil St',
            'city' => 'Vancouver',
            'province' => 'BC',
            'country' => 'CA',
            'postal_code' => 'V5K0A1',
        ]);
        $I->dontSeeInRepository(CompanyAddress::class, ['label' => 'Smuggled At Checkout']);
    }

    public function staffCannotEditAnAddress(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $address = $this->makeAddress($I, $company, ['label' => 'Warehouse']);
        $this->loginAs($I, $company, 'ROLE_COMPANY_STAFF');

        $I->amOnPage('/company-addresses/' . $address->getId() . '/edit');
        $I->seeCurrentUrlEquals('/company-profile');
        $I->see('Only a company owner can manage company addresses.');

        $I->sendAjaxPostRequest('/company-addresses/' . $address->getId() . '/edit', [
            '_token' => $this->grabCsrfTokenAsStaff($I),
            'label' => 'Repointed',
            'address1' => '9 Evil St',
            'city' => 'Vancouver',
            'province' => 'BC',
            'country' => 'CA',
            'postal_code' => 'V5K0A1',
        ]);
        $I->seeInRepository(CompanyAddress::class, ['id' => $address->getId(), 'label' => 'Warehouse']);
    }

    public function staffCannotDeleteAnAddress(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $address = $this->makeAddress($I, $company);
        $this->loginAs($I, $company, 'ROLE_COMPANY_STAFF');

        $I->sendAjaxPostRequest('/company-addresses/' . $address->getId() . '/delete', [
            '_token' => $this->grabCsrfTokenAsStaff($I),
        ]);
        $I->seeInRepository(CompanyAddress::class, ['id' => $address->getId()]);
    }

    public function staffCannotChangeTheDefaultBillingAddress(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $address = $this->makeAddress($I, $company);
        $this->loginAs($I, $company, 'ROLE_COMPANY_STAFF');

        $I->sendAjaxPostRequest('/company-addresses/' . $address->getId() . '/default/billing', [
            '_token' => $this->grabCsrfTokenAsStaff($I),
        ]);
        $I->seeInRepository(CompanyAddress::class, ['id' => $address->getId(), 'isDefaultBilling' => false]);
    }

    /** Positive counterpart: staff still see the addresses, just without any action controls. */
    public function staffStillSeeTheAddressBookWithoutActionControls(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $this->makeAddress($I, $company, ['label' => 'Visible Warehouse']);
        $this->loginAs($I, $company, 'ROLE_COMPANY_STAFF');

        $I->amOnPage('/company-profile');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Visible Warehouse');

        $html = $I->grabPageSource();
        $I->assertStringNotContainsString('/company-addresses/new', $html);
        $I->assertStringNotContainsString('/delete', $html);
        $I->assertStringNotContainsString('/default/billing', $html);
    }
}
