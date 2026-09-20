<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\AppSetting;
use App\Entity\EmailLog;
use App\Entity\EmailTemplate;
use App\Service\AppSettings;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The duration in the SENT body has to match the real token lifetime — asserted on the email that
 * was actually built, through the database template, not on the file template.
 *
 * This is the gap that let #35 ship. #450 fixed the file templates and #475 made the duration
 * configurable, and both were covered — but the tests asserted against the files, and the file is
 * not what gets sent. UserController renders the file and then replaces the body with the
 * email_template row when one exists, so a stale row silently won and nothing noticed. The row on
 * the dev box still said "1 hour" while an admin-issued link lasted thirty days.
 *
 * So every case here installs a DB row first and then reads the rendered body back out of
 * email_log. Asserting on email_log rather than on the mail transport is deliberate and not a
 * workaround: local delivery is sandboxed by design, so a transport assertion fails on a correct
 * application. email_log stores the body that was built, which is the thing under test.
 *
 * forgot_password is the interesting row because ONE row serves two different lifetimes — the
 * self-service reset (password_reset_expiry_hours, default 1 hour) and the admin-issued one
 * (invite_token_expiry_days, default 30 days). No literal can be right for both, which is why
 * #475 introduced {{ expiry_description }} and why Version20260807210000 rewrites the literal
 * rather than correcting it.
 */
final class AdminResetEmailExpiryCopyFromDbTemplateCest
{
    /** The body as the migration leaves it: the sentence carries the variable, not a number. */
    private const MIGRATED_BODY = <<<'TWIG'
    <p>Hello,</p>
    <p>Use the link below to set a new password.</p>
    <p><a href="{{ reset_url }}">Reset your password</a></p>
    <p>For security, this link will expire in {{ expiry_description }}.</p>
    TWIG;

    /** The body as it was BEFORE the migration, so the bug itself is pinned. */
    private const DRIFTED_BODY = <<<'TWIG'
    <p>Hello,</p>
    <p>Use the link below to set a new password.</p>
    <p><a href="{{ reset_url }}">Reset your password</a></p>
    <p>For security, this link will expire in 1 hour.</p>
    TWIG;

    /**
     * The AppSettings cache is NOT covered by the transaction rollback that isolates these tests:
     * the pool is on the filesystem under var/cache, so a value written here outlives the test and
     * poisons unrelated ones with a misleading failure. #442 documents exactly this, and
     * theCopyFollowsTheConfiguredInviteWindow() below is a setting write, so the cleanup is
     * mandatory rather than tidy. Clearing unconditionally is cheaper than remembering which cases
     * wrote a setting.
     */
    public function _after(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    private function superAdmin(FunctionalTester $I): AdminUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $user = (new AdminUser())->setEmail('reset-copy-actor' . uniqid() . '@example.com');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setPassword($hasher->hashPassword($user, 'original-password-123'));
        $I->haveInRepository($user);

        return $user;
    }

    private function target(FunctionalTester $I): AdminUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $user = (new AdminUser())->setEmail('reset-copy-target' . uniqid() . '@example.com');
        $user->setRoles(['ROLE_ADMIN']);
        $user->setPassword($hasher->hashPassword($user, 'original-password-123'));
        $I->haveInRepository($user);

        return $user;
    }

    private function installTemplate(FunctionalTester $I, string $body): void
    {
        $em = $I->grabService(\Doctrine\ORM\EntityManagerInterface::class);
        $row = $em->getRepository(EmailTemplate::class)->findOneBy(['code' => 'forgot_password']);
        if (!$row instanceof EmailTemplate) {
            $row = (new EmailTemplate())->setCode('forgot_password');
            $em->persist($row);
        }
        $row->setSubject('Reset your password')->setBody($body);
        $em->flush();
    }

    private function setSetting(FunctionalTester $I, string $key, string $value): void
    {
        $em = $I->grabService(\Doctrine\ORM\EntityManagerInterface::class);
        $row = $em->getRepository(AppSetting::class)->findOneBy(['settingKey' => $key]);
        if (!$row instanceof AppSetting) {
            $row = (new AppSetting())->setSettingKey($key)->setName($key);
            $em->persist($row);
        }
        $row->setSettingValue($value);
        $em->flush();
        $I->grabService(AppSettings::class)->clearCache();
    }

    /** Triggers the admin-issued reset for a user, which is the 30-day path. */
    private function adminIssuedReset(FunctionalTester $I, AdminUser $target): void
    {
        $I->sendAjaxPostRequest('/admin/user/reset-password/admin/' . $target->getId(), ['_token' => $I->csrfToken()]);
        $I->seeResponseCodeIsSuccessful();
    }

    /** The body of the most recent email actually built for this recipient. */
    private function lastSentBodyTo(FunctionalTester $I, string $recipient): string
    {
        $em = $I->grabService(\Doctrine\ORM\EntityManagerInterface::class);
        $log = $em->getRepository(EmailLog::class)->findOneBy(['recipient' => $recipient], ['id' => 'DESC']);

        $I->assertNotNull($log, 'no email_log row was written for ' . $recipient);

        return strip_tags((string) $log->getBody());
    }

    public function anAdminIssuedResetSaysThirtyDaysNotOneHour(FunctionalTester $I): void
    {
        $actor = $this->superAdmin($I);
        $I->amLoggedInAs($actor, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        $this->installTemplate($I, self::MIGRATED_BODY);
        $target = $this->target($I);

        $this->adminIssuedReset($I, $target);

        $body = $this->lastSentBodyTo($I, $target->getEmail());

        $I->assertStringContainsString('expire in 30 days', $body);
        $I->assertStringNotContainsString('expire in 1 hour', $body);
    }

    /**
     * The bug itself, pinned. With the pre-migration row installed, the very same request produces
     * the wrong sentence — so this test would have caught #35, and fails if the migration is ever
     * reverted on a box while this row shape comes back.
     */
    public function theDriftedRowIsWhatProducedTheWrongCopy(FunctionalTester $I): void
    {
        $actor = $this->superAdmin($I);
        $I->amLoggedInAs($actor, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        $this->installTemplate($I, self::DRIFTED_BODY);
        $target = $this->target($I);

        $this->adminIssuedReset($I, $target);

        $body = $this->lastSentBodyTo($I, $target->getEmail());

        // A hardcoded hour against a thirty-day token: exactly what was on the dev box.
        $I->assertStringContainsString('expire in 1 hour', $body);
    }

    /** The value is configuration, not a renamed constant: change it and the copy follows. */
    public function theCopyFollowsTheConfiguredInviteWindow(FunctionalTester $I): void
    {
        $actor = $this->superAdmin($I);
        $I->amLoggedInAs($actor, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        $this->installTemplate($I, self::MIGRATED_BODY);
        $this->setSetting($I, 'invite_token_expiry_days', '5');
        $target = $this->target($I);

        $this->adminIssuedReset($I, $target);

        $body = $this->lastSentBodyTo($I, $target->getEmail());

        $I->assertStringContainsString('expire in 5 days', $body);
    }

    /** No path may render the variable empty — "expire in ." is worse than the wrong number. */
    public function theDurationIsNeverRenderedEmpty(FunctionalTester $I): void
    {
        $actor = $this->superAdmin($I);
        $I->amLoggedInAs($actor, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');

        $this->installTemplate($I, self::MIGRATED_BODY);
        $target = $this->target($I);

        $this->adminIssuedReset($I, $target);

        $body = $this->lastSentBodyTo($I, $target->getEmail());

        $I->assertStringNotContainsString('expire in .', $body);
        $I->assertStringNotContainsString('expiry_description', $body);
    }
}
