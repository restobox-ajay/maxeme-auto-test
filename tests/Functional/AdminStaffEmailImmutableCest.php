<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CustomerUser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * A staff email is the login identifier for the admin firewall, so it cannot be changed on the edit
 * screen — including by a request that never came from that screen.
 *
 * The form used to render the email as a disabled input paired with a hidden field. A disabled input
 * submits nothing, which is why the hidden one existed; and that hidden value was fully
 * client-controlled, while applyUserRequest() wrote it back unconditionally. Editing it repointed the
 * account: the original owner could no longer sign in, and password-reset mail went to the new
 * address. See issue #89.
 *
 * The field is gone from the form now, but the assertion that matters is the server one — a form that
 * omits a field is not a control.
 */
final class AdminStaffEmailImmutableCest
{
    private function loginAsAdmin(FunctionalTester $I): AdminUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('staff-email-actor@example.test');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        return $admin;
    }

    /**
     * These POSTs carry no CSRF token because these routes currently accept none — the admin-wide
     * enforcement is still open in PR #83. When that lands it updates the Cests that post to admin
     * routes, and this one will need a token like the rest.
     */
    private function staff(FunctionalTester $I, string $email): AdminUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $user = (new AdminUser())->setEmail($email);
        $user->setFirstName('Original');
        $user->setPassword($hasher->hashPassword($user, 'test-password-123'));
        $I->haveInRepository($user);

        return $user;
    }

    public function theStaffEditFormDoesNotCarryTheEmailAtAll(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $target = $this->staff($I, 'staff-email-form@example.test');

        $I->amOnPage('/admin/user/staff/update/' . $target->getId());
        $I->seeResponseCodeIsSuccessful();

        // Shown, but as text — not as a control the server will not honour.
        $I->see('staff-email-form@example.test');
        $I->dontSeeElement('input[name="email"]');
    }

    public function aTamperedEmailIsIgnoredAndTheAccountKeepsItsAddress(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $target = $this->staff($I, 'staff-email-keep@example.test');

        $I->amOnPage('/admin/user/staff/update/' . $target->getId());
        $I->sendAjaxPostRequest('/admin/user/staff/update/' . $target->getId(), [
            '_token' => $I->grabAttributeFrom('form.js-admin-user-form input[name="_token"]', 'value'),
            'email' => 'attacker-owned@evil.test',
            'first_name' => 'Renamed',
            'status' => 'Active',
        ]);

        $reloaded = $I->grabEntityFromRepository(AdminUser::class, ['id' => $target->getId()]);

        $I->assertSame('staff-email-keep@example.test', $reloaded->getEmail(), 'the login identifier must survive a tampered POST');
        $I->dontSeeInRepository(AdminUser::class, ['email' => 'attacker-owned@evil.test']);

        // The rest of the form still applies, so this is an ignored field rather than a rejected
        // request — an admin editing a name should not be stopped by it.
        $I->assertSame('Renamed', $reloaded->getFirstName());
    }

    public function anOmittedEmailDoesNotTripValidation(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $target = $this->staff($I, 'staff-email-omitted@example.test');

        $I->amOnPage('/admin/user/staff/update/' . $target->getId());
        // What the real form now posts: no email key whatsoever. Validation reads the address from the
        // request, so without the pinning it would fail with "User email is required".
        $I->sendAjaxPostRequest('/admin/user/staff/update/' . $target->getId(), [
            '_token' => $I->grabAttributeFrom('form.js-admin-user-form input[name="_token"]', 'value'),
            'first_name' => 'StillSaves',
            'status' => 'Active',
        ]);

        $reloaded = $I->grabEntityFromRepository(AdminUser::class, ['id' => $target->getId()]);
        $I->assertSame('staff-email-omitted@example.test', $reloaded->getEmail());
        $I->assertSame('StillSaves', $reloaded->getFirstName());
    }

    /**
     * Ignored outright, not validated-then-overwritten.
     *
     * The screen does not offer the field, so an address in the request is not required, not
     * format-checked and not uniqueness-checked. Garbage in it must not produce "Email must be a valid
     * email address" about a control the admin never saw.
     */
    public function aMalformedEmailIsIgnoredRatherThanRejected(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $target = $this->staff($I, 'staff-email-garbage@example.test');

        $I->amOnPage('/admin/user/staff/update/' . $target->getId());
        $I->sendAjaxPostRequest('/admin/user/staff/update/' . $target->getId(), [
            '_token' => $I->grabAttributeFrom('form.js-admin-user-form input[name="_token"]', 'value'),
            'email' => 'not-an-email-at-all',
            'first_name' => 'SavedAnyway',
            'status' => 'Active',
        ]);

        $I->dontSee('Email must be a valid email address.');
        $I->dontSee('User email is required.');

        $reloaded = $I->grabEntityFromRepository(AdminUser::class, ['id' => $target->getId()]);
        $I->assertSame('staff-email-garbage@example.test', $reloaded->getEmail());
        $I->assertSame('SavedAnyway', $reloaded->getFirstName(), 'the edit still applies');
    }

    /**
     * An address already belonging to another staff account must not block the edit either — it is a
     * uniqueness check on a field this screen does not submit.
     */
    public function anotherUsersEmailInTheRequestIsIgnored(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);
        $other = $this->staff($I, 'staff-email-taken@example.test');
        $target = $this->staff($I, 'staff-email-mine@example.test');

        $I->amOnPage('/admin/user/staff/update/' . $target->getId());
        $I->sendAjaxPostRequest('/admin/user/staff/update/' . $target->getId(), [
            '_token' => $I->grabAttributeFrom('form.js-admin-user-form input[name="_token"]', 'value'),
            'email' => $other->getEmail(),
            'first_name' => 'Unblocked',
            'status' => 'Active',
        ]);

        $I->dontSee('is already used by an admin user');

        $reloaded = $I->grabEntityFromRepository(AdminUser::class, ['id' => $target->getId()]);
        $I->assertSame('staff-email-mine@example.test', $reloaded->getEmail());
        $I->assertSame('Unblocked', $reloaded->getFirstName());
    }

    /**
     * The customer side is deliberately unaffected: changing a customer's email is a supported action
     * there, and that path notifies the previous address.
     */
    public function aCustomerEmailCanStillBeChanged(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $company = (new Company())->setName('Email Co ' . uniqid());
        $I->haveInRepository($company);

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $customer = (new CustomerUser())->setEmail('customer-email-before@example.test');
        $customer->setCompany($company);
        $customer->setPassword($hasher->hashPassword($customer, 'test-password-123'));
        $I->haveInRepository($customer);

        $I->amOnPage('/admin/user/customer/update/' . $customer->getId());
        $I->seeElement('input[name="email"]');
        $I->sendAjaxPostRequest('/admin/user/customer/update/' . $customer->getId(), [
            '_token' => $I->grabAttributeFrom('form.js-admin-user-form input[name="_token"]', 'value'),
            'email' => 'customer-email-after@example.test',
            'status' => 'Active',
            'company' => (string) $company->getId(),
            'notify_email_change' => 'no',
        ]);

        $reloaded = $I->grabEntityFromRepository(CustomerUser::class, ['id' => $customer->getId()]);
        $I->assertSame('customer-email-after@example.test', $reloaded->getEmail());
    }
}
