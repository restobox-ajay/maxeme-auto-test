<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CustomerUser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * A customer user's role can be changed. The select was rendered permanently disabled with a hidden
 * field posting the current value back, so it looked editable and did nothing. See issue #92.
 */
final class AdminCustomerRoleEditableCest
{
    private function loginAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('cust-role-actor@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function customer(FunctionalTester $I): CustomerUser
    {
        $company = (new Company())->setName('Role Co ' . uniqid());
        $I->haveInRepository($company);

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $user = (new CustomerUser())->setEmail('cust-role-' . uniqid() . '@example.test');
        $user->setCompany($company);
        $user->setRoles(['ROLE_COMPANY_STAFF']);
        $user->setPassword($hasher->hashPassword($user, 'test-password-123'));
        $I->haveInRepository($user);

        return $user;
    }

    public function theRoleSelectIsNoLongerDisabled(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $customer = $this->customer($I);

        $I->amOnPage('/admin/user/customer/update/' . $customer->getId());
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('select[name="role"]');
        $I->dontSeeElement('select[name="role"][disabled]');
    }

    public function anAdminCanPromoteACustomerToOwner(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $customer = $this->customer($I);

        $I->amOnPage('/admin/user/customer/update/' . $customer->getId());
        $I->sendAjaxPostRequest('/admin/user/customer/update/' . $customer->getId(), [
            '_token' => $I->grabAttributeFrom('form.js-admin-user-form input[name="_token"]', 'value'),
            'email' => $customer->getEmail(),
            'status' => 'Active',
            'company' => (string) $customer->getCompany()->getId(),
            'role' => 'Owner',
        ]);

        $reloaded = $I->grabEntityFromRepository(CustomerUser::class, ['id' => $customer->getId()]);
        $I->assertContains('ROLE_COMPANY_OWNER', $reloaded->getRoles());
    }

    public function aStaffRoleCannotBeSmuggledOntoACustomer(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $customer = $this->customer($I);

        // The screen offers Owner and Company Staff; anything else must be rejected, not normalised.
        $I->amOnPage('/admin/user/customer/update/' . $customer->getId());
        $I->sendAjaxPostRequest('/admin/user/customer/update/' . $customer->getId(), [
            '_token' => $I->grabAttributeFrom('form.js-admin-user-form input[name="_token"]', 'value'),
            'email' => $customer->getEmail(),
            'status' => 'Active',
            'company' => (string) $customer->getCompany()->getId(),
            'role' => 'Super Admin',
        ]);

        $reloaded = $I->grabEntityFromRepository(CustomerUser::class, ['id' => $customer->getId()]);
        $I->assertNotContains('ROLE_SUPER_ADMIN', $reloaded->getRoles());
        $I->assertNotContains('ROLE_ADMIN', $reloaded->getRoles());
    }
}
