<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\AppSetting;
use App\Service\AppSettings;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Invite and admin-issued reset links last invite_token_expiry_days (30), not an hour.
 *
 * Every reset_token call site used to hardcode +1 hour. That is the right number for the
 * self-service forgot-password flow — the person reading the mail just asked for it — and the wrong
 * one for an invite, which is unsolicited, may sit unread for a day, and cannot be re-issued by the
 * recipient (admin_account_setup only validates a token that already exists).
 *
 * The split is the whole point of the change, so it is what is asserted here: the admin-driven
 * paths move to the configured window, and the anonymous forgot-password path — the only one an
 * unauthenticated visitor can trigger — must NOT move with them. A regression in either direction
 * fails a test, including the tempting "just change them all" one.
 *
 * The override case is what proves the value is genuinely configuration rather than a renamed
 * constant: a row of 5 has to produce a 5-day link.
 */
final class AdminInviteTokenExpiryCest
{
    /** Tolerance in seconds — the request happens some ms after the expected value is computed. */
    private const DELTA = 120;

    private function superAdmin(FunctionalTester $I): AdminUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $user = (new AdminUser())->setEmail('invite-expiry-actor' . uniqid() . '@example.com');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setPassword($hasher->hashPassword($user, 'original-password-123'));
        $I->haveInRepository($user);

        return $user;
    }

    private function target(FunctionalTester $I, string $prefix): AdminUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $user = (new AdminUser())->setEmail($prefix . uniqid() . '@example.com');
        $user->setRoles(['ROLE_ADMIN']);
        $user->setPassword($hasher->hashPassword($user, 'original-password-123'));
        $I->haveInRepository($user);

        return $user;
    }

    private function loginAs(FunctionalTester $I, AdminUser $user): void
    {
        $I->amLoggedInAs($user, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * Both endpoints are CSRF-guarded and answer a bad token with a JSON refusal instead of doing
     * the work, so the token has to come from a real rendered page — posting without one asserts
     * nothing about expiry, it just exercises the CSRF check.
     */
    private function inviteVia(FunctionalTester $I, string $path, int $id): void
    {
        $I->amOnPage('/admin/user/staff');
        $I->sendAjaxPostRequest($path . $id, ['_token' => $I->csrfToken()]);
    }

    /** Re-read the row from the database rather than trusting the in-memory entity. */
    private function freshExpiry(FunctionalTester $I, int $id): ?\DateTimeImmutable
    {
        $em = $I->grabService(\Doctrine\ORM\EntityManagerInterface::class);
        $em->clear();

        return $em->find(AdminUser::class, $id)?->getResetTokenExpiresAt();
    }

    private function assertExpiresIn(FunctionalTester $I, ?\DateTimeImmutable $actual, string $modifier): void
    {
        $I->assertNotNull($actual, 'Expected a reset token expiry to have been written.');
        $I->assertEqualsWithDelta(
            (new \DateTimeImmutable($modifier))->getTimestamp(),
            $actual->getTimestamp(),
            self::DELTA,
            sprintf('Expected the link to expire %s, got %s.', $modifier, $actual->format(\DATE_ATOM)),
        );
    }

    public function resendInviteGivesThirtyDaysNotOneHour(FunctionalTester $I): void
    {
        $target = $this->target($I, 'invite-target');
        $this->loginAs($I, $this->superAdmin($I));

        $this->inviteVia($I, '/admin/user/resend-invite/admin/', $target->getId());

        $this->assertExpiresIn($I, $this->freshExpiry($I, $target->getId()), '+30 days');

        // #450: invite.html.twig used to hardcode "1 hour" no matter what the token above was
        // actually granted.
        $body = $I->grabLastSentEmailBody($target->getEmail());
        $I->assertStringContainsString('This invitation will expire in 30 days.', $body);
        $I->assertStringNotContainsString('1 hour', $body);
    }

    /**
     * #450, bug B: this route sets an invite-lifetime token (assertExpiresIn below) but renders
     * forgot_password.html.twig, which hardcoded "1 hour" — telling the recipient the link dies in
     * an hour when it actually lasts 30 days. The email copy has to match the token it describes.
     */
    public function adminTriggeredResetGivesThirtyDaysNotOneHour(FunctionalTester $I): void
    {
        $target = $this->target($I, 'reset-target');
        $this->loginAs($I, $this->superAdmin($I));

        $this->inviteVia($I, '/admin/user/reset-password/admin/', $target->getId());

        $this->assertExpiresIn($I, $this->freshExpiry($I, $target->getId()), '+30 days');

        $body = $I->grabLastSentEmailBody($target->getEmail());
        $I->assertStringContainsString('this link will expire in 30 days.', $body);
        $I->assertStringNotContainsString('1 hour', $body);
    }

    /**
     * The guard against over-applying the change. This is the one path an anonymous visitor can
     * trigger for an arbitrary address, so its short window is a security property, not an
     * oversight — it must stay at an hour even though it writes the same column.
     */
    public function selfServiceForgotPasswordStaysAtOneHour(FunctionalTester $I): void
    {
        $target = $this->target($I, 'forgot-target');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/forgot-password');
        // Posted directly rather than via submitForm(): on success this action redirects to an
        // absolute admin-host URL, which the browser-less client rejects as external before the
        // assertion is ever reached. The token is written before that redirect, so going straight
        // at the endpoint tests the same code path without the client following anything.
        $I->sendAjaxPostRequest('/admin/forgot-password', [
            'email' => $target->getEmail(),
            '_token' => $I->csrfToken(),
        ]);

        $this->assertExpiresIn($I, $this->freshExpiry($I, $target->getId()), '+1 hour');

        // Regression pin for #450: this is one of the two genuinely self-service flows that must
        // keep saying "1 hour" — it must NOT start rendering a day count.
        $I->assertStringContainsString(
            'this link will expire in 1 hour.',
            $I->grabLastSentEmailBody($target->getEmail()),
        );
    }

    /** A configured value has to actually drive the link, or this is a renamed constant. */
    public function configuredValueOverridesTheDefault(FunctionalTester $I): void
    {
        $em = $I->grabService(\Doctrine\ORM\EntityManagerInterface::class);
        $setting = $em->getRepository(AppSetting::class)
            ->findOneBy(['settingKey' => AppSettings::INVITE_EXPIRY_KEY])
            ?? (new AppSetting())
                ->setSettingKey(AppSettings::INVITE_EXPIRY_KEY)
                ->setName('Invite Link Expiry (Days)')
                ->setCategory('General')
                ->setVisibility(AppSetting::VISIBILITY_STORE);

        $setting->setSettingValue('5');
        $em->persist($setting);
        $em->flush();
        // all() is cached for an hour; without this the write is invisible to the same process.
        $I->grabService(AppSettings::class)->clearCache();

        try {
            $target = $this->target($I, 'configured-target');
            $this->loginAs($I, $this->superAdmin($I));

            $this->inviteVia($I, '/admin/user/resend-invite/admin/', $target->getId());

            $this->assertExpiresIn($I, $this->freshExpiry($I, $target->getId()), '+5 days');

            // The email copy has to move with the setting too, not just the DB column (#450).
            $I->assertStringContainsString(
                'This invitation will expire in 5 days.',
                $I->grabLastSentEmailBody($target->getEmail()),
            );
        } finally {
            $this->restoreDefaultExpiry($I);
        }
    }

    /**
     * Put the setting back however this test ended.
     *
     * In a finally, and re-reading the row rather than reusing the entity from above: freshExpiry()
     * calls EntityManager::clear(), which detaches it, so persisting that instance INSERTs a second
     * row and trips the unique index on setting_key. When that happened the cleanup threw, the
     * override survived the test, and every other case in this file silently got 5-day links —
     * failures that pointed at the production code rather than at this method.
     */
    private function restoreDefaultExpiry(FunctionalTester $I): void
    {
        $em = $I->grabService(\Doctrine\ORM\EntityManagerInterface::class);
        $fresh = $em->getRepository(AppSetting::class)
            ->findOneBy(['settingKey' => AppSettings::INVITE_EXPIRY_KEY]);

        if ($fresh instanceof AppSetting) {
            $fresh->setSettingValue((string) AppSettings::INVITE_EXPIRY_DEFAULT_DAYS);
            $em->flush();
        }

        $I->grabService(AppSettings::class)->clearCache();
    }
}
