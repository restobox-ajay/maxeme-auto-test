<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Entity\AppSetting;
use App\Entity\Company;
use App\Entity\CustomerUser;
use App\Entity\EmailTemplate;
use App\Service\AppSettings;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The duration in the email a recipient actually receives, on the path production actually takes.
 *
 * Every send path here renders the FILE template and then, if an email_template row exists for the
 * code, discards that render and re-renders the row. Production has those rows; the test databases
 * do not, because both suites build their schema from entity mappings rather than the migration
 * chain. That asymmetry is the whole bug (#475): #450 fixed the files, every test it added asserted
 * against the files, and production carried on promising "1 hour" for admin-issued reset links that
 * lasted 30 days.
 *
 * So each case here seeds the row first — with the body the migration leaves behind, carrying a
 * marker string that exists in no file template — and then asserts on the sent Email. If a test in
 * this file ever passes without ROW-RENDERED in the body, the row stopped winning and the test has
 * stopped covering the thing it was written for.
 *
 * The other half is the split the settings encode: invite and admin-issued links follow
 * invite_token_expiry_days, the two self-service flows follow password_reset_expiry_hours, and
 * moving one must not move the other. forgot_password is rendered by both, from a single row, which
 * is why that row can name no duration of its own.
 */
final class EmailTemplateExpiryCopyCest
{
    /** Tolerance in seconds — the request happens some ms after the expected value is computed. */
    private const DELTA = 120;

    private const MARKER = 'ROW-RENDERED';

    /**
     * The body Version20260806040000 leaves in the forgot_password row, plus a marker. Shortened,
     * but the sentence under test is byte-for-byte the migrated one.
     */
    private const FORGOT_PASSWORD_ROW = <<<'TWIG'
        {% extends 'emails/layout.html.twig' %}
        {% block body %}
        <p>Hello <strong>{{ user_email|default('User') }}</strong>,</p>
        <p><a href="{{ reset_url|default('#') }}">Reset My Password</a></p>
        <p>ROW-RENDERED For security, this link will expire in {{ expiry_description }}.</p>
        {% endblock %}
        TWIG;

    /** The same, for the invite row (new_user_invited is preferred over invite by every caller). */
    private const INVITE_ROW = <<<'TWIG'
        {% extends 'emails/layout.html.twig' %}
        {% block body %}
        <p>Hello <strong>{{ user_email|default('User') }}</strong>,</p>
        <p><a href="{{ reset_url|default('#') }}">Set Up My Account</a></p>
        <p>ROW-RENDERED This invitation will expire in {{ expiry_description }}. If you didn't request this, you can ignore this email.</p>
        {% endblock %}
        TWIG;

    private function seedRow(FunctionalTester $I, string $code, string $body): void
    {
        $template = (new EmailTemplate())
            ->setCode($code)
            ->setModule($code)
            ->setSentTo('Customer')
            ->setSubject('Message from {{ site_name() }}')
            ->setBody($body)
            ->setStatus('Active');

        $I->haveInRepository($template);
    }

    private function admin(FunctionalTester $I, string $prefix, array $roles = ['ROLE_ADMIN']): AdminUser
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $user = (new AdminUser())->setEmail($prefix . uniqid() . '@example.test');
        $user->setRoles($roles);
        $user->setPassword($hasher->hashPassword($user, 'original-password-123'));
        $I->haveInRepository($user);

        return $user;
    }

    private function customer(FunctionalTester $I): CustomerUser
    {
        $company = (new Company())->setName('Expiry Copy Co');
        $I->haveInRepository($company);

        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $user = (new CustomerUser())
            ->setEmail('expiry-copy-' . uniqid() . '@example.test')
            ->setCompany($company);
        $user->setPassword($hasher->hashPassword($user, 'original-password-123'));
        $I->haveInRepository($user);

        return $user;
    }

    /** Both admin endpoints are CSRF-guarded and answer a bad token with a refusal, not the work. */
    private function postAdminAction(FunctionalTester $I, string $path, int $id): void
    {
        $I->amOnPage('/admin/user/staff');
        $I->sendAjaxPostRequest($path . $id, ['_token' => $I->csrfToken()]);
    }

    private function loginAsSuperAdmin(FunctionalTester $I): void
    {
        $I->amLoggedInAs($this->admin($I, 'expiry-copy-actor', ['ROLE_SUPER_ADMIN']), 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * @param class-string<AdminUser|CustomerUser> $class
     */
    private function freshExpiry(FunctionalTester $I, string $class, int $id): ?\DateTimeImmutable
    {
        $em = $I->grabService(\Doctrine\ORM\EntityManagerInterface::class);
        $em->clear();

        return $em->find($class, $id)?->getResetTokenExpiresAt();
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

    /** @return string the HTML body of the email that was just sent */
    private function sentBody(FunctionalTester $I, bool $expectRow = true): string
    {
        $I->seeEmailIsSent();
        $body = (string) $I->grabLastSentEmail()->getHtmlBody();

        if ($expectRow) {
            $I->assertStringContainsString(
                self::MARKER,
                $body,
                'The email came from the file template, so this test is not covering the database row at all.',
            );
        }

        // The failure mode being guarded against is not a wrong duration but a missing one. A row
        // an admin has edited into `{{ expiry_description|default('') }}`, or a future context key
        // rename, leaves the sentence reading "expire in ." — still prose, still sent, and nobody
        // notices. No path may produce it.
        $I->assertStringNotContainsString('expire in .', $body);
        $I->assertStringNotContainsString('expire in  ', $body);

        return $body;
    }

    private function setSetting(FunctionalTester $I, string $key, string $value): void
    {
        $em = $I->grabService(\Doctrine\ORM\EntityManagerInterface::class);
        $setting = $em->getRepository(AppSetting::class)->findOneBy(['settingKey' => $key])
            ?? (new AppSetting())
                ->setSettingKey($key)
                ->setName($key)
                ->setCategory('General')
                ->setVisibility(AppSetting::VISIBILITY_STORE);

        $setting->setSettingValue($value);
        $em->persist($setting);
        $em->flush();

        // all() is cached for an hour; without this the write is invisible to the same process.
        $I->grabService(AppSettings::class)->clearCache();
    }

    public function _after(FunctionalTester $I): void
    {
        // The rollback between tests puts the rows back, but the AppSettings cache is process-wide
        // and outlives it — a stale entry would hand the next test a value its database no longer
        // holds, and that failure points at the production code rather than at this file.
        $I->grabService(AppSettings::class)->clearCache();
    }

    public function inviteEmailFromTheDatabaseRowShowsTheInviteWindow(FunctionalTester $I): void
    {
        $this->seedRow($I, 'new_user_invited', self::INVITE_ROW);
        $target = $this->admin($I, 'row-invite-target');
        $this->loginAsSuperAdmin($I);

        $this->postAdminAction($I, '/admin/user/resend-invite/admin/', $target->getId());

        $this->assertExpiresIn($I, $this->freshExpiry($I, AdminUser::class, $target->getId()), '+30 days');

        $body = $this->sentBody($I);
        $I->assertStringContainsString('This invitation will expire in 30 days.', $body);
        $I->assertStringNotContainsString('1 hour', $body);
    }

    /**
     * The exact production bug: this route mints an invite-lifetime token and renders the
     * forgot_password row, which said "1 hour".
     */
    public function adminInitiatedResetFromTheDatabaseRowShowsTheInviteWindow(FunctionalTester $I): void
    {
        $this->seedRow($I, 'forgot_password', self::FORGOT_PASSWORD_ROW);
        $target = $this->admin($I, 'row-reset-target');
        $this->loginAsSuperAdmin($I);

        $this->postAdminAction($I, '/admin/user/reset-password/admin/', $target->getId());

        $this->assertExpiresIn($I, $this->freshExpiry($I, AdminUser::class, $target->getId()), '+30 days');

        $body = $this->sentBody($I);
        $I->assertStringContainsString('this link will expire in 30 days.', $body);
        $I->assertStringNotContainsString('1 hour', $body);
    }

    /** The same row, the other flow, the other lifetime — from the admin firewall. */
    public function adminSelfServiceResetFromTheDatabaseRowShowsTheShortWindow(FunctionalTester $I): void
    {
        $I->grabService('cache.rate_limiter')->clear();
        $this->seedRow($I, 'forgot_password', self::FORGOT_PASSWORD_ROW);
        $target = $this->admin($I, 'row-self-service-target');

        $I->haveHttpHeader('Host', 'admin.localhost');
        $I->amOnPage('/admin/forgot-password');
        // Without this the mailer collector inspected below belongs to the redirect target — a GET
        // that sends nothing — instead of this POST.
        $I->stopFollowingRedirects();
        $I->sendAjaxPostRequest('/admin/forgot-password', [
            'email' => $target->getEmail(),
            '_token' => $I->csrfToken(),
        ]);

        $this->assertExpiresIn($I, $this->freshExpiry($I, AdminUser::class, $target->getId()), '+1 hour');

        $body = $this->sentBody($I);
        $I->assertStringContainsString('this link will expire in 1 hour.', $body);
        $I->assertStringNotContainsString('30 days', $body);
    }

    /** And from the customer firewall, which is a separate controller with its own copy of it all. */
    public function customerSelfServiceResetFromTheDatabaseRowShowsTheShortWindow(FunctionalTester $I): void
    {
        $I->grabService('cache.rate_limiter')->clear();
        $this->seedRow($I, 'forgot_password', self::FORGOT_PASSWORD_ROW);
        $target = $this->customer($I);

        $I->amOnPage('/auth/password-reset');
        $I->stopFollowingRedirects();
        $I->sendAjaxPostRequest('/auth/password-reset', [
            'email' => $target->getEmail(),
            '_token' => $I->csrfToken(),
        ]);

        $this->assertExpiresIn($I, $this->freshExpiry($I, CustomerUser::class, $target->getId()), '+1 hour');

        $body = $this->sentBody($I);
        $I->assertStringContainsString('this link will expire in 1 hour.', $body);
        $I->assertStringNotContainsString('30 days', $body);
    }

    /** Configuration, not a renamed constant: the token AND the copy have to move together. */
    public function configuredPasswordResetHoursDrivesBothTheTokenAndTheCopy(FunctionalTester $I): void
    {
        $I->grabService('cache.rate_limiter')->clear();
        $this->setSetting($I, AppSettings::PASSWORD_RESET_EXPIRY_KEY, '6');
        $this->seedRow($I, 'forgot_password', self::FORGOT_PASSWORD_ROW);
        $target = $this->customer($I);

        $I->amOnPage('/auth/password-reset');
        $I->stopFollowingRedirects();
        $I->sendAjaxPostRequest('/auth/password-reset', [
            'email' => $target->getEmail(),
            '_token' => $I->csrfToken(),
        ]);

        $this->assertExpiresIn($I, $this->freshExpiry($I, CustomerUser::class, $target->getId()), '+6 hours');

        $I->assertStringContainsString('this link will expire in 6 hours.', $this->sentBody($I));
    }

    /**
     * The guard against re-merging the two windows. An admin lengthening the invite window must not
     * silently lengthen the one link an anonymous visitor can request for an address they do not own.
     */
    public function changingTheInviteWindowDoesNotMoveTheSelfServiceCopy(FunctionalTester $I): void
    {
        $I->grabService('cache.rate_limiter')->clear();
        $this->setSetting($I, AppSettings::INVITE_EXPIRY_KEY, '5');
        $this->seedRow($I, 'forgot_password', self::FORGOT_PASSWORD_ROW);
        $this->seedRow($I, 'new_user_invited', self::INVITE_ROW);

        $invited = $this->admin($I, 'row-split-invite');
        $this->loginAsSuperAdmin($I);
        $this->postAdminAction($I, '/admin/user/resend-invite/admin/', $invited->getId());

        $this->assertExpiresIn($I, $this->freshExpiry($I, AdminUser::class, $invited->getId()), '+5 days');
        $I->assertStringContainsString('This invitation will expire in 5 days.', $this->sentBody($I));

        $forgetful = $this->customer($I);
        $I->amOnPage('/auth/password-reset');
        $I->stopFollowingRedirects();
        $I->sendAjaxPostRequest('/auth/password-reset', [
            'email' => $forgetful->getEmail(),
            '_token' => $I->csrfToken(),
        ]);

        $this->assertExpiresIn($I, $this->freshExpiry($I, CustomerUser::class, $forgetful->getId()), '+1 hour');

        $body = $this->sentBody($I);
        $I->assertStringContainsString('this link will expire in 1 hour.', $body);
        $I->assertStringNotContainsString('5 days', $body);
    }

    /**
     * The admin's own view of these rows. The preview renders the row against a fixed mock context
     * (ConfigController::getMockDataForTemplate), and under strict_variables a key missing from
     * that context is not a blank field but a Twig error — so the moment the row started printing
     * {{ expiry_description }}, previewing three of the shipped templates would have shown
     * "Template Render Error" on a template with nothing wrong with it.
     */
    public function previewingTheRewrittenRowShowsARealDurationNotARenderError(FunctionalTester $I): void
    {
        $this->seedRow($I, 'forgot_password', self::FORGOT_PASSWORD_ROW);
        $this->loginAsSuperAdmin($I);

        // Keyed by code, not row id (#507): most templates no longer have a row to have an id.
        $I->amOnPage('/admin/email-template/preview/forgot_password');
        $I->seeResponseCodeIsSuccessful();
        $I->dontSee('Template Render Error');
        $I->see('this link will expire in 1 hour.');
    }

    /**
     * The file template is the fallback whenever an installation has no row for the code, and it no
     * longer carries a |default('1 hour') to paper over a caller that forgot to supply the
     * duration. So the path with no row has to be checked too, or a missing expiry_description
     * would render "expire in ." for exactly the installations that never customised anything.
     */
    public function theFileTemplateFallbackAlsoRendersARealDuration(FunctionalTester $I): void
    {
        $I->grabService('cache.rate_limiter')->clear();
        $target = $this->customer($I);

        $I->amOnPage('/auth/password-reset');
        $I->stopFollowingRedirects();
        $I->sendAjaxPostRequest('/auth/password-reset', [
            'email' => $target->getEmail(),
            '_token' => $I->csrfToken(),
        ]);

        $body = $this->sentBody($I, false);
        $I->assertStringNotContainsString(self::MARKER, $body, 'No row was seeded, so the file template must have rendered.');
        $I->assertStringContainsString('this link will expire in 1 hour.', $body);
    }
}
