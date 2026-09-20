<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AdminUser;
use App\Entity\AppSetting;
use App\Entity\CustomerUser;
use App\Entity\EmailTemplate;
use App\Service\AppSettings;
use App\Service\CustomerInviteMailer;
use App\Service\CustomerUrlGenerator;
use App\Service\ResetTokenService;
use App\Tests\DoctrineIntegrationTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use App\Service\Email\EmailTemplateRenderer;
use App\Service\Email\EmailTemplateResolver;
use App\Service\Email\ShippedEmailTemplateCatalogue;
use App\Twig\SandboxedTemplateRenderer;
use Twig\Environment;

/**
 * Uses the real Twig Environment, CustomerUrlGenerator, ResetTokenService and router from the
 * container (so token hashing, URL generation and template inheritance/rendering are genuine)
 * but a mocked MailerInterface — same rationale as EmailNotifierTest for keeping
 * Doctrine-dependent behavior real while stubbing out the true I/O boundary.
 */
final class CustomerInviteMailerTest extends DoctrineIntegrationTestCase
{
    private function mailerService(MailerInterface $mailer): CustomerInviteMailer
    {
        return new CustomerInviteMailer(
            $mailer,
            self::getContainer()->get(Environment::class),
            new EmailTemplateRenderer(new EmailTemplateResolver($this->em, self::getContainer()->get(ShippedEmailTemplateCatalogue::class)), self::getContainer()->get(SandboxedTemplateRenderer::class)),
            self::getContainer()->get(CustomerUrlGenerator::class),
            self::getContainer()->get(ResetTokenService::class),
            self::getContainer()->get(UrlGeneratorInterface::class),
            self::getContainer()->get(\App\Service\AppSettings::class),
        );
    }

    public function testSendSetsHashedResetTokenAndExpiryAndSendsCustomerAccountSetupUrl(): void
    {
        $user = (new CustomerUser())->setEmail('jane@example.com')->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        $sent = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->with(self::callback(function ($email) use (&$sent) {
            $sent = $email;
            return $email instanceof Email;
        }));

        $result = $this->mailerService($mailer)->send($user, $this->em);

        self::assertTrue($result);
        self::assertNotNull($user->getResetToken());
        self::assertSame(64, \strlen($user->getResetToken()));
        self::assertNotNull($user->getResetTokenExpiresAt());
        // An invite is unsolicited mail the recipient cannot re-issue for themselves, so it gets the
        // configurable invite lifetime (invite_token_expiry_days, default 30) rather than the 1 hour
        // the self-service forgot-password flow still uses. No setting row exists in the test
        // database, so this exercises the code default.
        self::assertEqualsWithDelta(
            (new \DateTimeImmutable('+' . AppSettings::INVITE_EXPIRY_DEFAULT_DAYS . ' days'))->getTimestamp(),
            $user->getResetTokenExpiresAt()->getTimestamp(),
            5
        );

        // No app_name/company_name set in this test, so site_name() uses its neutral fallback.
        self::assertSame('Invitation to Catalog', $sent->getSubject());
        self::assertCount(1, $sent->getTo());
        self::assertSame('jane@example.com', $sent->getTo()[0]->getAddress());
        self::assertStringContainsString('jane@example.com', (string) $sent->getHtmlBody());
        self::assertMatchesRegularExpression(
            '#/auth/setup-account\?token=[0-9a-f]{64}#',
            (string) $sent->getHtmlBody()
        );
    }

    /**
     * #450: this email used to hardcode "This invitation will expire in 1 hour." regardless of
     * what the token above was actually granted (the invite lifetime, default 30 days). No
     * setting row exists in this test database, so this exercises the code default.
     */
    public function testSendEmailBodyShowsConfiguredExpiryInsteadOfHardcodedOneHour(): void
    {
        $user = (new CustomerUser())->setEmail('grace@example.com')->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        $sent = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function ($email) use (&$sent) { $sent = $email; });

        $this->mailerService($mailer)->send($user, $this->em);

        self::assertStringContainsString(
            'This invitation will expire in ' . AppSettings::INVITE_EXPIRY_DEFAULT_DAYS . ' days.',
            (string) $sent->getHtmlBody()
        );
        self::assertStringNotContainsString('1 hour', (string) $sent->getHtmlBody());
    }

    /** Proves the copy is genuinely reading invite_token_expiry_days, not a renamed constant. */
    public function testSendEmailBodyReflectsACustomConfiguredExpiry(): void
    {
        $this->em->persist(
            (new AppSetting())
                ->setSettingKey(AppSettings::INVITE_EXPIRY_KEY)
                ->setName('Invite Link Expiry (Days)')
                ->setCategory('General')
                ->setVisibility(AppSetting::VISIBILITY_STORE)
                ->setSettingValue('7')
        );
        $this->em->flush();
        self::getContainer()->get(AppSettings::class)->clearCache();

        $user = (new CustomerUser())->setEmail('henry@example.com')->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        $sent = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function ($email) use (&$sent) { $sent = $email; });

        $this->mailerService($mailer)->send($user, $this->em);

        self::assertStringContainsString('This invitation will expire in 7 days.', (string) $sent->getHtmlBody());
    }

    public function testSendUsesAbsoluteAdminAccountSetupUrlForAdminUser(): void
    {
        $user = (new AdminUser())->setEmail('admin@example.com')->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        $sent = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function ($email) use (&$sent) { $sent = $email; });

        $this->mailerService($mailer)->send($user, $this->em);

        self::assertStringContainsString('http', (string) $sent->getHtmlBody());
        self::assertMatchesRegularExpression(
            '#https?://[^"\']+/admin/setup-account\?token=[0-9a-f]{64}#',
            (string) $sent->getHtmlBody()
        );
    }

    public function testSendPrefersNewUserInvitedTemplateOverInviteFallback(): void
    {
        $this->em->persist((new EmailTemplate())->setCode('invite')->setSubject('Old invite subject')->setBody('<p>old body</p>'));
        $this->em->persist((new EmailTemplate())->setCode('new_user_invited')->setSubject('Hi {{ user_email }}')->setBody('<p>New body for {{ user_email }}</p>'));
        $this->em->flush();

        $user = (new CustomerUser())->setEmail('ada@example.com')->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        $sent = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function ($email) use (&$sent) { $sent = $email; });

        $this->mailerService($mailer)->send($user, $this->em);

        self::assertSame('Hi ada@example.com', $sent->getSubject());
        self::assertStringContainsString('New body for ada@example.com', (string) $sent->getHtmlBody());
    }

    /**
     * The legacy `invite` fallback no longer fires, and this pins that rather than mourning it.
     *
     * The chain is `new_user_invited ?? invite ?? company_user_invitation`, written when a database
     * might simply not have had a row for the first one. Since #507 every shipped code resolves from
     * code with no row present, so `new_user_invited` always wins and the two fallbacks behind it
     * are unreachable — `company_user_invitation` doubly so, since it has never shipped and no row
     * has ever carried it.
     *
     * This test previously asserted the opposite: seed only `invite`, expect it to be used. That
     * assertion described a database with an incomplete seed, which is a state that can no longer
     * exist. The behaviour change is real and worth stating plainly: an admin who customised the
     * template labelled "Invite (Legacy)" and not "New User Invited" would have been having their
     * edit used, and now would not.
     */
    public function testTheLegacyInviteTemplateNoLongerWinsOverTheShippedNewUserInvited(): void
    {
        $this->em->persist((new EmailTemplate())->setCode('invite')->setSubject('Welcome {{ user_email }}')->setBody('<p>Fallback body for {{ user_email }}</p>'));
        $this->em->flush();

        $user = (new CustomerUser())->setEmail('bob@example.com')->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        $sent = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function ($email) use (&$sent) { $sent = $email; });

        $this->mailerService($mailer)->send($user, $this->em);

        self::assertNotSame('Welcome bob@example.com', $sent->getSubject(), 'the legacy invite row should no longer be reached');
        self::assertStringNotContainsString('Fallback body for bob@example.com', (string) $sent->getHtmlBody());
        // The shipped subject is itself Twig ('Invitation to {{ site_name() }}'), and no app_name is
        // set in the test database, so site_name() falls back to its neutral default.
        self::assertSame(
            'Invitation to Catalog',
            $sent->getSubject(),
            'the shipped new_user_invited template should win, with no row of its own',
        );
    }

    public function testSendWrapsDbTemplateBodyInLayoutWhenBodyHasNoExtendsBlock(): void
    {
        // new_user_invited, not invite: the first code in the chain always resolves now, so a row on
        // the legacy one would never be consulted.
        $this->em->persist((new EmailTemplate())->setCode('new_user_invited')->setSubject('Hi')->setBody('<p>Plain body, no layout</p>'));
        $this->em->flush();

        $user = (new CustomerUser())->setEmail('carl@example.com')->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        $sent = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function ($email) use (&$sent) { $sent = $email; });

        $this->mailerService($mailer)->send($user, $this->em);

        self::assertStringContainsString('Plain body, no layout', (string) $sent->getHtmlBody());
    }

    public function testSendReturnsFalseAndSwallowsMailerException(): void
    {
        $user = (new CustomerUser())->setEmail('fail@example.com')->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willThrowException(new \RuntimeException('smtp down'));

        $result = $this->mailerService($mailer)->send($user, $this->em);

        self::assertFalse($result);
    }
}
