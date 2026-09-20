<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CustomFieldDefinition;
use App\Entity\CustomerUser;
use App\Entity\EmailLog;
use App\Service\ResetTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers Customer/AuthController: the /auth/login already-authenticated guard and bad-credentials
 *  messaging, the /auth/register company+user creation flow (success/validation/duplicate/CSRF),
 *  the /auth/password-reset request+change flow, and the /auth/setup-account account-setup flow. */
final class CustomerAuthCest
{
    /**
     * #336 put a per-IP rate limiter on POST /auth/register — 2 accepted registrations per 5
     * minutes, 5 per hour, 6 per day — and its storage is a filesystem cache pool, so limiter state
     * survives both the per-test transaction rollback and the suite run itself. Several tests here
     * register successfully and every functional test presents the same client IP, so by the third
     * one the limiter is doing exactly what it was asked to do and the test fails for a reason that
     * has nothing to do with what it is checking.
     *
     * Cleared per test rather than per registering test, so a test added later that happens to
     * register does not have to know this. The rate limiter is covered on purpose in
     * CustomerRegisterRaceAndRateLimitCest, which sets its own client IPs; this Cest is about the
     * registration flow's behaviour, not its quota. Same tool the password-reset tests below and in
     * AdminAuthCest already reach for, just hoisted.
     */
    public function _before(FunctionalTester $I): void
    {
        $I->grabService('cache.rate_limiter')->clear();
    }

    private function createCustomer(FunctionalTester $I, string $email): CustomerUser
    {
        $company = (new Company())->setName('Auth Test Co');
        $I->haveInRepository($company);

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $customer = (new CustomerUser())->setEmail($email)->setCompany($company);
        $customer->setPassword($hasher->hashPassword($customer, 'test-password-123'));
        $I->haveInRepository($customer);

        return $customer;
    }

    /** @return array{0: CustomerUser, 1: string} the persisted customer and the raw (unhashed) token */
    private function createCustomerWithResetToken(FunctionalTester $I, string $email): array
    {
        $resetTokenService = $I->grabService(ResetTokenService::class);
        $rawToken = $resetTokenService->generate();

        $customer = $this->createCustomer($I, $email);
        $customer->setResetToken($resetTokenService->hash($rawToken));
        $customer->setResetTokenExpiresAt((new \DateTimeImmutable())->modify('+1 hour'));
        $I->haveInRepository($customer);

        return [$customer, $rawToken];
    }

    public function loginPageRendersForAGuest(FunctionalTester $I): void
    {
        $I->amOnPage('/auth/login');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Sign in');
        $I->seeInField('_username', '');
    }

    /** #300: opted in, not opted out — a shared/public machine is the case a wrong default harms. */
    public function rememberMeCheckboxIsNotPreChecked(FunctionalTester $I): void
    {
        $I->amOnPage('/auth/login');
        $I->dontSeeCheckboxIsChecked('input[name="_remember_me"]');
    }

    /**
     * #300/#299: leaving the box untouched (the common case, now that it isn't pre-checked) must
     * not silently opt the customer into a 14-day persistent credential.
     */
    public function loggingInWithoutTickingRememberMeIssuesNoRemembermeCookie(FunctionalTester $I): void
    {
        $customer = $this->createCustomer($I, 'auth-login-noremember-test@example.test');

        $I->amOnPage('/auth/login');
        $I->submitForm('form.form-grid', [
            '_username' => $customer->getEmail(),
            '_password' => 'test-password-123',
        ]);
        $I->seeCurrentUrlEquals('/');
        $I->dontSeeCookie('REMEMBERME');
    }

    public function loggingInWithRememberMeTickedIssuesARemembermeCookie(FunctionalTester $I): void
    {
        $customer = $this->createCustomer($I, 'auth-login-remember-test@example.test');

        $I->amOnPage('/auth/login');
        $I->submitForm('form.form-grid', [
            '_username' => $customer->getEmail(),
            '_password' => 'test-password-123',
            '_remember_me' => 'on',
        ]);
        $I->seeCurrentUrlEquals('/');
        $I->seeCookie('REMEMBERME');
    }

    /**
     * #299: the core claim. A signature-based remember-me cookie is self-contained and stateless —
     * nothing server-side to delete, so logging out cannot touch a copy taken before that (XSS, a
     * shared machine, a log). With a persistent (doctrine) token_provider, logout deletes the
     * backing row, so replaying the same cookie value afterwards — from a session that never
     * logged in at all, exactly what a captured copy replayed elsewhere looks like — must fail.
     */
    public function aRememberMeCookieReplayedAfterLogoutIsRejected(FunctionalTester $I): void
    {
        $customer = $this->createCustomer($I, 'auth-remember-revoke-test@example.test');

        $I->amOnPage('/auth/login');
        $I->submitForm('form.form-grid', [
            '_username' => $customer->getEmail(),
            '_password' => 'test-password-123',
            '_remember_me' => 'on',
        ]);
        $I->seeCurrentUrlEquals('/');
        $capturedRememberMeCookie = $I->grabCookie('REMEMBERME');
        $I->assertIsString($capturedRememberMeCookie);

        $I->sendAjaxPostRequest('/logout', []);

        // A fresh, sessionless client presenting only the captured cookie — the replay scenario
        // the issue is about, not merely "the same browser after clicking logout".
        $I->resetCookie('MOCKSESSID');
        $I->setCookie('REMEMBERME', $capturedRememberMeCookie);

        $I->amOnPage('/orders');
        $I->seeCurrentUrlEquals('/auth/login');
    }

    /** The revocation above must not break the ordinary case: ticking remember-me still survives a session reset. */
    public function aRememberMeCookieStillAuthenticatesAcrossASessionReset(FunctionalTester $I): void
    {
        $customer = $this->createCustomer($I, 'auth-remember-persist-test@example.test');

        $I->amOnPage('/auth/login');
        $I->submitForm('form.form-grid', [
            '_username' => $customer->getEmail(),
            '_password' => 'test-password-123',
            '_remember_me' => 'on',
        ]);
        $capturedRememberMeCookie = $I->grabCookie('REMEMBERME');
        $I->assertIsString($capturedRememberMeCookie);

        // Simulates a browser restart: the session is gone, the persistent cookie is not.
        $I->resetCookie('MOCKSESSID');
        $I->setCookie('REMEMBERME', $capturedRememberMeCookie);

        $I->amOnPage('/orders');
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeCurrentUrlEquals('/auth/login');
    }

    public function loggedInCustomerIsRedirectedFromLoginToHome(FunctionalTester $I): void
    {
        $customer = $this->createCustomer($I, 'auth-login-redirect-test@example.test');
        $I->amLoggedInAs($customer, 'main');

        $I->amOnPage('/auth/login');
        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlEquals('/');
    }

    public function loginWithBadCredentialsShowsAFriendlyError(FunctionalTester $I): void
    {
        $this->createCustomer($I, 'auth-login-badpw-test@example.test');

        $I->amOnPage('/auth/login');
        $I->submitForm('form.form-grid', [
            '_username' => 'auth-login-badpw-test@example.test',
            '_password' => 'totally-wrong-password',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Email or password is incorrect.');
    }

    public function registerPageRendersForAGuest(FunctionalTester $I): void
    {
        $I->amOnPage('/auth/register');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Request access');
    }

    /**
     * #355: the "Custom Fields" section label is hidden entirely, and the section itself moves
     * from right after Company Email to just above the terms-and-conditions checkbox.
     */
    public function registerHidesTheCustomFieldsLabelAndPlacesItAboveTermsAndConditions(FunctionalTester $I): void
    {
        $definition = (new CustomFieldDefinition())
            ->setObjectType('company')
            ->setSlug('preferred_delivery_window')
            ->setLabel('Preferred Delivery Window')
            ->setFieldType('text');
        $I->grabService(EntityManagerInterface::class)->persist($definition);
        $I->grabService(EntityManagerInterface::class)->flush();

        $I->amOnPage('/auth/register');
        $I->seeResponseCodeIsSuccessful();
        $I->dontSee('Custom Fields');
        $I->see('Preferred Delivery Window');

        $source = $I->grabPageSource();
        $billingPos = strpos($source, 'name="bill_postal"');
        $customFieldPos = strpos($source, 'preferred_delivery_window');
        $termsPos = strpos($source, 'name="agree_terms"');
        $I->assertNotFalse($billingPos, 'billing section should be in the page');
        $I->assertNotFalse($customFieldPos, 'the custom field should render');
        $I->assertNotFalse($termsPos, 'the terms checkbox should be in the page');
        $I->assertGreaterThan($billingPos, $customFieldPos, 'custom fields must render after the billing address section, not up by Company Email');
        $I->assertLessThan($termsPos, $customFieldPos, 'custom fields must render before the terms and conditions checkbox');
    }

    /**
     * #354: a Billing Address Name field (mirroring the existing Shipping Address Name one), and
     * the billing fields wired to be hidden client-side while "same as shipping" is checked. The
     * actual hide/show is a runtime JS behavior this suite has no browser to exercise — this
     * confirms the markup it depends on (the checkbox's id, the field-group class the script
     * selects) is genuinely present, not just that the page renders.
     */
    public function registerPageHasABillingAddressNameFieldWiredForTheSameAsShippingToggle(FunctionalTester $I): void
    {
        $I->amOnPage('/auth/register');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('input[name="bill_address_name"]');
        $I->seeElement('input#bill_same[name="bill_same"]');
        // Every billing field the toggle hides carries this class — if a future edit drops it from
        // one, the script silently stops hiding that field while "same as shipping" is checked.
        $I->seeElement('input[name="bill_address_name"].js-bill-field, label.js-bill-field input[name="bill_address_name"]');
        foreach (['bill_first_name', 'bill_last_name', 'bill_address1', 'bill_address2', 'bill_city', 'bill_postal'] as $field) {
            $I->seeElement("label.js-bill-field input[name=\"{$field}\"]");
        }
    }

    public function registerWithBlankRequiredFieldsShowsFieldErrors(FunctionalTester $I): void
    {
        $I->amOnPage('/auth/register');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/auth/register', [
            '_token' => $token,
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Please correct the highlighted fields below.');
        $I->see('Registered company name is required.');
        $I->see('Shipping address line 1 is required.');
    }

    public function registerWithInvalidCsrfShowsAFormError(FunctionalTester $I): void
    {
        $I->amOnPage('/auth/register');

        $I->sendAjaxPostRequest('/auth/register', [
            '_token' => 'not-a-real-token',
            'company_name' => 'Whatever Co',
        ]);
        $I->seeResponseCodeIs(403);
        $I->assertStringContainsString('Your session expired', $I->grabPageSource());
    }

    public function registerWithADuplicateEmailShowsAFieldError(FunctionalTester $I): void
    {
        $this->createCustomer($I, 'auth-register-dupe-test@example.test');

        $I->amOnPage('/auth/register');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/auth/register', array_merge($this->validRegistrationPayload(), [
            '_token' => $token,
            'user_email' => 'auth-register-dupe-test@example.test',
        ]));
        $I->seeResponseCodeIsSuccessful();
        $I->see('An account with this email already exists.');
    }

    public function registerWithValidDataCreatesAReviewCompanyAndInactiveUser(FunctionalTester $I): void
    {
        $I->amOnPage('/auth/register');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/auth/register', array_merge($this->validRegistrationPayload(), [
            '_token' => $token,
        ]));
        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlEquals('/auth/login');
        $I->see('pending approval');

        /** @var CustomerUser $user */
        $user = $I->grabEntityFromRepository(CustomerUser::class, ['email' => 'auth-register-new-test@example.test']);
        $I->assertSame('Inactive', $user->getStatus());
        $I->assertSame('Pinnacle Supply Co', $user->getCompany()->getName());
        $I->assertSame('Review', $user->getCompany()->getStatus());
        $I->assertNotNull($user->getCompany()->getCode());
    }

    /** #354: bill_address_name saves as the billing CompanyAddress's own label, same as ship_address_name already does for shipping. */
    public function registerWithASeparateBillingAddressSavesTheBillingAddressName(FunctionalTester $I): void
    {
        $I->amOnPage('/auth/register');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/auth/register', array_merge($this->validRegistrationPayload(), [
            '_token' => $token,
            'user_email' => 'auth-register-billing-name-test@example.test',
            'bill_same' => '',
            'bill_address_name' => 'Head Office',
            'bill_address1' => '200 Finance Ave',
            'bill_city' => 'Edmonton',
            'bill_province' => 'Alberta',
            'bill_country' => 'Canada',
            'bill_postal' => 'T5J 0N3',
        ]));
        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlEquals('/auth/login');

        /** @var CustomerUser $user */
        $user = $I->grabEntityFromRepository(CustomerUser::class, ['email' => 'auth-register-billing-name-test@example.test']);
        $billing = null;
        foreach ($user->getCompany()->getAddresses() as $address) {
            if ($address->isDefaultBilling()) {
                $billing = $address;
            }
        }
        $I->assertNotNull($billing, 'a separate billing address should have been created');
        $I->assertSame('Head Office', $billing->getLabel());
    }

    /** A blank billing address name falls back to the same literal default 'Billing' had before #354 added the field. */
    public function registerWithASeparateBillingAddressAndNoNameFallsBackToTheDefaultLabel(FunctionalTester $I): void
    {
        $I->amOnPage('/auth/register');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/auth/register', array_merge($this->validRegistrationPayload(), [
            '_token' => $token,
            'user_email' => 'auth-register-billing-default-test@example.test',
            'bill_same' => '',
            'bill_address1' => '200 Finance Ave',
            'bill_city' => 'Edmonton',
            'bill_province' => 'Alberta',
            'bill_country' => 'Canada',
            'bill_postal' => 'T5J 0N3',
        ]));
        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlEquals('/auth/login');

        /** @var CustomerUser $user */
        $user = $I->grabEntityFromRepository(CustomerUser::class, ['email' => 'auth-register-billing-default-test@example.test']);
        $billing = null;
        foreach ($user->getCompany()->getAddresses() as $address) {
            if ($address->isDefaultBilling()) {
                $billing = $address;
            }
        }
        $I->assertNotNull($billing);
        $I->assertSame('Billing', $billing->getLabel());
    }

    /**
     * #331: `user_email[]=a&user_email[]=b` used to reach `(string) $data['user_email']`, and the
     * "Array to string conversion" warning was promoted to an uncaught exception — HTTP 500 from an
     * anonymous guest holding nothing but a valid CSRF token. The type gate refuses the submission
     * outright now, with the ordinary re-rendered form.
     */
    public function registerWithAnArrayValuedScalarFieldIsRefusedWithoutCrashing(FunctionalTester $I): void
    {
        $I->amOnPage('/auth/register');

        $I->sendFormPostRequest('/auth/register', array_merge($this->validRegistrationPayload(), [
            '_token' => $I->csrfToken(),
            'user_email' => ['auth-register-array-a@example.test', 'auth-register-array-b@example.test'],
        ]));

        $I->seeResponseCodeIs(200);
        $I->see('could not be read');
        $I->see('user_email');
        $I->dontSeeInRepository(CustomerUser::class, ['email' => 'auth-register-array-a@example.test']);
        $I->dontSeeInRepository(Company::class, ['name' => 'Pinnacle Supply Co']);
    }

    /**
     * #331: the nested shape fails differently from the flat one — `user_email[x][y]` is an array of
     * arrays, so anything that coped by taking the first element would still be handing an array to
     * the string cast. The gate is a plain is_scalar() check, so both are refused identically.
     */
    public function registerWithANestedArrayValuedScalarFieldIsRefusedWithoutCrashing(FunctionalTester $I): void
    {
        $I->amOnPage('/auth/register');

        $I->sendFormPostRequest('/auth/register', array_merge($this->validRegistrationPayload(), [
            '_token' => $I->csrfToken(),
            'user_email' => ['x' => ['y' => 'auth-register-nested@example.test']],
            'company_name' => ['deep' => ['deeper' => 'Nested Co']],
        ]));

        $I->seeResponseCodeIs(200);
        $I->see('could not be read');
        $I->see('user_email');
        $I->see('company_name');
        $I->dontSeeInRepository(CustomerUser::class, ['email' => 'auth-register-nested@example.test']);
    }

    /**
     * #333: `?: 'CA'` ran before the validity check, so an omitted ship_country could never fail it
     * and the registration succeeded with a country the customer never chose. An explicitly invalid
     * value was already refused; absent is refused the same way now.
     */
    public function registerWithAnOmittedShipCountryIsRefused(FunctionalTester $I): void
    {
        $payload = $this->validRegistrationPayload();
        unset($payload['ship_country']);

        $I->amOnPage('/auth/register');
        $I->sendFormPostRequest('/auth/register', array_merge($payload, [
            '_token' => $I->csrfToken(),
            'user_email' => 'auth-register-nocountry@example.test',
        ]));

        $I->seeResponseCodeIs(200);
        $I->see('Please choose a shipping country from the list.');
        $I->dontSeeInRepository(CustomerUser::class, ['email' => 'auth-register-nocountry@example.test']);
    }

    /** #333: a blank submitted value is the same missing answer as an omitted key. */
    public function registerWithABlankShipCountryIsRefused(FunctionalTester $I): void
    {
        $I->amOnPage('/auth/register');
        $I->sendFormPostRequest('/auth/register', array_merge($this->validRegistrationPayload(), [
            '_token' => $I->csrfToken(),
            'user_email' => 'auth-register-blankcountry@example.test',
            'ship_country' => '   ',
        ]));

        $I->seeResponseCodeIs(200);
        $I->see('Please choose a shipping country from the list.');
        $I->dontSeeInRepository(CustomerUser::class, ['email' => 'auth-register-blankcountry@example.test']);
    }

    /** #333: bill_country carried the identical `?: 'CA'` and is settled the same way. */
    public function registerWithASeparateBillingAddressAndNoBillCountryIsRefused(FunctionalTester $I): void
    {
        $payload = $this->validRegistrationPayload();
        unset($payload['bill_same']);

        $I->amOnPage('/auth/register');
        $I->sendFormPostRequest('/auth/register', array_merge($payload, [
            '_token' => $I->csrfToken(),
            'user_email' => 'auth-register-nobillcountry@example.test',
            'bill_address1' => '200 Second St',
            'bill_city' => 'Calgary',
            'bill_province' => 'Alberta',
            'bill_postal' => 'T2P 1J9',
        ]));

        $I->seeResponseCodeIs(200);
        $I->see('Please choose a billing country from the list.');
        $I->dontSeeInRepository(CustomerUser::class, ['email' => 'auth-register-nobillcountry@example.test']);
    }

    /**
     * #334: SQLite does not enforce VARCHAR(n) and the controller did not either, so an oversized
     * value was stored whole. Each field is now capped at its own column's declared length.
     */
    public function registerCapsFreeTextFieldsAtTheirColumnLength(FunctionalTester $I): void
    {
        $I->amOnPage('/auth/register');
        $I->sendFormPostRequest('/auth/register', array_merge($this->validRegistrationPayload(), [
            '_token' => $I->csrfToken(),
            'user_email' => 'auth-register-toolong@example.test',
            'company_name' => str_repeat('A', 10000),
            'ship_postal' => str_repeat('P', 5000),
            'first_name' => str_repeat('F', 500),
        ]));

        $I->seeCurrentUrlEquals('/auth/login');

        /** @var CustomerUser $user */
        $user = $I->grabEntityFromRepository(CustomerUser::class, ['email' => 'auth-register-toolong@example.test']);
        $I->assertSame(255, strlen((string) $user->getCompany()->getName()));   // Company::$name
        $I->assertSame(120, strlen((string) $user->getFirstName()));            // CustomerUser::$firstName

        $address = $user->getCompany()->getAddresses()->first();
        $I->assertNotFalse($address);
        $I->assertSame(20, strlen((string) $address->getPostalCode()));         // CompanyAddress::$postalCode
    }

    /**
     * #335: a raw NUL in a customer-supplied name round-tripped byte-for-byte out of the database
     * into admin screens, PDFs (Dompdf treats an embedded NUL as end-of-string in places) and
     * exports. TextInput strips C0 controls on the way in, as it already did for checkout.
     */
    public function registerStripsControlCharactersFromTextFields(FunctionalTester $I): void
    {
        $I->amOnPage('/auth/register');
        $I->sendFormPostRequest('/auth/register', array_merge($this->validRegistrationPayload(), [
            '_token' => $I->csrfToken(),
            'user_email' => 'auth-register-controlchars@example.test',
            'first_name' => "E2E\x00INJECT",
            'company_name' => "Acme\x01\x02\x07\x08Co",
            'ship_city' => "Cal\x00gary",
        ]));

        $I->seeCurrentUrlEquals('/auth/login');

        /** @var CustomerUser $user */
        $user = $I->grabEntityFromRepository(CustomerUser::class, ['email' => 'auth-register-controlchars@example.test']);
        $I->assertSame('E2EINJECT', $user->getFirstName());
        $I->assertSame('AcmeCo', $user->getCompany()->getName());

        $address = $user->getCompany()->getAddresses()->first();
        $I->assertNotFalse($address);
        $I->assertSame('Calgary', $address->getCity());
    }

    /**
     * #346: the admin alert used to link to the generic customer user list, so an admin had to
     * search for the new signup by hand. It must link straight to the new user's own edit page.
     */
    public function registrationAlertsAdminsWithALinkToTheSpecificNewUserNotTheList(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('auth-register-admin-alert-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amOnPage('/auth/register');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/auth/register', array_merge($this->validRegistrationPayload(), [
            '_token' => $token,
            'user_email' => 'auth-register-alert-link-test@example.test',
            'company_email' => 'orders-alert-link@pinnaclesupply.test',
        ]));
        $I->seeResponseCodeIsSuccessful();

        /** @var CustomerUser $user */
        $user = $I->grabEntityFromRepository(CustomerUser::class, ['email' => 'auth-register-alert-link-test@example.test']);

        /** @var EmailLog $log */
        $log = $I->grabEntityFromRepository(EmailLog::class, ['recipient' => 'auth-register-admin-alert-test@example.test']);
        $I->assertSame('New company registration pending approval', $log->getTemplateCode());

        $I->assertStringContainsString(
            '/admin/user/customer/update/' . $user->getId(),
            (string) $log->getBody(),
            'the admin alert must link straight to the new user\'s own record, not the customer user list'
        );
    }

    /** @return array<string, string> */
    private function validRegistrationPayload(): array
    {
        return [
            'company_name' => 'Pinnacle Supply Co',
            'company_email' => 'orders@pinnaclesupply.test',
            'first_name' => 'Pat',
            'last_name' => 'Owner',
            'user_email' => 'auth-register-new-test@example.test',
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

    public function passwordResetRequestForExistingCustomerGeneratesATokenAndFlashesAGenericSuccess(FunctionalTester $I): void
    {
        // Shared by name with the admin password_reset_request limiter (see rate_limiter.yaml)
        // and keyed per-IP, so a prior admin-side test hitting this endpoint in the same suite
        // run can exhaust it here too. Clear it for the same reason AdminAuthCest does.
        $I->grabService('cache.rate_limiter')->clear();

        $customer = $this->createCustomer($I, 'auth-reset-request-test@example.test');

        $I->amOnPage('/auth/password-reset');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/auth/password-reset', [
            '_token' => $token,
            'email' => $customer->getEmail(),
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('If an account exists for that email, a password reset link has been sent.');

        /** @var CustomerUser $refreshed */
        $refreshed = $I->grabEntityFromRepository(CustomerUser::class, ['email' => $customer->getEmail()]);
        $I->assertNotNull($refreshed->getResetToken());
        $I->assertNotNull($refreshed->getResetTokenExpiresAt());
        $I->assertGreaterThan(new \DateTimeImmutable(), $refreshed->getResetTokenExpiresAt());

        // Regression pin for #450: this is one of the two genuinely self-service forgot-password
        // flows. It hardcodes its token to +1 hour (not the configurable invite lifetime), and the
        // email copy must keep saying exactly that.
        // email_log, not the transport — local delivery is sandboxed by design (see
        // Helper\Functional::grabLastSentEmailBody).
        $I->assertStringContainsString(
            'this link will expire in 1 hour.',
            $I->grabLastSentEmailBody($customer->getEmail()),
        );
    }

    public function passwordResetWithAnInvalidTokenShowsAnExpiredLinkMessage(FunctionalTester $I): void
    {
        $I->amOnPage('/auth/password-reset?token=not-a-real-token');
        $I->seeResponseCodeIsSuccessful();
        $I->see('This reset link is invalid or has expired. Please request a new one.');
        $I->dontSeeElement('input[name="password"]');
    }

    public function passwordResetWithAValidTokenAndMismatchedPasswordsShowsAnInlineError(FunctionalTester $I): void
    {
        [$customer, $rawToken] = $this->createCustomerWithResetToken($I, 'auth-reset-mismatch-test@example.test');

        $I->amOnPage('/auth/password-reset?token=' . $rawToken);
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/auth/password-reset?token=' . $rawToken, [
            '_token' => $token,
            'password' => 'newpassword1',
            'confirm_password' => 'somethingelse',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Passwords do not match.');

        /** @var CustomerUser $refreshed */
        $refreshed = $I->grabEntityFromRepository(CustomerUser::class, ['email' => $customer->getEmail()]);
        $I->assertNotNull($refreshed->getResetToken());
    }

    public function passwordResetWithAValidTokenAndValidPasswordUpdatesThePasswordAndRedirectsToLogin(FunctionalTester $I): void
    {
        [$customer, $rawToken] = $this->createCustomerWithResetToken($I, 'auth-reset-success-test@example.test');

        $I->amOnPage('/auth/password-reset?token=' . $rawToken);
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/auth/password-reset?token=' . $rawToken, [
            '_token' => $token,
            'password' => 'brand-new-password-1',
            'confirm_password' => 'brand-new-password-1',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlEquals('/auth/login');
        $I->see('Password updated successfully. You can now log in.');

        /** @var CustomerUser $refreshed */
        $refreshed = $I->grabEntityFromRepository(CustomerUser::class, ['email' => $customer->getEmail()]);
        $I->assertNull($refreshed->getResetToken());
        $I->assertNull($refreshed->getResetTokenExpiresAt());

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $I->assertTrue($hasher->isPasswordValid($refreshed, 'brand-new-password-1'));
    }

    public function accountSetupWithABlankTokenRedirectsToPasswordResetWithAnError(FunctionalTester $I): void
    {
        $I->amOnPage('/auth/setup-account');
        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlEquals('/auth/password-reset');
        $I->see('This setup link is invalid.');
    }

    public function accountSetupWithAValidTokenSetsThePasswordAndRedirectsToLogin(FunctionalTester $I): void
    {
        [$customer, $rawToken] = $this->createCustomerWithResetToken($I, 'auth-setup-success-test@example.test');

        $I->amOnPage('/auth/setup-account?token=' . $rawToken);
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/auth/setup-account?token=' . $rawToken, [
            '_token' => $token,
            'password' => 'first-login-password-1',
            'confirm_password' => 'first-login-password-1',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlEquals('/auth/login');
        $I->see('Account setup complete. You can now log in.');

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        /** @var CustomerUser $refreshed */
        $refreshed = $I->grabEntityFromRepository(CustomerUser::class, ['email' => $customer->getEmail()]);
        $I->assertTrue($hasher->isPasswordValid($refreshed, 'first-login-password-1'));
        $I->assertNull($refreshed->getResetToken());
    }
}
