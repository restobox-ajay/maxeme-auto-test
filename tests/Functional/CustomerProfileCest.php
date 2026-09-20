<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CustomerUser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers Customer\ProfileController — /profile (self-service account + password update) and
 *  /company-profile (company details + billing-address split-off logic). */
final class CustomerProfileCest
{
    /**
     * Owner by default: since #522 §3 the /company-profile form is owner-only, so a roleless actor
     * would be bounced before reaching the behaviour most cases here are about. /profile itself is
     * unaffected — it is every customer's own self-service page. Pass ROLE_COMPANY_STAFF to
     * exercise the gate; see the staff cases at the bottom of this file.
     */
    private function loginAs(FunctionalTester $I, Company $company, string $role = 'ROLE_COMPANY_OWNER'): CustomerUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $customer = (new CustomerUser())
            ->setEmail('profile-test-' . uniqid() . '@example.test')
            ->setFirstName('Jane')
            ->setLastName('Doe')
            ->setPhoneNumber('555-0100')
            ->setCompany($company)
            ->setRoles([$role]);
        $customer->setPassword($hasher->hashPassword($customer, 'current-password-123'));
        $I->haveInRepository($customer);

        $I->amLoggedInAs($customer, 'main');

        return $customer;
    }

    private function makeCompany(FunctionalTester $I): Company
    {
        $company = (new Company())
            ->setName('Acme Co')
            ->setCode('ACME-' . uniqid());
        $I->haveInRepository($company);

        return $company;
    }

    public function guestIsRedirectedToLoginFromProfile(FunctionalTester $I): void
    {
        // access_control (config/packages/security.yaml) requires ROLE_CUSTOMER on this route,
        // so the firewall redirects before the controller's own not-logged-in branch ever runs.
        $I->amOnPage('/profile');
        $I->seeCurrentUrlEquals('/auth/login');
    }

    public function profilePageRendersPrefilledForALoggedInCustomer(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $this->loginAs($I, $company);

        $I->amOnPage('/profile');
        $I->seeResponseCodeIsSuccessful();
        $I->seeInField('first_name', 'Jane');
        $I->seeInField('last_name', 'Doe');
        $I->seeInField('phone_number', '555-0100');
    }

    public function profilePageDoesNotShowAccountStatusOrCompanyInThePasswordCard(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $this->loginAs($I, $company);

        $I->amOnPage('/profile');
        $I->seeResponseCodeIsSuccessful();

        // "Account Status" is meaningless to a logged-in user (an inactive user cannot log in at all),
        // and the read-only Company box never belonged in the Reset Password card. The company name still
        // legitimately appears in the layout's user menu, so scope these assertions to the profile form.
        $I->dontSee('Account Status', '.customer-profile-form-shell');
        $I->dontSee('Acme Co', '.customer-profile-form-shell');
        $I->dontSeeElement('.customer-profile-form-shell input[value="Active"]');
    }

    public function updatingProfileFieldsPersistsAndRedirects(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $customer = $this->loginAs($I, $company);

        $I->amOnPage('/profile');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/profile', [
            '_token' => $token,
            'first_name' => 'Janet',
            'last_name' => 'Smith',
            'phone_number' => '555-0199',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Profile updated.');

        $I->seeInRepository(CustomerUser::class, [
            'id' => $customer->getId(),
            'firstName' => 'Janet',
            'lastName' => 'Smith',
            'phoneNumber' => '555-0199',
        ]);
    }

    public function submittingBlankNamesShowsValidationErrors(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $this->loginAs($I, $company);

        $I->amOnPage('/profile');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/profile', [
            '_token' => $token,
            'first_name' => '',
            'last_name' => '',
            'phone_number' => '555-0100',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('First name is required.');
        $I->see('Last name is required.');
    }

    public function changingPasswordWithWrongCurrentPasswordShowsAFieldError(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $this->loginAs($I, $company);

        $I->amOnPage('/profile');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/profile', [
            '_token' => $token,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone_number' => '555-0100',
            'current_password' => 'not-the-right-password',
            'new_password' => 'a-new-password-123',
            'confirm_new_password' => 'a-new-password-123',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Current password is incorrect.');
    }

    public function changingPasswordWithMismatchedConfirmationShowsAFieldError(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $this->loginAs($I, $company);

        $I->amOnPage('/profile');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/profile', [
            '_token' => $token,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone_number' => '555-0100',
            'current_password' => 'current-password-123',
            'new_password' => 'a-new-password-123',
            'confirm_new_password' => 'a-different-password-456',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Passwords do not match.');
    }

    public function changingPasswordSuccessfullyUpdatesTheHash(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $customer = $this->loginAs($I, $company);
        $oldHash = $customer->getPassword();

        $I->amOnPage('/profile');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/profile', [
            '_token' => $token,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'phone_number' => '555-0100',
            'current_password' => 'current-password-123',
            'new_password' => 'a-new-password-123',
            'confirm_new_password' => 'a-new-password-123',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Profile and password updated.');

        $refreshed = $I->grabEntityFromRepository(CustomerUser::class, ['id' => $customer->getId()]);
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $I->assertTrue($hasher->isPasswordValid($refreshed, 'a-new-password-123'));
        $I->assertNotSame($oldHash, $refreshed->getPassword());
    }

    public function submittingProfileWithAnInvalidCsrfTokenRedirectsWithAnErrorFlash(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $this->loginAs($I, $company);

        $I->sendAjaxPostRequest('/profile', [
            '_token' => 'not-a-real-token',
            'first_name' => 'Someone',
            'last_name' => 'Else',
        ]);
        $I->seeResponseCodeIs(403);
        $I->assertStringContainsString('Your session expired', $I->grabPageSource());
    }

    public function guestIsRedirectedFromCompanyProfile(FunctionalTester $I): void
    {
        // Same access_control gating as /profile — the controller's own guard is unreachable here.
        $I->amOnPage('/company-profile');
        $I->seeCurrentUrlEquals('/auth/login');
    }

    public function companyProfilePageRendersCompanyDetails(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $company->setTradeName('Acme Trading');
        $I->haveInRepository($company);
        $this->loginAs($I, $company);

        $I->amOnPage('/company-profile');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Company Profile - Acme Co');
        $I->seeInField('company_name', 'Acme Co');
        $I->seeInField('trade_name', 'Acme Trading');
    }

    public function companyProfilePageDoesNotExposeTheSalesRepField(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $company->setSalesRepNote('Internal Rep');
        $I->haveInRepository($company);
        $this->loginAs($I, $company);

        $I->amOnPage('/company-profile');
        $I->seeResponseCodeIsSuccessful();

        // Sales rep is internal-only: it must not be shown or editable on the customer-facing page.
        $I->dontSee('Sales Rep');
        $I->dontSeeElement('input[name="sales_rep"]');
        $I->dontSee('Internal Rep');
    }

    public function savingCompanyProfileDoesNotClearTheSalesRep(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $company->setSalesRepNote('Internal Rep');
        $I->haveInRepository($company);
        $this->loginAs($I, $company);

        $I->amOnPage('/company-profile');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        // The form no longer submits sales_rep. The controller must therefore leave the admin-managed
        // value alone rather than blanking it via an unconditional setSalesRepNote()/setSalesRepUser().
        $I->sendAjaxPostRequest('/company-profile', [
            '_token' => $token,
            'company_name' => 'Acme Co Renamed',
            'trade_name' => 'Acme Trading',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Company profile updated.');

        $I->seeInRepository(Company::class, [
            'id' => $company->getId(),
            'name' => 'Acme Co Renamed',
            'tradeName' => 'Acme Trading',
            'salesRepNote' => 'Internal Rep',
        ]);
    }

    public function companyProfileRendersAccountTypeReadonly(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $company->setAccountType('Business');
        $I->haveInRepository($company);
        $this->loginAs($I, $company);

        $I->amOnPage('/company-profile');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[name="account_type"][readonly]');
    }

    public function directPostCannotChangeAccountTypeOnCompanyProfile(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $company->setAccountType('Business');
        $I->haveInRepository($company);
        $this->loginAs($I, $company);

        $I->amOnPage('/company-profile');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        // account_type is readonly in the rendered form (admin-managed field), but a readonly input still
        // submits its value in a normal form post, and nothing stops a direct POST either way — the
        // controller itself must refuse to write it, not just the template.
        $I->sendAjaxPostRequest('/company-profile', [
            '_token' => $token,
            'company_name' => 'Acme Co',
            'account_type' => 'Non-business',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Company profile updated.');

        $I->seeInRepository(Company::class, [
            'id' => $company->getId(),
            'accountType' => 'Business',
        ]);
    }

    public function companyNameRequiredKeepsFormWithAnErrorFlash(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $this->loginAs($I, $company);

        $I->amOnPage('/company-profile');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/company-profile', [
            '_token' => $token,
            'company_name' => '   ',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Company name is required.');
    }

    public function updatingCompanyProfileSplitsBillingFromAComboAddress(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);

        $comboAddress = (new CompanyAddress())
            ->setCompany($company)
            ->setFirstName('Original')
            ->setLastName('Owner')
            ->setAddressLine1('1 Combo St')
            ->setIsDefaultShipping(true)
            ->setIsDefaultBilling(true);
        $I->haveInRepository($comboAddress);

        $this->loginAs($I, $company);

        $I->amOnPage('/company-profile');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/company-profile', [
            '_token' => $token,
            'company_name' => 'Acme Co',
            'bill_first_name' => 'Billing',
            'bill_last_name' => 'Contact',
            'bill_address1' => '2 Billing Ave',
            'bill_city' => 'Vancouver',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Company profile updated.');

        // The original combo address must remain the default shipping address, untouched, and
        // must no longer be marked as the default billing address.
        $I->seeInRepository(CompanyAddress::class, [
            'id' => $comboAddress->getId(),
            'isDefaultShipping' => true,
            'isDefaultBilling' => false,
            'firstName' => 'Original',
            'addressLine1' => '1 Combo St',
        ]);

        // A brand new address record must have been created to hold the billing-only details.
        $I->seeInRepository(CompanyAddress::class, [
            'company' => $company->getId(),
            'isDefaultBilling' => true,
            'firstName' => 'Billing',
            'lastName' => 'Contact',
            'addressLine1' => '2 Billing Ave',
            'city' => 'Vancouver',
        ]);
    }

    // ---------------------------------------------------------------------------------------
    // #522 §3: the company profile is owner-editable, staff-readable. Every case above is the
    // matching positive — they all log in as an owner.
    // ---------------------------------------------------------------------------------------

    public function staffSeeTheCompanyProfileReadOnly(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $this->loginAs($I, $company, 'ROLE_COMPANY_STAFF');

        $I->amOnPage('/company-profile');
        $I->seeResponseCodeIsSuccessful();
        // Readable: the company's own details are still on the page...
        $I->seeInField('company_name', 'Acme Co');
        // ...but nothing offers to change them.
        $I->see('You have view-only access to the company profile.');
        $I->dontSee('Update Profile');
        $I->assertStringContainsString('<fieldset disabled', $I->grabPageSource());
    }

    /** The disabled fieldset is a rendering hint; this is the check that actually holds. */
    public function staffDirectPostCannotChangeTheCompanyProfile(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $this->loginAs($I, $company, 'ROLE_COMPANY_STAFF');

        $I->amOnPage('/company-profile');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/company-profile', [
            '_token' => $token,
            'company_name' => 'Hijacked Inc',
            'trade_name' => 'Hijacked',
            'company_email' => 'attacker@evil.test',
        ]);
        $I->see('Only a company owner can edit the company profile.');

        $I->seeInRepository(Company::class, ['id' => $company->getId(), 'name' => 'Acme Co']);
        $I->dontSeeInRepository(Company::class, ['id' => $company->getId(), 'name' => 'Hijacked Inc']);
    }

    /** Positive counterpart: an owner still gets an editable form and a working save. */
    public function ownerStillSeesAnEditableCompanyProfile(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $this->loginAs($I, $company);

        $I->amOnPage('/company-profile');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Update Profile');
        $I->dontSee('You have view-only access to the company profile.');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/company-profile', [
            '_token' => $token,
            'company_name' => 'Renamed Co',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->seeInRepository(Company::class, ['id' => $company->getId(), 'name' => 'Renamed Co']);
    }
}
