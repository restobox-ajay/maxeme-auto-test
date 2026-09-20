<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\SalesOrder;
use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CompanyNote;
use App\Entity\CustomerUser;
use App\Service\AppSettings;
use App\Service\DocumentActor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers Admin\CompanyController — the /admin/company index (search/filters, XHR/JSON mode),
 *  create()/update() form validation and persistence, detail() rendering with its order list,
 *  delete()/reactivate() status toggling (and cascading customer-user status), and the
 *  addNote()/updateNote()/deleteNote() JSON round trip. */
final class AdminCompanyCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('admin-co-functional-test@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
    }

    private function makeCompany(FunctionalTester $I, string $name, string $status = 'Active', ?string $code = null): Company
    {
        $company = (new Company())
            ->setName($name)
            ->setCode($code ?? strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 8)));
        $company->setStatus($status, DocumentActor::system());
        $I->haveInRepository($company);

        return $company;
    }

    private function makeCustomerUser(FunctionalTester $I, Company $company, string $status = 'Active', array $roles = ['ROLE_COMPANY_STAFF']): CustomerUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $user = (new CustomerUser())
            ->setEmail('co-user-' . uniqid() . '@example.test')
            ->setCompany($company)
            ->setRoles($roles);
        $user->setStatus($status, DocumentActor::system());
        $user->setPassword($hasher->hashPassword($user, 'current-password-123'));
        $I->haveInRepository($user);

        return $user;
    }

    public function indexListsCompaniesAndAppliesSearchAndFilters(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $this->makeCompany($I, 'Co Index Alpha', 'Active');
        $this->makeCompany($I, 'Co Index Beta', 'Inactive');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/company');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Co Index Alpha');
        $I->see('Co Index Beta');

        $I->amOnPage('/admin/company?q=Alpha');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Co Index Alpha');
        $I->dontSee('Co Index Beta');

        $I->amOnPage('/admin/company?filters[status]=Inactive');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Co Index Beta');
        $I->dontSee('Co Index Alpha');
    }

    public function indexAsXhrReturnsJsonWithRenderedRowsAndPagination(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $this->makeCompany($I, 'Co Xhr Company');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxGetRequest('/admin/company?q=Co+Xhr+Company');
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertStringContainsString('Co Xhr Company', $response['html']);
        $I->assertSame(1, $response['total']);
        $I->assertSame(1, $response['page']);
        $I->assertSame(1, $response['pages']);
    }

    public function createRendersTheForm(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/company/create');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Create');
        $I->seeInField('status', 'Active');
    }

    public function creatingWithValidDataPersistsAndRedirectsToIndex(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/company/create');
        $I->sendAjaxPostRequest('/admin/company/create', [
            '_token' => $I->csrfToken(),
            'name' => 'Co Created Corp',
            'code' => 'COCREATED1',
            'status' => 'Active',
            'primary_email' => 'created@example.test',
            'account_type' => 'Business',
        ]);
        $I->seeCurrentUrlEquals('/admin/company');

        $I->seeInRepository(Company::class, [
            'name' => 'Co Created Corp',
            'code' => 'COCREATED1',
            'primaryEmail' => 'created@example.test',
        ]);
    }

    /**
     * #450: handleNewAccountEmail() sets the new customer user's token to the invite lifetime
     * (AppSettings::inviteTokenExpiresAt()), but invite.html.twig used to hardcode "1 hour"
     * regardless. No invite_token_expiry_days row exists in this test database, so this exercises
     * the code default (30 days).
     */
    public function creatingWithSendAccountEmailShowsConfiguredExpiryInsteadOfHardcodedOneHour(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/company/create');
        $I->sendAjaxPostRequest('/admin/company/create', [
            '_token' => $I->csrfToken(),
            'name' => 'Co Invite Corp',
            'code' => 'COINVITE1',
            'status' => 'Active',
            'primary_email' => 'invite-corp@example.test',
            'account_type' => 'Business',
            'send_account_email' => 'yes',
        ]);
        $I->seeCurrentUrlEquals('/admin/company');

        // Read from email_log, not the transport: local delivery is sandboxed by design, so
        // seeEmailIsSent() fails against a correct application. email_log holds the body that was
        // actually rendered, which is what this assertion is about.
        $body = $I->grabLastSentEmailBody('invite-corp@example.test');
        $expected = 'This invitation will expire in ' . AppSettings::INVITE_EXPIRY_DEFAULT_DAYS . ' days.';
        $I->assertStringContainsString($expected, $body);
        $I->assertStringNotContainsString('1 hour', $body);
    }

    /**
     * The "Send user a new account email with password link?" choice used to gate the customer
     * account's creation itself, not just the invite email — choosing "No" left the company with
     * no customer login at all. It should only skip the email; the account is created either way.
     */
    public function creatingWithSendAccountEmailNoStillCreatesTheCustomerAccountWithoutEmailing(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/company/create');
        $I->sendAjaxPostRequest('/admin/company/create', [
            '_token' => $I->csrfToken(),
            'name' => 'Co No Invite Corp',
            'code' => 'CONOINVITE1',
            'status' => 'Active',
            'primary_email' => 'no-invite-corp@example.test',
            'account_type' => 'Business',
            'send_account_email' => 'no',
        ]);
        $I->seeCurrentUrlEquals('/admin/company');

        $I->seeInRepository(CustomerUser::class, [
            'email' => 'no-invite-corp@example.test',
            'status' => 'Active',
        ]);

        $em = $I->grabService(EntityManagerInterface::class);
        $log = $em->getRepository(\App\Entity\EmailLog::class)->findOneBy(['recipient' => 'no-invite-corp@example.test']);
        $I->assertNull($log, 'No email should have been sent when "No" was chosen.');
    }

    public function creatingWithABlankNameFailsValidationAndDoesNotPersist(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/company/create');
        $I->sendAjaxPostRequest('/admin/company/create', [
            '_token' => $I->csrfToken(),
            'name' => '   ',
            'code' => 'COBLANKNAME',
            'status' => 'Active',
        ]);
        $I->seeResponseCodeIs(422);
        $I->see('Company name is required.');

        $I->dontSeeInRepository(Company::class, ['code' => 'COBLANKNAME']);
    }

    /** External ID is an externally-assigned code, and plenty of customers have none. */
    public function creatingWithoutAnExternalIdPersistsWithANullCode(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/company/create');
        $I->dontSeeElement('input[name=code][required]');

        $I->sendAjaxPostRequest('/admin/company/create', [
            '_token' => $I->csrfToken(),
            'name' => 'Co No External Id',
            'code' => '',
            'status' => 'Active',
            'account_type' => 'Business',
        ]);
        $I->seeCurrentUrlEquals('/admin/company');

        $I->seeInRepository(Company::class, ['name' => 'Co No External Id', 'code' => null]);
    }

    public function twoCompaniesWithoutAnExternalIdCanCoexist(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        foreach (['Co Blank Code One', 'Co Blank Code Two'] as $name) {
            $I->amOnPage('/admin/company/create');
            $I->sendAjaxPostRequest('/admin/company/create', [
                '_token' => $I->csrfToken(),
                'name' => $name,
                'code' => '',
                'status' => 'Active',
            ]);
            $I->seeCurrentUrlEquals('/admin/company');
            $I->seeInRepository(Company::class, ['name' => $name, 'code' => null]);
        }
    }

    public function clearingTheExternalIdOnUpdatePersistsANullCode(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'Co Clear External Id', 'Active', 'COCLEARME');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/company/update/' . $company->getId());
        $I->sendAjaxPostRequest('/admin/company/update/' . $company->getId(), [
            '_token' => $I->csrfToken(),
            'name' => 'Co Clear External Id',
            'code' => '   ',
            'status' => 'Active',
        ]);
        $I->seeCurrentUrlEquals('/admin/company');

        $I->seeInRepository(Company::class, ['id' => $company->getId(), 'code' => null]);
    }

    /** External IDs come from the customer's own systems, so this app must not enforce uniqueness. */
    public function twoCompaniesCanShareTheSameExternalId(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $this->makeCompany($I, 'Co Shared Code Existing', 'Active', 'COCODEDUPE');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/company/create');
        $I->sendAjaxPostRequest('/admin/company/create', [
            '_token' => $I->csrfToken(),
            'name' => 'Co Shared Code New',
            'code' => 'COCODEDUPE',
            'status' => 'Active',
        ]);
        $I->seeCurrentUrlEquals('/admin/company');

        $I->seeInRepository(Company::class, ['name' => 'Co Shared Code Existing', 'code' => 'COCODEDUPE']);
        $I->seeInRepository(Company::class, ['name' => 'Co Shared Code New', 'code' => 'COCODEDUPE']);
    }

    public function anExistingExternalIdCanBeReusedOnUpdate(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $this->makeCompany($I, 'Co Reuse Code Existing', 'Active', 'COREUSECODE');
        $company = $this->makeCompany($I, 'Co Reuse Code Target', 'Active', 'COREUSEOTHER');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/company/update/' . $company->getId());
        $I->sendAjaxPostRequest('/admin/company/update/' . $company->getId(), [
            '_token' => $I->csrfToken(),
            'name' => 'Co Reuse Code Target',
            'code' => 'COREUSECODE',
            'status' => 'Active',
        ]);
        $I->seeCurrentUrlEquals('/admin/company');

        $I->seeInRepository(Company::class, ['id' => $company->getId(), 'code' => 'COREUSECODE']);
    }

    public function updateRendersThePrefilledFormAndPersistsChanges(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'Co Update Original');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/company/update/' . $company->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeInField('name', 'Co Update Original');

        $I->sendAjaxPostRequest('/admin/company/update/' . $company->getId(), [
            '_token' => $I->csrfToken(),
            'name' => 'Co Update Renamed',
            'code' => $company->getCode(),
            'status' => 'Active',
            'primary_email' => 'updated@example.test',
        ]);
        $I->seeCurrentUrlEquals('/admin/company');

        $I->seeInRepository(Company::class, [
            'id' => $company->getId(),
            'name' => 'Co Update Renamed',
            'primaryEmail' => 'updated@example.test',
        ]);
    }

    public function updatingWithABlankNameFailsValidationAndLeavesTheOriginalNameInPlace(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'Co Update Invalid Original');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/company/update/' . $company->getId());
        $I->sendAjaxPostRequest('/admin/company/update/' . $company->getId(), [
            '_token' => $I->csrfToken(),
            'name' => '',
            'code' => $company->getCode(),
            'status' => 'Active',
        ]);
        $I->seeResponseCodeIs(422);
        $I->see('Company name is required.');

        $I->seeInRepository(Company::class, [
            'id' => $company->getId(),
            'name' => 'Co Update Invalid Original',
        ]);
    }

    public function updatingAnUnknownIdRedirectsToIndexWithAnErrorFlash(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/company/update/999999999');
        $I->seeCurrentUrlEquals('/admin/company');
        $I->see('Customer could not be found.');
    }

    public function detailRendersCompanyInfoAndOrdersOrderedNewestFirst(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'Co Detail Company');
        $olderOrder = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('ORD-DETAIL-OLDER')
            ->setSubtotal('50.00')
            ->setTax('0.00')
            ->setTotal('50.00');
        $I->haveInRepository($olderOrder);
        $newerOrder = (new SalesOrder())
            ->setCompany($company)
            ->setOrderNumber('ORD-DETAIL-NEWER')
            ->setSubtotal('75.00')
            ->setTax('0.00')
            ->setTotal('75.00');
        $I->haveInRepository($newerOrder);
        // Both orders are live ones on the company's list. Since #539 stage 2 that is approve() on
        // the persisted Draft; neither has invoices, so both settle at Approved. (The company and
        // customer-user statuses elsewhere in this file are a different vocabulary and unchanged.)
        $olderOrder->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $newerOrder->setStatus('Approved', DocumentActor::system(), 'Order approved.');
        $I->grabService(EntityManagerInterface::class)->flush();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/company/detail/' . $company->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('Co Detail Company');
        $I->see('ORD-DETAIL-OLDER');
        $I->see('ORD-DETAIL-NEWER');
    }

    /**
     * The Company Details card printed the literal string "6041234560" as every company's phone
     * number, so the screen showed one hardcoded number no matter which company you opened.
     */
    public function detailShowsTheCompanysOwnPhoneNumberAndNotAHardcodedOne(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'Co Detail Phone');
        $company->setPhoneNumber('+1 250 555 0142');
        $I->grabService(EntityManagerInterface::class)->flush();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/company/detail/' . $company->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->see('+1 250 555 0142', '.company-summary-body');
        $I->dontSee('6041234560');
    }

    /** A company with no phone number on file gets a dash, not someone else's number. */
    public function detailShowsADashWhenTheCompanyHasNoPhoneNumber(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'Co Detail No Phone');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/company/detail/' . $company->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->dontSee('6041234560');
        // The dash itself, which the method is named after, and which nothing asserted (#594).
        // dontSee() of one historical literal passes for a blank cell, the string "null", a zero,
        // or — the failure the sibling test above exists for — another company's number.
        $I->seeInSource('<strong>Phone Number:</strong> -');
    }

    public function detailWithAnUnknownIdRedirectsToIndexWithAnErrorFlash(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/company/detail/999999999');
        $I->seeCurrentUrlEquals('/admin/company');
        $I->see('Customer could not be found.');
    }

    public function deletingAnActiveCompanyDeactivatesItAndDisablesItsCustomerUsers(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'Co Deactivate Me', 'Active');
        $user = $this->makeCustomerUser($I, $company, 'Active');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/company/delete/' . $company->getId(), ['_token' => $I->csrfToken()]);
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['ok']);
        $I->assertStringContainsString('deactivated successfully', $response['message']);

        $I->seeInRepository(Company::class, ['id' => $company->getId(), 'status' => 'Inactive']);
        $I->seeInRepository(CustomerUser::class, ['id' => $user->getId(), 'status' => 'Inactive']);
    }

    public function reactivatingAnInactiveCompanyReenablesItsCustomerUsersAndAssignsAnOwnerIfMissing(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'Co Reactivate Me', 'Inactive');
        $user = $this->makeCustomerUser($I, $company, 'Inactive', ['ROLE_COMPANY_STAFF']);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/company/reactivate/' . $company->getId(), ['_token' => $I->csrfToken()]);
        $I->seeResponseCodeIsSuccessful();

        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['ok']);
        $I->assertStringContainsString('reactivated successfully', $response['message']);

        $I->seeInRepository(Company::class, ['id' => $company->getId(), 'status' => 'Active']);
        $I->seeInRepository(CustomerUser::class, ['id' => $user->getId(), 'status' => 'Active']);

        $updated = $I->grabEntityFromRepository(CustomerUser::class, ['id' => $user->getId()]);
        $I->assertContains('ROLE_COMPANY_OWNER', $updated->getRoles());
    }

    public function deletingAnUnknownIdReturnsJsonNotFound(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/company/delete/999999999', ['_token' => $I->csrfToken()]);
        $I->seeResponseCodeIs(404);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['ok']);
    }

    public function notesCanBeAddedUpdatedAndDeletedViaJson(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'Co Notes Company');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/company/note/' . $company->getId(), [
            '_token' => $I->csrfToken(),'note' => 'First note']);
        $I->seeResponseCodeIsSuccessful();
        $addResponse = json_decode($I->grabPageSource(), true);
        $I->assertTrue($addResponse['ok']);
        $I->assertSame('First note', $addResponse['note']);
        $noteId = $addResponse['id'];
        $I->assertIsInt($noteId);

        $I->sendAjaxPostRequest('/admin/company/note/' . $company->getId() . '/update', [
            '_token' => $I->csrfToken(),
            'id' => $noteId,
            'note' => 'First note edited',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $updateResponse = json_decode($I->grabPageSource(), true);
        $I->assertTrue($updateResponse['ok']);
        $I->assertSame('First note edited', $updateResponse['note']);

        $I->sendAjaxPostRequest('/admin/company/note/' . $company->getId() . '/delete', [
            '_token' => $I->csrfToken(),'id' => $noteId]);
        $I->seeResponseCodeIsSuccessful();
        $deleteResponse = json_decode($I->grabPageSource(), true);
        $I->assertTrue($deleteResponse['ok']);

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $I->assertCount(0, $entityManager->getRepository(CompanyNote::class)->findBy(['company' => $company->getId()]));
    }

    public function addingABlankNoteFailsValidation(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = $this->makeCompany($I, 'Co Blank Note Company');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest('/admin/company/note/' . $company->getId(), [
            '_token' => $I->csrfToken(),'note' => '   ']);
        $I->seeResponseCodeIs(400);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['ok']);
    }
}
