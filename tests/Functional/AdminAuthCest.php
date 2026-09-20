<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Service\ResetTokenService;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/** Covers Admin/AuthController: the /admin/login already-authenticated guard, the
 *  /admin/password-reset request+change flow, the /admin/setup-account account-setup flow,
 *  and the /admin/profile display-name/phone/password self-service forms. */
final class AdminAuthCest
{
    private function createAdmin(FunctionalTester $I, string $email): AdminUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail($email);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        return $admin;
    }

    /** @return array{0: AdminUser, 1: string} the persisted admin and the raw (unhashed) token */
    private function createAdminWithResetToken(FunctionalTester $I, string $email): array
    {
        $resetTokenService = $I->grabService(ResetTokenService::class);
        $rawToken = $resetTokenService->generate();

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail($email);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $admin->setResetToken($resetTokenService->hash($rawToken));
        $admin->setResetTokenExpiresAt((new \DateTimeImmutable())->modify('+1 hour'));
        $I->haveInRepository($admin);

        return [$admin, $rawToken];
    }

    public function loginPageRendersForAGuest(FunctionalTester $I): void
    {
        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/login');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Sign in to your account');
        $I->seeInField('email', '');
    }

    public function loggedInAdminIsRedirectedFromLoginToDashboard(FunctionalTester $I): void
    {
        $admin = $this->createAdmin($I, 'auth-login-redirect-test@example.test');
        $I->amLoggedInAs($admin, 'admin');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/login');
        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlEquals('/admin');
    }

    public function passwordResetRequestForExistingAdminGeneratesATokenAndFlashesAGenericSuccess(FunctionalTester $I): void
    {
        // The password_reset_request rate limiter's storage lives under var/share/test
        // (outside var/cache/test), so it survives the schema-reset wipe in _bootstrap.php
        // and accumulates across suite runs within its 15-minute window. Clear it here so
        // this test doesn't intermittently fail with "Too many reset requests" depending on
        // how many times the suite has run recently.
        $I->grabService('cache.rate_limiter')->clear();

        $admin = $this->createAdmin($I, 'auth-reset-request-test@example.test');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/password-reset');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/password-reset', [
            '_token' => $token,
            'email' => $admin->getEmail(),
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('If an admin account exists for that email, a password reset link has been sent.');

        /** @var AdminUser $refreshed */
        $refreshed = $I->grabEntityFromRepository(AdminUser::class, ['email' => $admin->getEmail()]);
        $I->assertNotNull($refreshed->getResetToken());
        $I->assertNotNull($refreshed->getResetTokenExpiresAt());
        $I->assertGreaterThan(new \DateTimeImmutable(), $refreshed->getResetTokenExpiresAt());
    }

    public function passwordResetWithAnInvalidTokenShowsAnExpiredLinkMessage(FunctionalTester $I): void
    {
        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/password-reset?token=not-a-real-token');
        $I->seeResponseCodeIsSuccessful();
        $I->see('This reset link is invalid or has expired. Please request a new one.');
        $I->dontSeeElement('input[name="password"]');
    }

    public function passwordResetWithAValidTokenAndMismatchedPasswordsShowsAnInlineError(FunctionalTester $I): void
    {
        [$admin, $rawToken] = $this->createAdminWithResetToken($I, 'auth-reset-mismatch-test@example.test');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/password-reset?token=' . $rawToken);
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/password-reset?token=' . $rawToken, [
            '_token' => $token,
            'password' => 'newpassword1',
            'confirm_password' => 'somethingelse',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Passwords do not match.');

        /** @var AdminUser $refreshed */
        $refreshed = $I->grabEntityFromRepository(AdminUser::class, ['email' => $admin->getEmail()]);
        $I->assertNotNull($refreshed->getResetToken());
    }

    public function passwordResetWithAValidTokenAndValidPasswordUpdatesThePasswordAndRedirectsToLogin(FunctionalTester $I): void
    {
        [$admin, $rawToken] = $this->createAdminWithResetToken($I, 'auth-reset-success-test@example.test');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/password-reset?token=' . $rawToken);
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/password-reset?token=' . $rawToken, [
            '_token' => $token,
            'password' => 'brand-new-password-1',
            'confirm_password' => 'brand-new-password-1',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlEquals('/admin/login');
        $I->see('Password updated successfully. You can now log in.');

        /** @var AdminUser $refreshed */
        $refreshed = $I->grabEntityFromRepository(AdminUser::class, ['email' => $admin->getEmail()]);
        $I->assertNull($refreshed->getResetToken());
        $I->assertNull($refreshed->getResetTokenExpiresAt());

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $I->assertTrue($hasher->isPasswordValid($refreshed, 'brand-new-password-1'));
    }

    public function accountSetupWithABlankTokenRedirectsToPasswordResetWithAnError(FunctionalTester $I): void
    {
        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/setup-account');
        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlEquals('/admin/password-reset');
        $I->see('This setup link is invalid.');
    }

    public function accountSetupWithAValidTokenSetsThePasswordAndRedirectsToLogin(FunctionalTester $I): void
    {
        [$admin, $rawToken] = $this->createAdminWithResetToken($I, 'auth-setup-success-test@example.test');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/setup-account?token=' . $rawToken);
        $I->seeResponseCodeIsSuccessful();
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/setup-account?token=' . $rawToken, [
            '_token' => $token,
            'password' => 'first-login-password-1',
            'confirm_password' => 'first-login-password-1',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->seeCurrentUrlEquals('/admin/login');
        $I->see('Account setup complete. You can now log in.');

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        /** @var AdminUser $refreshed */
        $refreshed = $I->grabEntityFromRepository(AdminUser::class, ['email' => $admin->getEmail()]);
        $I->assertTrue($hasher->isPasswordValid($refreshed, 'first-login-password-1'));
        $I->assertNull($refreshed->getResetToken());
    }

    public function profileShowsCurrentValuesAndSavesDisplayNameAndPhone(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())
            ->setEmail('auth-profile-test@example.test')
            ->setFirstName('Jane')
            ->setLastName('Doe')
            ->setPhoneNumber('555-0100');
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);
        $I->amLoggedInAs($admin, 'admin');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/profile');
        $I->seeResponseCodeIsSuccessful();
        $I->seeInField('display_name', 'Jane Doe');
        $I->seeInField('phone_number', '555-0100');

        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/profile', [
            '_token' => $token,
            'form_type' => 'profile',
            'display_name' => 'John Smith',
            'phone_number' => '555-0200',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Profile updated.');

        /** @var AdminUser $refreshed */
        $refreshed = $I->grabEntityFromRepository(AdminUser::class, ['email' => $admin->getEmail()]);
        $I->assertSame('John', $refreshed->getFirstName());
        $I->assertSame('Smith', $refreshed->getLastName());
        $I->assertSame('555-0200', $refreshed->getPhoneNumber());
    }

    public function profilePasswordChangeWithWrongCurrentPasswordShowsAnInlineErrorAndLeavesPasswordUnchanged(FunctionalTester $I): void
    {
        $admin = $this->createAdmin($I, 'auth-profile-wrongpw-test@example.test');
        $I->amLoggedInAs($admin, 'admin');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/profile');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/profile', [
            '_token' => $token,
            'form_type' => 'password',
            'current_password' => 'totally-wrong-password',
            'new_password' => 'brand-new-password-1',
            'confirm_new_password' => 'brand-new-password-1',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Current password is incorrect.');

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        /** @var AdminUser $refreshed */
        $refreshed = $I->grabEntityFromRepository(AdminUser::class, ['email' => $admin->getEmail()]);
        $I->assertTrue($hasher->isPasswordValid($refreshed, 'test-password-123'));
    }

    public function profilePasswordChangeSuccessUpdatesThePassword(FunctionalTester $I): void
    {
        $admin = $this->createAdmin($I, 'auth-profile-pwok-test@example.test');
        $I->amLoggedInAs($admin, 'admin');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/profile');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->sendAjaxPostRequest('/admin/profile', [
            '_token' => $token,
            'form_type' => 'password',
            'current_password' => 'test-password-123',
            'new_password' => 'a-brand-new-password-1',
            'confirm_new_password' => 'a-brand-new-password-1',
        ]);
        $I->seeResponseCodeIsSuccessful();
        $I->see('Password updated.');

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        /** @var AdminUser $refreshed */
        $refreshed = $I->grabEntityFromRepository(AdminUser::class, ['email' => $admin->getEmail()]);
        $I->assertTrue($hasher->isPasswordValid($refreshed, 'a-brand-new-password-1'));
    }
}
