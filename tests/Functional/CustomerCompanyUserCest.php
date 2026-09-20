<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\Company;
use App\Entity\CustomerUser;
use App\Service\AppSettings;
use App\Service\DocumentActor;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers Customer\CompanyUserController — /company-users list/search, create, edit and
 *  password-reset-link routes for a logged-in company's own staff accounts, plus the
 *  ROLE_COMPANY_OWNER gating #522 put on everything but index() and create(). */
final class CustomerCompanyUserCest
{
    private function makeCompany(FunctionalTester $I): Company
    {
        $company = (new Company())
            ->setName('Acme Co')
            ->setCode('ACME-' . uniqid());
        $I->haveInRepository($company);

        return $company;
    }

    private function makeCustomer(FunctionalTester $I, Company $company, array $overrides = []): CustomerUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $customer = (new CustomerUser())
            ->setEmail($overrides['email'] ?? ('ccu-' . uniqid() . '@example.test'))
            ->setFirstName($overrides['firstName'] ?? 'Jane')
            ->setLastName($overrides['lastName'] ?? 'Doe')
            ->setCompany($company)
            ->setRoles($overrides['roles'] ?? ['ROLE_COMPANY_STAFF']);
        $customer->setStatus($overrides['status'] ?? 'Active', DocumentActor::system());
        $customer->setPassword($hasher->hashPassword($customer, 'current-password-123'));
        $I->haveInRepository($customer);

        return $customer;
    }

    private function loginAs(FunctionalTester $I, CustomerUser $customer): void
    {
        $I->amLoggedInAs($customer, 'main');
    }

    /**
     * CSRF is verified in CsrfProtectionSubscriber, i.e. before the controller runs, so a negative
     * test posting a junk token would get its 403 from CSRF and never exercise the role gate it is
     * meant to test. Tokens are global (Csrf::ID === 'submit'), so any page the actor can actually
     * open yields a usable one — /company-users/create is the one page these tests can rely on a
     * staff actor still reaching after #522.
     */
    private function grabCsrfToken(FunctionalTester $I): string
    {
        $I->amOnPage('/company-users/create');

        return $I->grabAttributeFrom('input[name="_token"]', 'value');
    }

    public function guestIsRedirectedToLoginFromCompanyUsersIndex(FunctionalTester $I): void
    {
        // access_control (config/packages/security.yaml) requires ROLE_CUSTOMER on this route,
        // so the firewall redirects before the controller's own not-logged-in branch ever runs.
        $I->amOnPage('/company-users');
        $I->seeCurrentUrlEquals('/auth/login');
    }

    public function indexListsOnlyUsersFromTheLoggedInUsersCompanyAndSupportsSearch(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $otherCompany = $this->makeCompany($I);

        $owner = $this->makeCustomer($I, $company, ['email' => 'owner@example.test', 'firstName' => 'Owner', 'roles' => ['ROLE_COMPANY_OWNER']]);
        $this->makeCustomer($I, $company, ['email' => 'staffer@example.test', 'firstName' => 'Staffer']);
        $this->makeCustomer($I, $otherCompany, ['email' => 'outsider@example.test', 'firstName' => 'Outsider']);

        $this->loginAs($I, $owner);

        $I->amOnPage('/company-users');
        $I->seeResponseCodeIsSuccessful();
        $I->see('My Company User');

        $I->sendAjaxGetRequest('/company-users');
        $I->seeResponseCodeIsSuccessful();
        $response = json_decode($I->grabPageSource(), true);
        $I->assertSame(2, $response['total']);
        $I->assertStringContainsString('owner@example.test', $response['html']);
        $I->assertStringContainsString('staffer@example.test', $response['html']);
        $I->assertStringNotContainsString('outsider@example.test', $response['html']);

        $I->sendAjaxGetRequest('/company-users?q=staffer');
        $I->seeResponseCodeIsSuccessful();
        $filtered = json_decode($I->grabPageSource(), true);
        $I->assertSame(1, $filtered['total']);
        $I->assertStringContainsString('staffer@example.test', $filtered['html']);
        $I->assertStringNotContainsString('owner@example.test', $filtered['html']);
    }

    public function createRendersAFormForALoggedInCustomer(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $owner = $this->makeCustomer($I, $company, ['roles' => ['ROLE_COMPANY_OWNER']]);
        $this->loginAs($I, $owner);

        $I->amOnPage('/company-users/create');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Create Company User');
    }

    public function creatingWithBlankRequiredFieldsShowsValidationErrors(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $owner = $this->makeCustomer($I, $company, ['roles' => ['ROLE_COMPANY_OWNER']]);
        $this->loginAs($I, $owner);

        $I->amOnPage('/company-users/create');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/company-users/create', [
            '_token' => $token,
            'first_name' => '',
            'last_name' => '',
            'email' => '',
            'password' => '',
            'confirm_password' => '',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('First name is required.');
        $I->see('Last name is required.');
        $I->see('Email is required.');
        $I->see('Password and confirm password are required.');
    }

    public function creatingWithMismatchedPasswordsShowsAFieldError(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $owner = $this->makeCustomer($I, $company, ['roles' => ['ROLE_COMPANY_OWNER']]);
        $this->loginAs($I, $owner);

        $I->amOnPage('/company-users/create');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/company-users/create', [
            '_token' => $token,
            'first_name' => 'New',
            'last_name' => 'Staffer',
            'email' => 'new-staffer@example.test',
            'password' => 'a-password-123',
            'confirm_password' => 'a-different-password',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Passwords do not match.');
    }

    public function creatingWithAnEmailAlreadyInUseShowsAFieldError(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $owner = $this->makeCustomer($I, $company, ['roles' => ['ROLE_COMPANY_OWNER']]);
        $this->makeCustomer($I, $company, ['email' => 'taken@example.test']);
        $this->loginAs($I, $owner);

        $I->amOnPage('/company-users/create');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/company-users/create', [
            '_token' => $token,
            'first_name' => 'New',
            'last_name' => 'Staffer',
            'email' => 'taken@example.test',
            'password' => 'a-password-123',
            'confirm_password' => 'a-password-123',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('An account with this email already exists.');
    }

    public function creatingWithAnInvalidCsrfTokenRedirectsWithAnErrorFlash(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $owner = $this->makeCustomer($I, $company, ['roles' => ['ROLE_COMPANY_OWNER']]);
        $this->loginAs($I, $owner);

        $I->sendAjaxPostRequest('/company-users/create', [
            '_token' => 'not-a-real-token',
            'first_name' => 'New',
            'last_name' => 'Staffer',
            'email' => 'new-staffer@example.test',
            'password' => 'a-password-123',
            'confirm_password' => 'a-password-123',
        ]);
        $I->seeResponseCodeIs(403);
        $I->assertStringContainsString('Your session expired', $I->grabPageSource());

        $I->dontSeeInRepository(CustomerUser::class, ['email' => 'new-staffer@example.test']);
    }

    public function successfullyCreatingACompanyUserPersistsWithHashedPasswordAndOwnerRole(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $owner = $this->makeCustomer($I, $company, ['roles' => ['ROLE_COMPANY_OWNER']]);
        $this->loginAs($I, $owner);

        $I->amOnPage('/company-users/create');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/company-users/create', [
            '_token' => $token,
            'first_name' => 'New',
            'last_name' => 'Staffer',
            'email' => 'new-owner@example.test',
            'status' => 'Active',
            'role' => 'Owner',
            'password' => 'a-password-123',
            'confirm_password' => 'a-password-123',
        ]);
        $I->seeResponseCodeIsSuccessful();
        // The redirect target (/company-users) branches on the X-Requested-With header, which
        // sendAjaxPostRequest leaves set on the followed redirect too, so it returns JSON here
        // rather than the flash-bearing HTML page — assert the persisted state instead.
        $I->seeCurrentUrlEquals('/company-users');

        $created = $I->grabEntityFromRepository(CustomerUser::class, ['email' => 'new-owner@example.test']);
        $I->assertSame($company->getId(), $created->getCompany()?->getId());
        // getRoles() always appends ROLE_CUSTOMER on top of the stored role (see CustomerUser::getRoles()).
        $I->assertSame(['ROLE_COMPANY_OWNER', 'ROLE_CUSTOMER'], $created->getRoles());
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $I->assertTrue($hasher->isPasswordValid($created, 'a-password-123'));
    }

    public function editPrefillsTheExistingUsersFields(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $owner = $this->makeCustomer($I, $company, ['roles' => ['ROLE_COMPANY_OWNER']]);
        $staffer = $this->makeCustomer($I, $company, ['email' => 'staffer@example.test', 'firstName' => 'Sam', 'lastName' => 'Staff']);
        $this->loginAs($I, $owner);

        $I->amOnPage('/company-users/' . $staffer->getId() . '/edit');
        $I->seeResponseCodeIsSuccessful();
        $I->seeInField('first_name', 'Sam');
        $I->seeInField('last_name', 'Staff');
        $I->seeInField('email', 'staffer@example.test');
    }

    public function editingAUserFromAnotherCompanyShowsNotFoundAndRedirects(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $otherCompany = $this->makeCompany($I);
        $owner = $this->makeCustomer($I, $company, ['roles' => ['ROLE_COMPANY_OWNER']]);
        $outsider = $this->makeCustomer($I, $otherCompany, ['email' => 'outsider@example.test']);
        $this->loginAs($I, $owner);

        $I->amOnPage('/company-users/' . $outsider->getId() . '/edit');
        $I->seeCurrentUrlEquals('/company-users');
        $I->see('User not found.');
    }

    public function updatingUserFieldsPersistsAndRedirects(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $owner = $this->makeCustomer($I, $company, ['roles' => ['ROLE_COMPANY_OWNER']]);
        $staffer = $this->makeCustomer($I, $company, ['email' => 'staffer@example.test', 'firstName' => 'Sam', 'lastName' => 'Staff']);
        $this->loginAs($I, $owner);

        $I->amOnPage('/company-users/' . $staffer->getId() . '/edit');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/company-users/' . $staffer->getId() . '/edit', [
            '_token' => $token,
            'first_name' => 'Samantha',
            'last_name' => 'Staffer',
            'email' => 'staffer@example.test',
            'status' => 'Inactive',
            'role' => 'Company Staff',
        ]);
        $I->seeResponseCodeIsSuccessful();
        // The redirect target (/company-users) branches on the X-Requested-With header, which
        // sendAjaxPostRequest leaves set on the followed redirect too, so it returns JSON here
        // rather than the flash-bearing HTML page — assert the persisted state instead.
        $I->seeInRepository(CustomerUser::class, [
            'id' => $staffer->getId(),
            'firstName' => 'Samantha',
            'lastName' => 'Staffer',
            'status' => 'Inactive',
        ]);
    }

    public function updatingWithANewPasswordHashesIt(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $owner = $this->makeCustomer($I, $company, ['roles' => ['ROLE_COMPANY_OWNER']]);
        $staffer = $this->makeCustomer($I, $company, ['email' => 'staffer@example.test']);
        $oldHash = $staffer->getPassword();
        $this->loginAs($I, $owner);

        $I->amOnPage('/company-users/' . $staffer->getId() . '/edit');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/company-users/' . $staffer->getId() . '/edit', [
            '_token' => $token,
            'first_name' => 'Sam',
            'last_name' => 'Staff',
            'email' => 'staffer@example.test',
            'password' => 'brand-new-password-123',
            'confirm_password' => 'brand-new-password-123',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $refreshed = $I->grabEntityFromRepository(CustomerUser::class, ['id' => $staffer->getId()]);
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $I->assertTrue($hasher->isPasswordValid($refreshed, 'brand-new-password-123'));
        $I->assertNotSame($oldHash, $refreshed->getPassword());
    }

    /**
     * #522 §1: the hard-delete action, its route and its button are gone for everyone — owner
     * included. Deactivating via the edit form's status field replaced it, so there is no longer
     * any URL under /company-users that removes a row.
     */
    public function theHardDeleteRouteNoLongerExistsForAnyone(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $owner = $this->makeCustomer($I, $company, ['roles' => ['ROLE_COMPANY_OWNER']]);
        $staffer = $this->makeCustomer($I, $company, ['email' => 'staffer@example.test']);
        $this->loginAs($I, $owner);

        $I->sendAjaxPostRequest('/company-users/' . $staffer->getId() . '/delete', ['_token' => 'anything']);
        $I->seeResponseCodeIs(404);

        $I->seeInRepository(CustomerUser::class, ['id' => $staffer->getId()]);

        // And the button that used to point at it is gone from the list for an owner too.
        $I->sendAjaxGetRequest('/company-users');
        $html = json_decode($I->grabPageSource(), true)['html'];
        $I->assertStringNotContainsString('/delete', $html);
    }

    public function sendingAResetLinkGeneratesATokenForTheUser(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $owner = $this->makeCustomer($I, $company, ['roles' => ['ROLE_COMPANY_OWNER']]);
        $staffer = $this->makeCustomer($I, $company, ['email' => 'staffer@example.test']);
        $this->loginAs($I, $owner);

        $I->amOnPage('/company-users');
        $csrfToken = $I->grabAttributeFrom(
            'form[action$="/' . $staffer->getId() . '/reset"] input[name="_token"]',
            'value'
        );

        $I->sendAjaxPostRequest('/company-users/' . $staffer->getId() . '/reset', ['_token' => $csrfToken]);
        $I->seeResponseCodeIsSuccessful();

        $refreshed = $I->grabEntityFromRepository(CustomerUser::class, ['id' => $staffer->getId()]);
        $I->assertNotNull($refreshed->getResetToken());
        $I->assertNotNull($refreshed->getResetTokenExpiresAt());
        $I->assertGreaterThan(new \DateTimeImmutable(), $refreshed->getResetTokenExpiresAt());
    }

    /**
     * #450, bug B: a company admin sending a reset link to their own staff is not that staffer
     * self-serving — the token above uses the invite lifetime (AppSettings::inviteTokenExpiresAt()),
     * not the 1 hour forgot_password.html.twig used to hardcode regardless of what the token
     * actually granted. The recipient must not be told the link dies in an hour when it lasts 30
     * days (the code default, since no invite_token_expiry_days row exists in this test database).
     */
    public function sendingAResetLinkEmailShowsConfiguredExpiryInsteadOfHardcodedOneHour(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $owner = $this->makeCustomer($I, $company, ['roles' => ['ROLE_COMPANY_OWNER']]);
        $staffer = $this->makeCustomer($I, $company, ['email' => 'staffer@example.test']);
        $this->loginAs($I, $owner);

        $I->amOnPage('/company-users');
        $csrfToken = $I->grabAttributeFrom(
            'form[action$="/' . $staffer->getId() . '/reset"] input[name="_token"]',
            'value'
        );

        // Without this the mailer collector inspected below belongs to the redirect target
        // (a GET that sends nothing), not this POST -- same trap AdminDatabaseConsoleCest works
        // around.
        $I->stopFollowingRedirects();
        $I->sendAjaxPostRequest('/company-users/' . $staffer->getId() . '/reset', ['_token' => $csrfToken]);
        $I->seeResponseCodeIs(302);

        $I->seeEmailIsSent();
        $email = $I->grabLastSentEmail();
        $expected = 'this link will expire in ' . AppSettings::INVITE_EXPIRY_DEFAULT_DAYS . ' days.';
        $I->assertStringContainsString($expected, (string) $email->getHtmlBody());
        $I->assertStringNotContainsString('1 hour', (string) $email->getHtmlBody());
    }

    public function sendingAResetLinkWithAnInvalidCsrfTokenDoesNotGenerateAToken(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $owner = $this->makeCustomer($I, $company, ['roles' => ['ROLE_COMPANY_OWNER']]);
        $staffer = $this->makeCustomer($I, $company, ['email' => 'staffer@example.test']);
        $this->loginAs($I, $owner);

        $I->sendAjaxPostRequest('/company-users/' . $staffer->getId() . '/reset', ['_token' => 'not-a-real-token']);
        $I->seeResponseCodeIs(403);

        $refreshed = $I->grabEntityFromRepository(CustomerUser::class, ['id' => $staffer->getId()]);
        $I->assertNull($refreshed->getResetToken());
    }

    // ---------------------------------------------------------------------------------------
    // #522: role gating. Every action on this controller used to check only "same company",
    // which made ROLE_COMPANY_STAFF equivalent to ROLE_COMPANY_OWNER in practice. Each case
    // below is paired: the staff actor is refused, the owner actor still succeeds.
    // ---------------------------------------------------------------------------------------

    /** Negative: the self-promotion path — staff editing their own row to set role=Owner. */
    public function staffCannotPromoteThemselvesToOwnerViaEdit(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $staffer = $this->makeCustomer($I, $company, ['email' => 'staffer@example.test']);
        $this->loginAs($I, $staffer);

        $I->sendAjaxPostRequest('/company-users/' . $staffer->getId() . '/edit', [
            '_token' => $this->grabCsrfToken($I),
            'first_name' => 'Sam',
            'last_name' => 'Staff',
            'email' => 'staffer@example.test',
            'status' => 'Active',
            'role' => 'Owner',
        ]);

        $refreshed = $I->grabEntityFromRepository(CustomerUser::class, ['id' => $staffer->getId()]);
        $I->assertSame(['ROLE_COMPANY_STAFF', 'ROLE_CUSTOMER'], $refreshed->getRoles());
    }

    /** Negative: staff cannot even open the edit form — for a teammate or for themselves. */
    public function staffCannotOpenTheEditFormForAnyone(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $owner = $this->makeCustomer($I, $company, ['email' => 'owner@example.test', 'roles' => ['ROLE_COMPANY_OWNER']]);
        $staffer = $this->makeCustomer($I, $company, ['email' => 'staffer@example.test']);
        $this->loginAs($I, $staffer);

        foreach ([$owner->getId(), $staffer->getId()] as $targetId) {
            $I->amOnPage('/company-users/' . $targetId . '/edit');
            $I->seeCurrentUrlEquals('/company-users');
            $I->see('Only a company owner can manage company users.');
        }
    }

    /** Negative: staff cannot demote the real owner by stripping ROLE_COMPANY_OWNER off their row. */
    public function staffCannotDemoteTheOwner(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $owner = $this->makeCustomer($I, $company, ['email' => 'owner@example.test', 'roles' => ['ROLE_COMPANY_OWNER']]);
        $staffer = $this->makeCustomer($I, $company, ['email' => 'staffer@example.test']);
        $this->loginAs($I, $staffer);

        $I->sendAjaxPostRequest('/company-users/' . $owner->getId() . '/edit', [
            '_token' => $this->grabCsrfToken($I),
            'first_name' => 'Owner',
            'last_name' => 'Doe',
            'email' => 'owner@example.test',
            'status' => 'Active',
            'role' => 'Company Staff',
        ]);

        $refreshed = $I->grabEntityFromRepository(CustomerUser::class, ['id' => $owner->getId()]);
        $I->assertSame(['ROLE_COMPANY_OWNER', 'ROLE_CUSTOMER'], $refreshed->getRoles());
    }

    /**
     * Negative: the account-takeover chain. Staff repoints a teammate's email at an address it
     * controls, then mails itself that teammate's reset link. Both halves have to fail; asserting
     * the email is unchanged and no reset token was minted covers both.
     */
    public function staffCannotRepointATeammatesEmailAndHarvestTheirResetLink(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $owner = $this->makeCustomer($I, $company, ['email' => 'owner@example.test', 'roles' => ['ROLE_COMPANY_OWNER']]);
        $staffer = $this->makeCustomer($I, $company, ['email' => 'staffer@example.test']);
        $this->loginAs($I, $staffer);

        $csrf = $this->grabCsrfToken($I);

        $I->sendAjaxPostRequest('/company-users/' . $owner->getId() . '/edit', [
            '_token' => $csrf,
            'first_name' => 'Owner',
            'last_name' => 'Doe',
            'email' => 'attacker@evil.test',
            'status' => 'Active',
            'role' => 'Owner',
        ]);

        $I->sendAjaxPostRequest('/company-users/' . $owner->getId() . '/reset', ['_token' => $csrf]);

        $refreshed = $I->grabEntityFromRepository(CustomerUser::class, ['id' => $owner->getId()]);
        $I->assertSame('owner@example.test', $refreshed->getEmail());
        $I->assertNull($refreshed->getResetToken());
    }

    /** Negative: a staff create() POST asking for role=Owner produces a Company Staff account. */
    public function staffCreatingAUserCannotMintAnOwner(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $staffer = $this->makeCustomer($I, $company, ['email' => 'staffer@example.test']);
        $this->loginAs($I, $staffer);

        $I->amOnPage('/company-users/create');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        // The option the POST below asks for is not even offered to a staff actor.
        $I->dontSee('Owner', 'select[name="role"]');

        $I->sendAjaxPostRequest('/company-users/create', [
            '_token' => $token,
            'first_name' => 'New',
            'last_name' => 'Person',
            'email' => 'minted@example.test',
            'status' => 'Active',
            'role' => 'Owner',
            'password' => 'a-password-123',
            'confirm_password' => 'a-password-123',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $created = $I->grabEntityFromRepository(CustomerUser::class, ['email' => 'minted@example.test']);
        $I->assertSame(['ROLE_COMPANY_STAFF', 'ROLE_CUSTOMER'], $created->getRoles());
    }

    /** Positive: staff keep the part of create() they are supposed to have — adding a teammate. */
    public function staffCanStillCreateAStaffTeammate(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $staffer = $this->makeCustomer($I, $company, ['email' => 'staffer@example.test']);
        $this->loginAs($I, $staffer);

        $I->amOnPage('/company-users/create');
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/company-users/create', [
            '_token' => $token,
            'first_name' => 'New',
            'last_name' => 'Teammate',
            'email' => 'teammate@example.test',
            'status' => 'Active',
            'role' => 'Company Staff',
            'password' => 'a-password-123',
            'confirm_password' => 'a-password-123',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $created = $I->grabEntityFromRepository(CustomerUser::class, ['email' => 'teammate@example.test']);
        $I->assertSame($company->getId(), $created->getCompany()?->getId());
        $I->assertSame(['ROLE_COMPANY_STAFF', 'ROLE_CUSTOMER'], $created->getRoles());
    }

    /** Negative: staff cannot mail a reset link at all, even to a plain teammate. */
    public function staffCannotSendAResetLink(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $staffer = $this->makeCustomer($I, $company, ['email' => 'staffer@example.test']);
        $target = $this->makeCustomer($I, $company, ['email' => 'target@example.test']);
        $this->loginAs($I, $staffer);

        $I->sendAjaxPostRequest('/company-users/' . $target->getId() . '/reset', [
            '_token' => $this->grabCsrfToken($I),
        ]);

        $refreshed = $I->grabEntityFromRepository(CustomerUser::class, ['id' => $target->getId()]);
        $I->assertNull($refreshed->getResetToken());
    }

    /** Positive: the owner keeps every action the checks above deny to staff. */
    public function ownerCanStillEditPromoteAndSendResetLinks(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $owner = $this->makeCustomer($I, $company, ['email' => 'owner@example.test', 'roles' => ['ROLE_COMPANY_OWNER']]);
        $staffer = $this->makeCustomer($I, $company, ['email' => 'staffer@example.test']);
        $this->loginAs($I, $owner);

        $I->amOnPage('/company-users/' . $staffer->getId() . '/edit');
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/company-users/' . $staffer->getId() . '/edit', [
            '_token' => $token,
            'first_name' => 'Sam',
            'last_name' => 'Staff',
            'email' => 'staffer@example.test',
            'status' => 'Active',
            'role' => 'Owner',
        ]);
        $I->seeResponseCodeIsSuccessful();

        $I->sendAjaxPostRequest('/company-users/' . $staffer->getId() . '/reset', ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();

        // Both effects asserted off a single grab, deliberately: grabbing between the two requests
        // parks the entity in the test EntityManager's identity map, and the second grab then hands
        // back that stale copy instead of re-reading what the reset request wrote.
        $refreshed = $I->grabEntityFromRepository(CustomerUser::class, ['id' => $staffer->getId()]);
        $I->assertSame(['ROLE_COMPANY_OWNER', 'ROLE_CUSTOMER'], $refreshed->getRoles());
        $I->assertNotNull($refreshed->getResetToken());
    }

    /** Positive: staff keep read access to the list — only acting on teammates is owner-only. */
    public function staffCanStillViewTheListButSeeNoRowActions(FunctionalTester $I): void
    {
        $company = $this->makeCompany($I);
        $this->makeCustomer($I, $company, ['email' => 'owner@example.test', 'roles' => ['ROLE_COMPANY_OWNER']]);
        $staffer = $this->makeCustomer($I, $company, ['email' => 'staffer@example.test']);
        $this->loginAs($I, $staffer);

        $I->sendAjaxGetRequest('/company-users');
        $I->seeResponseCodeIsSuccessful();
        $html = json_decode($I->grabPageSource(), true)['html'];

        $I->assertStringContainsString('owner@example.test', $html);
        $I->assertStringNotContainsString('/edit', $html);
        $I->assertStringNotContainsString('/reset', $html);
    }
}
