<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CustomerUser;
use App\Entity\EmailLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The admin-sets-a-password screen, for users who will not act on the emailed reset link.
 *
 * Additive: admin_user_reset_password (the email-a-link flow) is untouched and still tested by
 * AdminUserCest. The distinguishing property here is that the admin learns the password, which makes
 * the interesting assertions the negative ones — who may not do it, and where the plaintext must not
 * end up.
 */
final class AdminSetUserPasswordCest
{
    private const APPLY = '/admin/user/set-password';

    private function pageFor(string $type, int $id): string
    {
        return '/admin/user/set-password/' . $type . '/' . $id;
    }

    private function admin(FunctionalTester $I, string $email, array $roles = []): AdminUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $user = (new AdminUser())->setEmail($email);
        $user->setRoles($roles);
        $user->setPassword($hasher->hashPassword($user, 'original-password-123'));
        $I->haveInRepository($user);

        return $user;
    }

    private function loginAs(FunctionalTester $I, AdminUser $user): void
    {
        $I->amLoggedInAs($user, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function customer(FunctionalTester $I, string $email): CustomerUser
    {
        $company = (new Company())->setName('SetPw Co ' . uniqid());
        $I->haveInRepository($company);

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $user = (new CustomerUser())->setEmail($email);
        $user->setCompany($company);
        $user->setPassword($hasher->hashPassword($user, 'original-password-123'));
        $I->haveInRepository($user);

        return $user;
    }

    /**
     * Re-reads a user from the database after a request has changed it.
     *
     * Not $em->refresh($user): the object haveInRepository() handed back is not managed by the entity
     * manager the request ran against, so refresh() throws "entity is not managed".
     */
    private function reload(FunctionalTester $I, string $class, string $email): AdminUser|CustomerUser
    {
        return $I->grabEntityFromRepository($class, ['email' => $email]);
    }

    private function token(FunctionalTester $I): string
    {
        return $I->grabAttributeFrom('input[name="_token"]', 'value');
    }

    /** @param array<string, string> $params */
    private function apply(FunctionalTester $I, array $params): void
    {
        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->sendAjaxPostRequest(self::APPLY, $params + ['_token' => $I->csrfToken()]);
    }

    /**
     * Reachable from where the admin already is, and about exactly one account.
     *
     * There is no finder on the page and no sidebar entry: you open it from the row menu of the user
     * you were already looking at, next to the "PW Reset Link" it complements.
     */
    /**
     * A valid token, taken from a page this actor is allowed to open.
     *
     * Needed because two of the tests below post for a target whose own page the actor may not open —
     * so the token cannot come from there.
     */
    private function tokenFromAnAllowedPage(FunctionalTester $I): string
    {
        $spare = $this->admin($I, 'setpw-token-source-' . uniqid() . '@example.test');
        $I->amOnPage($this->pageFor('admin', (int) $spare->getId()));

        return $this->token($I);
    }

    public function theRowMenuOpensThePageForThatOneUser(FunctionalTester $I): void
    {
        $this->loginAs($I, $this->admin($I, 'setpw-rowaction-actor@example.test'));
        $target = $this->admin($I, 'setpw-rowaction-target@example.test');

        $I->amOnPage('/admin/user/staff');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('a[href="' . $this->pageFor('admin', (int) $target->getId()) . '"]');

        $I->amOnPage($this->pageFor('admin', (int) $target->getId()));
        $I->seeResponseCodeIsSuccessful();
        $I->see($target->getEmail());
        $I->seeElement('input[name="password"]');
    }

    public function theCustomerListRowMenuHasItToo(FunctionalTester $I): void
    {
        $this->loginAs($I, $this->admin($I, 'setpw-rowcust-actor@example.test'));
        $target = $this->customer($I, 'setpw-rowcust-target@example.test');

        $I->amOnPage('/admin/user/customer');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('a[href="' . $this->pageFor('customer', (int) $target->getId()) . '"]');
    }

    public function thePageShowsOnlyTheChosenUser(FunctionalTester $I): void
    {
        $this->loginAs($I, $this->admin($I, 'setpw-solo-actor@example.test'));
        $target = $this->admin($I, 'setpw-solo-target@example.test');
        $other = $this->admin($I, 'setpw-solo-other@example.test');

        $I->amOnPage($this->pageFor('admin', (int) $target->getId()));
        $I->see($target->getEmail());

        // No search box, no list of other accounts to reset.
        $I->dontSee($other->getEmail());
        $I->dontSeeElement('input[name="q"]');
    }

    public function aTypedPasswordIsSetAndShownOnce(FunctionalTester $I): void
    {
        $this->loginAs($I, $this->admin($I, 'setpw-typed-actor@example.test'));
        $target = $this->admin($I, 'setpw-typed-target@example.test');

        $I->amOnPage($this->pageFor('admin', (int) $target->getId()));
        $this->apply($I, [
            '_token' => $this->token($I),
            'type' => 'admin',
            'id' => (string) $target->getId(),
            'mode' => 'typed',
            'password' => 'dictated-by-support-42',
        ]);

        // The redirect after the POST is followed, and that is where the password is displayed.
        $I->see('dictated-by-support-42');
        $I->see('setpw-typed-target@example.test');

        // Shown once: a fresh visit must not put it back on screen.
        $I->amOnPage($this->pageFor('admin', (int) $target->getId()));
        $I->dontSee('dictated-by-support-42');
    }

    public function theNewPasswordActuallyAuthenticates(FunctionalTester $I): void
    {
        $this->loginAs($I, $this->admin($I, 'setpw-auth-actor@example.test'));
        $target = $this->admin($I, 'setpw-auth-target@example.test');

        $I->amOnPage($this->pageFor('admin', (int) $target->getId()));
        $this->apply($I, [
            '_token' => $this->token($I),
            'type' => 'admin',
            'id' => (string) $target->getId(),
            'mode' => 'typed',
            'password' => 'brand-new-password-9',
        ]);

        // The point of the whole screen: the credential works afterwards.
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $target = $this->reload($I, $target instanceof AdminUser ? AdminUser::class : CustomerUser::class, $target->getEmail());

        $I->assertTrue($hasher->isPasswordValid($target, 'brand-new-password-9'));
        $I->assertFalse($hasher->isPasswordValid($target, 'original-password-123'));
    }

    public function aGeneratedPasswordIsReadableAndWorks(FunctionalTester $I): void
    {
        $this->loginAs($I, $this->admin($I, 'setpw-gen-actor@example.test'));
        $target = $this->customer($I, 'setpw-gen-target@example.test');

        $I->amOnPage($this->pageFor('customer', (int) $target->getId()));
        $this->apply($I, [
            '_token' => $this->token($I),
            'type' => 'customer',
            'id' => (string) $target->getId(),
            'mode' => 'generate',
            'password' => '',
        ]);

        $I->see('setpw-gen-target@example.test');

        // Read out of the page source rather than via a selector: this asserts an exact format, so
        // the extraction should be exact too.
        preg_match('/id="issued-password">([^<]+)</', $I->grabPageSource(), $matches);
        $shown = $matches[1] ?? '';
        $I->assertMatchesRegularExpression(
            '/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{4}(-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{4}){3}$/',
            $shown,
            'Generated passwords are dictated aloud, so the alphabet excludes look-alike characters.',
        );

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $target = $this->reload($I, $target instanceof AdminUser ? AdminUser::class : CustomerUser::class, $target->getEmail());
        $I->assertTrue($hasher->isPasswordValid($target, $shown));
    }

    public function settingAPasswordCancelsAnyOutstandingResetLink(FunctionalTester $I): void
    {
        $this->loginAs($I, $this->admin($I, 'setpw-token-actor@example.test'));
        $target = $this->admin($I, 'setpw-token-target@example.test');

        $entityManager = $I->grabService(EntityManagerInterface::class);
        $target->setResetToken('a-previously-emailed-token-hash');
        $target->setResetTokenExpiresAt(new \DateTimeImmutable('+1 hour'));
        $entityManager->flush();

        $I->amOnPage($this->pageFor('admin', (int) $target->getId()));
        $this->apply($I, [
            '_token' => $this->token($I),
            'type' => 'admin',
            'id' => (string) $target->getId(),
            'mode' => 'typed',
            'password' => 'supersedes-the-link-7',
        ]);

        $target = $this->reload($I, AdminUser::class, 'setpw-token-target@example.test');

        // Otherwise a stale inbox link could later override the password the admin just read out.
        $I->assertNull($target->getResetToken());
        $I->assertNull($target->getResetTokenExpiresAt());
    }

    public function anAdminCannotSetASuperAdminsPassword(FunctionalTester $I): void
    {
        $this->loginAs($I, $this->admin($I, 'setpw-plain-admin@example.test'));
        $superAdmin = $this->admin($I, 'setpw-super@example.test', ['ROLE_SUPER_ADMIN']);

        // The guard runs on the GET as well, so an Admin cannot even open the page for a Super Admin.
        $I->amOnPage($this->pageFor('admin', (int) $superAdmin->getId()));
        $I->dontSeeElement('input[name="password"]');

        // And posting directly, with a token lifted from a page they may open, still changes nothing.
        $this->apply($I, [
            '_token' => $this->tokenFromAnAllowedPage($I),
            'type' => 'admin',
            'id' => (string) $superAdmin->getId(),
            'mode' => 'typed',
            'password' => 'privilege-escalation-1',
        ]);

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $superAdmin = $this->reload($I, AdminUser::class, 'setpw-super@example.test');
        $I->assertFalse($hasher->isPasswordValid($superAdmin, 'privilege-escalation-1'));
    }

    public function anAdminCannotSetTheirOwnPasswordHere(FunctionalTester $I): void
    {
        $actor = $this->admin($I, 'setpw-self@example.test');
        $this->loginAs($I, $actor);

        // Your own password belongs on the profile page, where the change re-authenticates the session.
        $I->amOnPage($this->pageFor('admin', (int) $actor->getId()));
        $I->dontSeeElement('input[name="password"]');

        $this->apply($I, [
            '_token' => $this->tokenFromAnAllowedPage($I),
            'type' => 'admin',
            'id' => (string) $actor->getId(),
            'mode' => 'typed',
            'password' => 'changing-my-own-8',
        ]);

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $actor = $this->reload($I, AdminUser::class, 'setpw-self@example.test');
        $I->assertFalse($hasher->isPasswordValid($actor, 'changing-my-own-8'));
    }

    public function aShortTypedPasswordIsRejected(FunctionalTester $I): void
    {
        $this->loginAs($I, $this->admin($I, 'setpw-short-actor@example.test'));
        $target = $this->admin($I, 'setpw-short-target@example.test');

        $I->amOnPage($this->pageFor('admin', (int) $target->getId()));
        $this->apply($I, [
            '_token' => $this->token($I),
            'type' => 'admin',
            'id' => (string) $target->getId(),
            'mode' => 'typed',
            'password' => 'short',
        ]);

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $target = $this->reload($I, $target instanceof AdminUser ? AdminUser::class : CustomerUser::class, $target->getEmail());
        $I->assertFalse($hasher->isPasswordValid($target, 'short'));
        $I->assertTrue($hasher->isPasswordValid($target, 'original-password-123'));
    }

    public function aPasswordWrappedInSpacesIsRejected(FunctionalTester $I): void
    {
        $this->loginAs($I, $this->admin($I, 'setpw-space-actor@example.test'));
        $target = $this->admin($I, 'setpw-space-target@example.test');

        $I->amOnPage($this->pageFor('admin', (int) $target->getId()));
        $this->apply($I, [
            '_token' => $this->token($I),
            'type' => 'admin',
            'id' => (string) $target->getId(),
            'mode' => 'typed',
            'password' => '  padded-password  ',
        ]);

        // Invisible when read aloud, which is exactly how this password gets delivered.
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $target = $this->reload($I, $target instanceof AdminUser ? AdminUser::class : CustomerUser::class, $target->getEmail());
        $I->assertFalse($hasher->isPasswordValid($target, '  padded-password  '));
    }

    public function aTokenlessRequestChangesNothing(FunctionalTester $I): void
    {
        $this->loginAs($I, $this->admin($I, 'setpw-csrf-actor@example.test'));
        $target = $this->admin($I, 'setpw-csrf-target@example.test');

        $this->apply($I, [
            '_token' => 'not-a-real-token',
            'type' => 'admin',
            'id' => (string) $target->getId(),
            'mode' => 'typed',
            'password' => 'forged-request-11',
        ]);

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $target = $this->reload($I, $target instanceof AdminUser ? AdminUser::class : CustomerUser::class, $target->getEmail());
        $I->assertFalse($hasher->isPasswordValid($target, 'forged-request-11'));
    }

    /**
     * The plaintext must exist only on the one screen that displays it. email_log was already the
     * subject of a fix to redact reset tokens; mailing or logging a plaintext password would be the
     * same leak in a worse form.
     */
    public function thePlaintextIsNeverEmailedOrLogged(FunctionalTester $I): void
    {
        $this->loginAs($I, $this->admin($I, 'setpw-leak-actor@example.test'));
        $target = $this->customer($I, 'setpw-leak-target@example.test');

        $I->amOnPage($this->pageFor('customer', (int) $target->getId()));
        $this->apply($I, [
            '_token' => $this->token($I),
            'type' => 'customer',
            'id' => (string) $target->getId(),
            'mode' => 'typed',
            'password' => 'must-not-be-logged-77',
        ]);

        $I->dontSeeEmailIsSent();

        $entityManager = $I->grabService(EntityManagerInterface::class);
        foreach ($entityManager->getRepository(EmailLog::class)->findAll() as $log) {
            $I->assertStringNotContainsString('must-not-be-logged-77', (string) $log->getBody());
            $I->assertStringNotContainsString('must-not-be-logged-77', (string) $log->getSubject());
        }

        $audit = $entityManager->getConnection()->fetchAllAssociative(
            "SELECT summary, data_before, data_after FROM audit_log WHERE action = 'password_set_by_admin'",
        );

        $I->assertNotEmpty($audit, 'The reset itself must be recorded — just not the password.');
        foreach ($audit as $row) {
            $I->assertStringNotContainsString('must-not-be-logged-77', implode(' ', array_map('strval', $row)));
        }
        $I->assertStringContainsString('setpw-leak-target@example.test', (string) $audit[0]['summary']);
    }
}
