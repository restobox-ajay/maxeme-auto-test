<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Controller\Admin\DatabaseConsoleController;
use App\Entity\AdminUser;
use App\Entity\AppSetting;
use App\Security\ConsoleCookie;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The database console: Tech Support only, off by default, and every switch-on leaves a trail.
 *
 * The gateway that actually serves phpLiteAdmin runs outside the kernel, so it cannot be exercised
 * here. What these cover is the half that mints the capability — the role gate, the kill-switch, and
 * the db_console_session row (random token, owning admin, expiry, opening IP) the gateway later reads
 * to authorise a request. See issue #103.
 */
final class AdminDatabaseConsoleCest
{
    /**
     * Limiter state is a filesystem cache pool that outlives both the test transaction and the run,
     * and the db_console limiter is keyed by admin id — every admin created here is the first row in
     * a rolled-back table, so they all land on the same id and share one 10-per-5-minutes budget
     * across the whole file and across consecutive runs. Cleared per test for the same reason
     * CustomerRegisterRaceAndRateLimitCest and AdminAuthCest already do.
     *
     * AppSettings caches its rows, and that cache is not part of the transaction either, so a value
     * a test writes would otherwise be visible to the next one after the row itself is gone.
     */
    public function _before(FunctionalTester $I): void
    {
        $I->grabService('cache.rate_limiter')->clear();
        $I->grabService(AppSettings::class)->clearCache();
    }

    public function _after(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    private function admin(FunctionalTester $I, string $email, array $roles): AdminUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $user = (new AdminUser())->setEmail($email);
        $user->setRoles($roles);
        $user->setPassword($hasher->hashPassword($user, 'test-password-123'));
        $I->haveInRepository($user);

        return $user;
    }

    private function actAs(FunctionalTester $I, AdminUser $user): void
    {
        $I->amLoggedInAs($user, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    private function enabledUntil(FunctionalTester $I): int
    {
        return (int) $I->grabService(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT setting_value FROM app_setting WHERE setting_key = ?', [AppSetting::DB_CONSOLE_ENABLED_UNTIL]);
    }

    // ---- who may reach it ------------------------------------------------------------------

    public function aSuperAdminCannotReachTheConsole(FunctionalTester $I): void
    {
        // Tech Support and Super Admin have identical permissions by hierarchy, except this one.
        $this->actAs($I, $this->admin($I, 'dbc-super@example.test', ['ROLE_SUPER_ADMIN']));

        $I->amOnPage('/admin/db');
        $I->seeResponseCodeIs(403);
    }

    public function aPlainAdminCannotReachTheConsole(FunctionalTester $I): void
    {
        $this->actAs($I, $this->admin($I, 'dbc-admin@example.test', ['ROLE_ADMIN']));

        $I->amOnPage('/admin/db');
        $I->seeResponseCodeIs(403);
    }

    public function techSupportSeesTheConsolePage(FunctionalTester $I): void
    {
        $this->actAs($I, $this->admin($I, 'dbc-ts@example.test', ['ROLE_TECH_SUPPORT']));

        $I->amOnPage('/admin/db');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Database Console');
    }

    public function aSuperAdminCannotSwitchItOn(FunctionalTester $I): void
    {
        $this->actAs($I, $this->admin($I, 'dbc-super-enable@example.test', ['ROLE_SUPER_ADMIN']));

        $I->sendAjaxPostRequest('/admin/db/enable', [
            '_token' => $I->csrfToken(),]);
        $I->seeResponseCodeIs(403);
        $I->assertSame(0, $this->enabledUntil($I), 'the kill-switch must not move');
    }

    // ---- the kill-switch -------------------------------------------------------------------

    public function itIsOffUntilSomeoneSwitchesItOn(FunctionalTester $I): void
    {
        $this->actAs($I, $this->admin($I, 'dbc-default@example.test', ['ROLE_TECH_SUPPORT']));

        $I->amOnPage('/admin/db');
        $I->see('Off');
        $I->assertSame(0, $this->enabledUntil($I));

        // Nothing to open while it is off.
        $I->amOnPage('/admin/db/open');
        $I->seeCurrentUrlEquals('/admin/db');
    }

    public function switchingItOnSetsAWindowAndAuditsIt(FunctionalTester $I): void
    {
        $this->actAs($I, $this->admin($I, 'dbc-enable@example.test', ['ROLE_TECH_SUPPORT']));

        $I->amOnPage('/admin/db');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/db/enable', ['_token' => $token]);

        $until = $this->enabledUntil($I);
        $I->assertGreaterThan(time(), $until);
        $I->assertLessThanOrEqual(time() + 31 * 60, $until, 'the window should be the configured 30 minutes, not open-ended');

        $rows = $I->grabService(EntityManagerInterface::class)->getConnection()->fetchAllAssociative(
            'SELECT actor_name, summary, ip_address FROM audit_log WHERE action = ? ORDER BY id DESC LIMIT 1',
            [DatabaseConsoleController::AUDIT_ENABLED],
        );
        $I->assertNotEmpty($rows, 'switching on raw SQL access must be audited');
        $I->assertStringContainsString('dbc-enable@example.test', $rows[0]['summary']);
    }

    /**
     * #351: the "someone opened raw SQL access" alert must go to tech_support_email (the platform
     * operator) rather than support_email (the store owner's own customer-facing inbox) — cc'ing a
     * store owner on "an admin opened a database console" would be confusing at best.
     */
    public function switchingItOnAlertsTechSupportNotCustomerSupport(FunctionalTester $I): void
    {
        $this->setSetting($I, 'tech_support_email', 'platform-ops@example.test');
        $this->setSetting($I, 'support_email', 'help@example.test');

        $this->actAs($I, $this->admin($I, 'dbc-alert@example.test', ['ROLE_TECH_SUPPORT']));

        $I->amOnPage('/admin/db');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');

        $I->stopFollowingRedirects();
        $I->sendAjaxPostRequest('/admin/db/enable', ['_token' => $token]);

        $I->seeEmailIsSent();
        $I->assertEmailAddressContains('To', 'platform-ops@example.test');
        $I->assertEmailAddressNotContains('To', 'help@example.test');
        // The recipient is what #351 was about. The From: stopped being tech_support_email in #474
        // — no contact address is a sender any more — so it is the sending identity here, and in
        // particular not the store's own support inbox.
        $I->assertEmailAddressContains('From', 'no-reply@wholesale.example');
        $I->assertEmailAddressNotContains('From', 'help@example.test');
    }


    public function aTokenlessSwitchOnDoesNothing(FunctionalTester $I): void
    {
        $this->actAs($I, $this->admin($I, 'dbc-csrf@example.test', ['ROLE_TECH_SUPPORT']));

        $I->amOnPage('/admin/db');
        $I->sendAjaxPostRequest('/admin/db/enable', ['_token' => 'not-a-real-token']);

        $I->assertSame(0, $this->enabledUntil($I));
    }

    // ---- opening it ------------------------------------------------------------------------

    public function openingMintsASessionAndRecordsTheIp(FunctionalTester $I): void
    {
        $user = $this->admin($I, 'dbc-open@example.test', ['ROLE_TECH_SUPPORT']);
        $this->actAs($I, $user);

        $I->amOnPage('/admin/db');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/db/enable', ['_token' => $token]);

        $I->stopFollowingRedirects();
        $I->amOnPage('/admin/db/open');
        $I->seeResponseCodeIs(302);
        $I->startFollowingRedirects();

        // The session row is what the gateway authorises against: it must name the opener, carry the
        // opening IP for the binding, and expire within the window (never open-ended).
        $session = $I->grabService(EntityManagerInterface::class)->getConnection()->fetchAssociative(
            'SELECT token_hash, admin_id, ip_address, expires_at FROM db_console_session ORDER BY id DESC LIMIT 1',
        );
        $I->assertNotEmpty($session, 'opening must mint a session credential');
        $I->assertSame((int) $user->getId(), (int) $session['admin_id']);
        $I->assertNotEmpty($session['ip_address'], 'without an IP the gateway cannot bind the session');
        $I->assertGreaterThan(time(), strtotime($session['expires_at']));
        $I->assertLessThanOrEqual(time() + 31 * 60, strtotime($session['expires_at']), 'the session must not be open-ended');

        // The raw token lives only in the cookie; the DB keeps only its SHA-256. The stored hash must
        // match the cookie, and must NOT be the raw token.
        $cookie = $I->grabCookie(ConsoleCookie::COOKIE_NAME, ['path' => '/db-admin.php']);
        $I->assertNotEmpty($cookie);
        $I->assertSame(ConsoleCookie::hashToken($cookie), $session['token_hash'], 'the DB must store the hash, not the token');
        $I->assertNotSame($cookie, $session['token_hash']);

        // The open is still recorded in the permanent audit trail.
        $row = $I->grabService(EntityManagerInterface::class)->getConnection()->fetchAssociative(
            'SELECT actor_id FROM audit_log WHERE action = ? ORDER BY id DESC LIMIT 1',
            [DatabaseConsoleController::AUDIT_OPEN],
        );
        $I->assertNotEmpty($row);
        $I->assertSame((int) $user->getId(), (int) $row['actor_id']);
    }

    public function theTokenIsRandomAndStoredOnlyAsAHash(FunctionalTester $I): void
    {
        $a = ConsoleCookie::generateToken();
        $b = ConsoleCookie::generateToken();

        // 256 bits of randomness: unguessable and never the same twice.
        $I->assertNotSame($a, $b, 'each token must be freshly random');
        $I->assertSame(64, strlen($a), 'token should be 32 random bytes as hex');
        $I->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);

        // The stored form is a stable SHA-256, and reveals nothing about the token.
        $I->assertSame(hash('sha256', $a), ConsoleCookie::hashToken($a));
        $I->assertNotSame($a, ConsoleCookie::hashToken($a));
    }

    // ---- logging out revokes the credential ------------------------------------------------

    public function loggingOutDeletesTheConsoleSession(FunctionalTester $I): void
    {
        $user = $this->admin($I, 'dbc-logout@example.test', ['ROLE_TECH_SUPPORT']);
        $this->actAs($I, $user);

        $I->amOnPage('/admin/db');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->sendAjaxPostRequest('/admin/db/enable', ['_token' => $token]);

        $I->stopFollowingRedirects();
        $I->amOnPage('/admin/db/open');
        $I->startFollowingRedirects();

        $connection = $I->grabService(EntityManagerInterface::class)->getConnection();
        $before = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM db_console_session WHERE admin_id = ?',
            [$user->getId()],
        );
        $I->assertSame(1, $before, 'opening the console must mint a session to revoke');

        // The gateway never reads the Symfony session, so logging out has to delete the row or the
        // token keeps working until it lapses on its own.
        $I->amOnPage('/admin/logout');

        $after = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM db_console_session WHERE admin_id = ?',
            [$user->getId()],
        );
        $I->assertSame(0, $after, 'logging out must revoke the console session');
    }

    // ---- who gets told about it ------------------------------------------------------------

    /**
     * #351 item 5. The alert says someone just took raw SQL access to this installation, so it goes
     * to tech_support_email — the people who run the software — and to nobody else. It used to go to
     * app_email; that and support_email are the store owner's own addresses, and this is confidential
     * to the operator, so neither may ever receive it.
     */
    public function openingTheConsoleAlertsTechSupportNotTheStoresOwnAddresses(FunctionalTester $I): void
    {
        $this->setSetting($I, 'tech_support_email', 'ops@saas-operator.example');
        $this->setSetting($I, 'app_email', 'hello@thestore.example');
        $this->setSetting($I, 'support_email', 'help@thestore.example');

        $this->actAs($I, $this->admin($I, 'dbc-alert@example.test', ['ROLE_TECH_SUPPORT']));

        $I->amOnPage('/admin/db');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        // Without this the mailer collector inspected below belongs to the redirect target, which
        // sends nothing.
        $I->stopFollowingRedirects();
        $I->sendAjaxPostRequest('/admin/db/enable', ['_token' => $token]);

        $I->seeEmailIsSent();
        $sent = $I->grabLastSentEmail();
        $I->assertSame(['ops@saas-operator.example'], array_map(
            static fn ($address): string => $address->getAddress(),
            $sent->getTo(),
        ));
        $I->assertSame('Database console switched on', $sent->getSubject());
        // Who it is *addressed* to is the point of this test, and that is still tech_support_email.
        // The From: is a separate question and stopped being tech_support_email in #474: the sender
        // is now the sending domain's own identity (MAILER_FROM here), so this alert is no longer
        // sent from the address it is sent to. None of the store's own addresses may appear either,
        // which is what #351 item 6 was actually protecting.
        $from = $sent->getFrom()[0]->getAddress();
        $I->assertSame('no-reply@wholesale.example', $from);
        $I->assertNotSame('hello@thestore.example', $from);
        $I->assertNotSame('help@thestore.example', $from);
    }

    /**
     * With tech_support_email unset the alert is not sent at all — it must never fall back to one of
     * the store's own addresses. The contents (which admin, from which IP, at what time) are
     * confidential to whoever operates the software; app_email/sales_email/support_email all belong
     * to the store owner, so a fallback would hand them an internal security notice about their own
     * vendor. The audit row the caller writes stays the durable record.
     */
    public function withoutTechSupportEmailNoAlertReachesTheStoresOwnAddresses(FunctionalTester $I): void
    {
        $this->setSetting($I, 'tech_support_email', null);
        $this->setSetting($I, 'app_email', 'hello@thestore.example');
        $this->setSetting($I, 'sales_email', 'sales@thestore.example');
        $this->setSetting($I, 'support_email', 'help@thestore.example');

        $this->actAs($I, $this->admin($I, 'dbc-alert-fallback@example.test', ['ROLE_TECH_SUPPORT']));

        $I->amOnPage('/admin/db');
        $token = $I->grabAttributeFrom('input[name="_token"]', 'value');
        $I->stopFollowingRedirects();
        $I->sendAjaxPostRequest('/admin/db/enable', ['_token' => $token]);

        $I->dontSeeEmailIsSent();
    }

    /**
     * The settings screen must offer it, and must not let it be deleted like an ad-hoc key.
     *
     * Tech Support, not Super Admin: the row is app_setting.visibility = 'tech_support', so the store
     * owner neither sees nor reaches it — see AdminTechSupportSettingVisibilityCest for that half.
     */
    public function theSettingsScreenListsTechSupportEmailAsAProtectedSetting(FunctionalTester $I): void
    {
        $this->actAs($I, $this->admin($I, 'dbc-settings@example.test', ['ROLE_TECH_SUPPORT']));

        $I->amOnPage('/admin/settings?limit=500');
        $I->seeResponseCodeIsSuccessful();
        $I->see('tech_support_email');
        $I->see('Tech Support Email');

        $id = (int) $I->grabService(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT id FROM app_setting WHERE setting_key = ?', ['tech_support_email']);
        $I->assertGreaterThan(0, $id, 'the settings screen must self-heal the row into existence');
        $I->dontSeeElement('button[data-url="/admin/settings/' . $id . '/delete"]');
    }

    private function setSetting(FunctionalTester $I, string $key, ?string $value): void
    {
        $entityManager = $I->grabService(EntityManagerInterface::class);
        $setting = $entityManager->getRepository(AppSetting::class)->findOneBy(['settingKey' => $key]);
        if (!$setting instanceof AppSetting) {
            $setting = (new AppSetting())->setSettingKey($key)->setName($key);
            $entityManager->persist($setting);
        }
        $setting->setSettingValue($value);
        $entityManager->flush();
        $I->grabService(AppSettings::class)->clearCache();
    }

    // ---- the payload is not directly reachable ---------------------------------------------

    public function phpLiteAdminIsNotInTheDocroot(FunctionalTester $I): void
    {
        $root = \dirname(__DIR__, 2);

        // The only way in is the gateway; a copy under public/ would be a standing door.
        $I->assertFileDoesNotExist($root . '/public/phpliteadmin.php');
        $I->assertFileExists($root . '/tools/phpliteadmin.php');
        $I->assertFileExists($root . '/public/db-admin.php');
    }
}
