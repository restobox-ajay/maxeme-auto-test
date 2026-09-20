<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CustomerUser;
use App\Entity\InvoicePayment;
use App\Service\DocumentActor;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\AdminUserCsrfTokens;
use Tests\Support\FunctionalTester;

/** Covers Admin\UserController — the /admin/user/staff and /admin/user/customer listings
 *  (search), staff/customer create() and update() validation and persistence, the JSON
 *  status()/delete() endpoints (including the "can't delete yourself" and
 *  "Admin can't manage Super Admin" guards), and unknown-id handling. */
final class AdminUserCest
{
    use AdminUserCsrfTokens;

    private function loginAsAdmin(FunctionalTester $I, string $email = 'admin-user-functional-test@example.test'): AdminUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail($email);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');

        return $admin;
    }

    private function makeAdmin(FunctionalTester $I, string $email, array $roles = []): AdminUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail($email)->setRoles($roles);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        return $admin;
    }

    private function makeCompany(FunctionalTester $I, string $name, string $status = 'Active'): Company
    {
        $company = (new Company())
            ->setName($name)
            ->setCode(strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 8)));
        $company->setStatus($status, DocumentActor::system());
        $I->haveInRepository($company);

        return $company;
    }

    private function makeCustomerUser(FunctionalTester $I, Company $company, string $email, string $status = 'Active'): CustomerUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $user = (new CustomerUser())
            ->setEmail($email)
            ->setCompany($company)
            ->setRoles(['ROLE_COMPANY_STAFF']);
        $user->setStatus($status, DocumentActor::system());
        $user->setPassword($hasher->hashPassword($user, 'current-password-123'));
        $I->haveInRepository($user);

        return $user;
    }

    private function dropAdmin(FunctionalTester $I, int $id): void
    {
        $entityManager = $I->grabService('doctrine.orm.entity_manager');
        $entityManager->remove($entityManager->find(AdminUser::class, $id));
        $entityManager->flush();
    }

    public function staffIndexListsAdminUsersAndSupportsSearch(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $this->makeAdmin($I, 'staff-search-alpha@example.test');
        $this->makeAdmin($I, 'staff-search-beta@example.test');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/user/staff');
        $I->seeResponseCodeIsSuccessful();
        $I->see('staff-search-alpha@example.test');
        $I->see('staff-search-beta@example.test');

        $I->amOnPage('/admin/user/staff?q=alpha');
        $I->seeResponseCodeIsSuccessful();
        $I->see('staff-search-alpha@example.test');
        $I->dontSee('staff-search-beta@example.test');
    }

    public function customerIndexListsCustomerUsersAcrossCompaniesAndSupportsSearch(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $companyA = $this->makeCompany($I, 'Customer Index Co A');
        $companyB = $this->makeCompany($I, 'Customer Index Co B');
        $this->makeCustomerUser($I, $companyA, 'cust-index-alpha@example.test');
        $this->makeCustomerUser($I, $companyB, 'cust-index-beta@example.test');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/user/customer');
        $I->seeResponseCodeIsSuccessful();
        $I->see('cust-index-alpha@example.test');
        $I->see('cust-index-beta@example.test');

        $I->sendAjaxGetRequest('/admin/user/customer?q=Customer+Index+Co+A');
        $I->seeResponseCodeIsSuccessful();
        $response = json_decode($I->grabPageSource(), true);
        $I->assertSame(1, $response['total']);
        $I->assertStringContainsString('cust-index-alpha@example.test', $response['html']);
        $I->assertStringNotContainsString('cust-index-beta@example.test', $response['html']);
    }

    public function staffCreateRendersTheForm(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/user/staff/create');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Create');
        $I->seeInField('status', 'Active');
    }

    public function creatingAStaffUserWithABlankEmailFailsValidation(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/user/staff/create');
        $I->sendAjaxPostRequest('/admin/user/staff/create', [
            '_token' => $I->grabAttributeFrom('form.js-admin-user-form input[name="_token"]', 'value'),
            'email' => '',
            'first_name' => 'No',
            'last_name' => 'Email',
            'status' => 'Active',
            'role' => 'Admin',
        ]);
        $I->seeResponseCodeIs(422);
        $I->see('User email is required.');

        $I->dontSeeInRepository(AdminUser::class, ['firstName' => 'No', 'lastName' => 'Email']);
    }

    public function creatingAStaffUserPersistsWithAGeneratedPasswordAndSelectedRole(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/user/staff/create');
        $I->sendAjaxPostRequest('/admin/user/staff/create', [
            '_token' => $I->grabAttributeFrom('form.js-admin-user-form input[name="_token"]', 'value'),
            'email' => 'new-staff@example.test',
            'first_name' => 'New',
            'last_name' => 'Staff',
            'status' => 'Active',
            'role' => 'Plant Staff',
        ]);
        $I->seeCurrentUrlEquals('/admin/user/staff');

        $created = $I->grabEntityFromRepository(AdminUser::class, ['email' => 'new-staff@example.test']);
        $I->assertContains('ROLE_PLANT_STAFF', $created->getRoles());
        $I->assertNotEmpty($created->getPassword());
    }

    public function customerCreateRequiresACompany(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/user/customer/create');
        $I->sendAjaxPostRequest('/admin/user/customer/create', [
            '_token' => $I->grabAttributeFrom('form.js-admin-user-form input[name="_token"]', 'value'),
            'email' => 'no-company@example.test',
            'first_name' => 'No',
            'last_name' => 'Company',
            'status' => 'Active',
            'role' => 'Company Staff',
            'company' => '',
        ]);
        $I->seeResponseCodeIs(422);
        $I->see('Please choose a customer.');

        $I->dontSeeInRepository(CustomerUser::class, ['email' => 'no-company@example.test']);
    }

    public function creatingACustomerUserPersistsWithCompanyAndRole(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Customer Create Co');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/user/customer/create');
        $I->sendAjaxPostRequest('/admin/user/customer/create', [
            '_token' => $I->grabAttributeFrom('form.js-admin-user-form input[name="_token"]', 'value'),
            'email' => 'new-customer@example.test',
            'first_name' => 'New',
            'last_name' => 'Customer',
            'status' => 'Active',
            'role' => 'Owner',
            'company' => (string) $company->getId(),
        ]);
        $I->seeCurrentUrlEquals('/admin/user/customer');

        $created = $I->grabEntityFromRepository(CustomerUser::class, ['email' => 'new-customer@example.test']);
        $I->assertSame($company->getId(), $created->getCompany()?->getId());
        $I->assertContains('ROLE_COMPANY_OWNER', $created->getRoles());
    }

    public function staffUpdateRendersPrefilledFormAndPersistsChanges(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $staff = $this->makeAdmin($I, 'staff-update-original@example.test');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/user/staff/update/' . $staff->getId());
        $I->seeResponseCodeIsSuccessful();
        // The email is shown as text now, not as a field: it is the login identifier for the admin
        // firewall and is not editable here. See issue #89 — this test used to post a changed address
        // and assert it stuck, which is the vulnerable behaviour it was meant to guard.
        $I->see('staff-update-original@example.test');
        $I->dontSeeElement('input[name="email"]');

        $I->sendAjaxPostRequest('/admin/user/staff/update/' . $staff->getId(), [
            '_token' => $I->grabAttributeFrom('form.js-admin-user-form input[name="_token"]', 'value'),
            'email' => 'staff-update-renamed@example.test',
            'first_name' => 'Renamed',
            'last_name' => 'Staff',
            'status' => 'Active',
            'role' => 'Admin',
        ]);
        $I->seeCurrentUrlEquals('/admin/user/staff');

        $I->seeInRepository(AdminUser::class, [
            'id' => $staff->getId(),
            'email' => 'staff-update-original@example.test',
            'firstName' => 'Renamed',
        ]);
        $I->dontSeeInRepository(AdminUser::class, ['email' => 'staff-update-renamed@example.test']);
    }

    public function updatingAnUnknownUserRedirectsWithAnErrorFlash(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/user/staff/update/999999999');
        $I->seeCurrentUrlEquals('/admin/user/staff');
        $I->see('User could not be found.');
    }

    public function updateStatusActivatesAndDeactivatesACustomerUser(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $company = $this->makeCompany($I, 'Status Toggle Co');
        $customer = $this->makeCustomerUser($I, $company, 'status-toggle@example.test', 'Inactive');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $token = $this->grabUserRowToken($I, 'customer', $customer->getId(), 'status');
        $I->sendAjaxPostRequest('/admin/user/status/customer/' . $customer->getId(), ['status' => 'Active', '_token' => $token]);
        $I->seeResponseCodeIsSuccessful();
        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['ok']);
        $I->assertSame('Active', $response['status']);
        $I->seeInRepository(CustomerUser::class, ['id' => $customer->getId(), 'status' => 'Active']);

        $I->sendAjaxPostRequest('/admin/user/status/customer/' . $customer->getId(), ['status' => 'Inactive', '_token' => $token]);
        $I->seeResponseCodeIsSuccessful();
        $I->seeInRepository(CustomerUser::class, ['id' => $customer->getId(), 'status' => 'Inactive']);
    }

    public function updateStatusWithAnInvalidStatusReturnsUnprocessableAndLeavesUserUnchanged(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $staff = $this->makeAdmin($I, 'status-invalid@example.test');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $token = $this->grabUserRowToken($I, 'admin', $staff->getId(), 'status');
        $I->sendAjaxPostRequest('/admin/user/status/admin/' . $staff->getId(), ['status' => 'Deleted', '_token' => $token]);
        $I->seeResponseCodeIs(422);
        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['ok']);

        $I->seeInRepository(AdminUser::class, ['id' => $staff->getId(), 'status' => 'Active']);
    }

    public function updateStatusForAnUnknownUserReturnsNotFound(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $staff = $this->makeAdmin($I, 'status-vanishing@example.test');
        $id = $staff->getId();

        // Tokens are bound to the row id, so reaching the not-found branch means holding a real
        // token for an id that no longer exists.
        $I->haveHttpHeader('Host', 'admin.localhost');
        $token = $this->grabUserRowToken($I, 'admin', $id, 'status');
        $this->dropAdmin($I, $id);

        $I->sendAjaxPostRequest('/admin/user/status/admin/' . $id, ['status' => 'Active', '_token' => $token]);
        $I->seeResponseCodeIs(404);
    }

    public function deletingAUserRemovesThem(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $staff = $this->makeAdmin($I, 'delete-me@example.test');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $token = $this->grabUserRowToken($I, 'admin', $staff->getId(), 'delete');
        $I->sendAjaxPostRequest('/admin/user/delete/admin/' . $staff->getId(), ['_token' => $token]);
        $I->seeResponseCodeIsSuccessful();
        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['ok']);

        $I->dontSeeInRepository(AdminUser::class, ['id' => $staff->getId()]);
    }

    public function deletingYourOwnAccountIsBlocked(FunctionalTester $I): void
    {
        $admin = $this->loginAsAdmin($I, 'self-delete@example.test');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $token = $this->grabUserRowToken($I, 'admin', $admin->getId(), 'delete');
        $I->sendAjaxPostRequest('/admin/user/delete/admin/' . $admin->getId(), ['_token' => $token]);
        $I->seeResponseCodeIs(409);
        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['ok']);
        $I->assertStringContainsString('cannot delete your own user account', $response['message']);

        $I->seeInRepository(AdminUser::class, ['id' => $admin->getId()]);
    }

    public function deletingAnUnknownUserReturnsNotFound(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $staff = $this->makeAdmin($I, 'delete-vanishing@example.test');
        $id = $staff->getId();

        $I->haveHttpHeader('Host', 'admin.localhost');
        $token = $this->grabUserRowToken($I, 'admin', $id, 'delete');
        $this->dropAdmin($I, $id);

        $I->sendAjaxPostRequest('/admin/user/delete/admin/' . $id, ['_token' => $token]);
        $I->seeResponseCodeIs(404);
        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['ok']);
    }

    /** Issue: deleting a staff account another table still points at (here, a recorded invoice
     *  payment) must refuse with a 409 the admin can read, never a bare 500 from an uncaught
     *  ForeignKeyConstraintViolationException. */
    public function deletingAUserStillOnARecordedPaymentReturnsAConflictAndKeepsEverything(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $staff = $this->makeAdmin($I, 'delete-referenced@example.test');
        $company = $this->makeCompany($I, 'Delete Referenced Payment Co');
        $I->haveInRepository((new InvoicePayment())->setCompany($company)->setUser($staff)->setMethod('Bank Transfer')->setAmount('10.00'));

        // A sibling with no payment recorded against them, so the refused delete can be proven not
        // to poison the next request.
        $sibling = $this->makeAdmin($I, 'delete-referenced-sibling@example.test');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $token = $this->grabUserRowToken($I, 'admin', $staff->getId(), 'delete');

        $I->sendAjaxPostRequest('/admin/user/delete/admin/' . $staff->getId(), ['_token' => $token]);
        $I->seeResponseCodeIs(409);
        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['ok']);
        $I->assertStringContainsString($staff->getEmail(), $response['message']);

        // The FK exception closes the entity manager; get a fresh one before reading the database
        // again.
        $I->grabService('doctrine')->resetManager();

        $I->seeInRepository(AdminUser::class, ['id' => $staff->getId()]);
        $I->seeInRepository(InvoicePayment::class, ['user' => $staff->getId()]);

        // The sibling is untouched and can still be deleted.
        $I->seeInRepository(AdminUser::class, ['id' => $sibling->getId()]);
        $siblingToken = $this->grabUserRowToken($I, 'admin', $sibling->getId(), 'delete');
        $I->sendAjaxPostRequest('/admin/user/delete/admin/' . $sibling->getId(), ['_token' => $siblingToken]);
        $I->seeResponseCodeIsSuccessful();
        $I->dontSeeInRepository(AdminUser::class, ['id' => $sibling->getId()]);

        // Repeating the original delete is still a 409, not a 500 the second time either.
        $I->sendAjaxPostRequest('/admin/user/delete/admin/' . $staff->getId(), ['_token' => $token]);
        $I->seeResponseCodeIs(409);
        $I->seeInRepository(AdminUser::class, ['id' => $staff->getId()]);
    }

    public function anAdminCannotEditOrDeleteASuperAdminUser(FunctionalTester $I): void
    {
        // Default loginAsAdmin() actor has no explicit roles, so AdminUser::getRoles() falls
        // back to plain "Admin" — the role that's blocked from touching Super Admin targets.
        $this->loginAsAdmin($I);
        $superAdmin = $this->makeAdmin($I, 'super-admin-target@example.test', ['ROLE_SUPER_ADMIN']);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/user/staff/update/' . $superAdmin->getId());
        $I->seeCurrentUrlEquals('/admin/user/staff');
        $I->see('Admins cannot edit super admin users.');

        $I->sendAjaxPostRequest('/admin/user/delete/admin/' . $superAdmin->getId(), [
            '_token' => $this->grabUserRowToken($I, 'admin', $superAdmin->getId(), 'delete'),
        ]);
        $I->seeResponseCodeIs(403);
        $deleteResponse = json_decode($I->grabPageSource(), true);
        $I->assertFalse($deleteResponse['ok']);
        $I->assertStringContainsString('Admins cannot edit super admin users.', $deleteResponse['message']);

        $I->seeInRepository(AdminUser::class, ['id' => $superAdmin->getId()]);
    }

    /**
     * There was no guard against leaving the system with zero active Super Admins: an actor
     * allowed to manage a Super Admin target (Super Admin or Tech Support — plain Admin is
     * already blocked above) could freely demote/deactivate/delete the only one, exactly what
     * happened in production to ken@restobox.com. These four cover role change, deactivation,
     * deletion, and the negative case (a second active Super Admin makes all three succeed).
     * The actor is Tech Support rather than the target's own Super Admin session so the "cannot
     * delete/act on yourself" guards don't get conflated with this one.
     */
    public function aForgedTokenIsRefusedOnEveryUserWriteEndpoint(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $staff = $this->makeAdmin($I, 'forged-token-target@example.test');
        $id = $staff->getId();

        $I->haveHttpHeader('Host', 'admin.localhost');

        foreach (['status/admin', 'delete/admin', 'reset-password/admin', 'resend-invite/admin'] as $action) {
            $I->sendAjaxPostRequest('/admin/user/' . $action . '/' . $id, [
                '_token' => 'forged',
                'status' => 'Inactive',
            ]);
            $I->seeResponseCodeIs(403);
        }

        // Nothing above landed: the account is still here, still Active, still without a reset token.
        $I->seeInRepository(AdminUser::class, ['id' => $id, 'status' => 'Active']);
        $I->assertNull($I->grabEntityFromRepository(AdminUser::class, ['id' => $id])->getResetToken());
    }

    public function aForgedTokenIsRefusedOnTheUserForms(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $staff = $this->makeAdmin($I, 'forged-form-target@example.test');
        $staff->setFirstName('Original');
        $I->haveInRepository($staff);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/user/staff/create');
        $I->sendAjaxPostRequest('/admin/user/staff/create', [
            '_token' => 'forged',
            'email' => 'forged-create@example.test',
            'first_name' => 'Forged',
            'last_name' => 'Create',
            'status' => 'Active',
            'role' => 'Admin',
        ]);
        $I->dontSeeInRepository(AdminUser::class, ['email' => 'forged-create@example.test']);

        $I->amOnPage('/admin/user/staff/update/' . $staff->getId());
        $I->sendAjaxPostRequest('/admin/user/staff/update/' . $staff->getId(), [
            '_token' => 'forged',
            'first_name' => 'Renamed',
            'last_name' => 'Staff',
            'status' => 'Inactive',
            'role' => 'Admin',
        ]);
        $I->seeInRepository(AdminUser::class, ['id' => $staff->getId(), 'firstName' => 'Original', 'status' => 'Active']);
    }

    public function demotingTheOnlyActiveSuperAdminIsBlocked(FunctionalTester $I): void
    {
        $techSupport = $this->makeAdmin($I, 'tech-support-guard@example.test', ['ROLE_TECH_SUPPORT']);
        $I->amLoggedInAs($techSupport, 'admin');
        $soleSuperAdmin = $this->makeAdmin($I, 'sole-super-admin-demote@example.test', ['ROLE_SUPER_ADMIN']);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $token = $this->grabUserFormToken($I, '/admin/user/staff/update/' . $soleSuperAdmin->getId());
        $I->sendAjaxPostRequest('/admin/user/staff/update/' . $soleSuperAdmin->getId(), [
            '_token' => $token,
            'first_name' => 'Sole',
            'last_name' => 'Admin',
            'status' => 'Active',
            'role' => 'Admin',
        ]);
        $I->seeResponseCodeIs(422);
        $I->assertStringContainsString('the only active Super Admin', $I->grabPageSource());

        $I->seeInRepository(AdminUser::class, ['id' => $soleSuperAdmin->getId(), 'roles' => '["ROLE_SUPER_ADMIN"]']);
    }

    public function deactivatingTheOnlyActiveSuperAdminIsBlocked(FunctionalTester $I): void
    {
        $techSupport = $this->makeAdmin($I, 'tech-support-guard-2@example.test', ['ROLE_TECH_SUPPORT']);
        $I->amLoggedInAs($techSupport, 'admin');
        $soleSuperAdmin = $this->makeAdmin($I, 'sole-super-admin-deactivate@example.test', ['ROLE_SUPER_ADMIN']);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $token = $this->grabUserRowToken($I, 'admin', $soleSuperAdmin->getId(), 'status');
        $I->sendAjaxPostRequest('/admin/user/status/admin/' . $soleSuperAdmin->getId(), ['status' => 'Inactive', '_token' => $token]);
        $I->seeResponseCodeIs(409);
        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['ok']);
        $I->assertStringContainsString('the only active Super Admin', $response['message']);

        $I->seeInRepository(AdminUser::class, ['id' => $soleSuperAdmin->getId(), 'status' => 'Active']);
    }

    public function deletingTheOnlyActiveSuperAdminIsBlocked(FunctionalTester $I): void
    {
        $techSupport = $this->makeAdmin($I, 'tech-support-guard-3@example.test', ['ROLE_TECH_SUPPORT']);
        $I->amLoggedInAs($techSupport, 'admin');
        $soleSuperAdmin = $this->makeAdmin($I, 'sole-super-admin-delete@example.test', ['ROLE_SUPER_ADMIN']);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $token = $this->grabUserRowToken($I, 'admin', $soleSuperAdmin->getId(), 'delete');
        $I->sendAjaxPostRequest('/admin/user/delete/admin/' . $soleSuperAdmin->getId(), ['_token' => $token]);
        $I->seeResponseCodeIs(409);
        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['ok']);
        $I->assertStringContainsString('the only active Super Admin', $response['message']);

        $I->seeInRepository(AdminUser::class, ['id' => $soleSuperAdmin->getId()]);
    }

    public function demotingASuperAdminSucceedsWhenAnotherActiveSuperAdminExists(FunctionalTester $I): void
    {
        $techSupport = $this->makeAdmin($I, 'tech-support-guard-4@example.test', ['ROLE_TECH_SUPPORT']);
        $I->amLoggedInAs($techSupport, 'admin');
        $this->makeAdmin($I, 'backup-super-admin@example.test', ['ROLE_SUPER_ADMIN']);
        $target = $this->makeAdmin($I, 'demotable-super-admin@example.test', ['ROLE_SUPER_ADMIN']);

        $I->haveHttpHeader('Host', 'admin.localhost');
        $token = $this->grabUserFormToken($I, '/admin/user/staff/update/' . $target->getId());
        $I->sendAjaxPostRequest('/admin/user/staff/update/' . $target->getId(), [
            '_token' => $token,
            'first_name' => 'Demotable',
            'last_name' => 'Admin',
            'status' => 'Active',
            'role' => 'Admin',
        ]);
        $I->seeCurrentUrlEquals('/admin/user/staff');

        $I->seeInRepository(AdminUser::class, ['id' => $target->getId(), 'roles' => '["ROLE_ADMIN"]']);
    }
}
