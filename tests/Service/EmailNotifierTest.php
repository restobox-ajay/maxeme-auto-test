<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AdminUser;
use App\Entity\Company;
use App\Entity\CustomerUser;
use App\Entity\EmailTemplate;
use App\Service\AppSettings;
use App\Service\DocumentActor;
use App\Service\EmailNotifier;
use App\Tests\DoctrineIntegrationTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use App\Service\Email\EmailTemplateRenderer;
use App\Service\Email\EmailTemplateResolver;
use App\Service\Email\ShippedEmailTemplateCatalogue;
use App\Twig\SandboxedTemplateRenderer;
use Twig\Environment;

/**
 * Uses the real Twig Environment from the container (so template inheritance/rendering is
 * genuine) but a mocked MailerInterface (an interface, easy to mock and lets us capture/throw
 * on send() without a real transport) — same rationale as other integration tests in this repo
 * for keeping Doctrine-dependent behavior real while stubbing out true I/O boundaries.
 */
final class EmailNotifierTest extends DoctrineIntegrationTestCase
{
    private function notifier(MailerInterface $mailer): EmailNotifier
    {
        return new EmailNotifier(
            $this->em,
            $mailer,
            self::getContainer()->get(Environment::class),
            new EmailTemplateRenderer(new EmailTemplateResolver($this->em, self::getContainer()->get(ShippedEmailTemplateCatalogue::class)), self::getContainer()->get(SandboxedTemplateRenderer::class)),
            self::getContainer()->get(AppSettings::class),
        );
    }

    public function testSendWithNoValidRecipientsNeverCallsMailer(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $this->notifier($mailer)->send('missing_code', 'Subject', 'emails/forgot_password.html.twig', [], [null, '', '   '], fromCategory: 'support');
    }

    public function testSendFallsBackToViewWithDedupedTrimmedRecipientsWhenNoTemplateExists(): void
    {
        /** @var Email|null $sent */
        $sent = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->with(self::callback(function ($email) use (&$sent) {
            $sent = $email;
            return $email instanceof Email;
        }));

        // expiry_description is required, not optional: since #475 forgot_password.html.twig prints
        // it bare rather than falling back to a hardcoded "1 hour", because the same copy serves
        // two flows with different lifetimes and only the caller knows which one it just minted.
        // With strict_variables on, omitting it is a render error and no mail is sent at all —
        // which is the point: a forgotten duration fails loudly instead of mailing a blank one.
        $context = [
            'user_email' => 'jane@example.com',
            'reset_url' => 'https://example.test/reset',
            'expiry_description' => '1 hour',
        ];
        $this->notifier($mailer)->send(
            'no_such_template',
            'Reset your password',
            'emails/forgot_password.html.twig',
            $context,
            [' jane@example.com ', 'jane@example.com', null, ''],
            fromCategory: 'support',
        );

        self::assertInstanceOf(Email::class, $sent);
        self::assertSame('Reset your password', $sent->getSubject());
        self::assertCount(1, $sent->getTo());
        self::assertSame('jane@example.com', $sent->getTo()[0]->getAddress());
        self::assertStringContainsString('jane@example.com', (string) $sent->getHtmlBody());
        self::assertStringContainsString('https://example.test/reset', (string) $sent->getHtmlBody());
    }

    public function testSendUsesDbTemplateAndWrapsBodyInLayoutWhenBodyHasNoExtends(): void
    {
        $template = (new EmailTemplate())
            ->setCode('welcome')
            ->setModule('Core')
            ->setSubject('Hi {{ name }}')
            ->setBody('<p>Plain body for {{ name }}</p>');
        $this->em->persist($template);
        $this->em->flush();

        $sent = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function ($email) use (&$sent) { $sent = $email; });

        $this->notifier($mailer)->send('welcome', 'Fallback subject', 'emails/forgot_password.html.twig', ['name' => 'Ada', 'user_email' => 'x', 'reset_url' => 'x'], ['ada@example.com'], fromCategory: 'support');

        self::assertSame('Hi Ada', $sent->getSubject());
        self::assertStringContainsString('Plain body for Ada', (string) $sent->getHtmlBody());
    }

    public function testSendSwallowsMailerExceptionWithoutPropagating(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willThrowException(new \RuntimeException('smtp down'));

        $this->notifier($mailer)->send('missing_code', 'Subject', 'emails/forgot_password.html.twig', ['user_email' => 'x', 'reset_url' => 'x', 'expiry_description' => '1 hour'], ['a@example.com'], fromCategory: 'support');

        self::assertTrue(true); // no exception propagated out of send()
    }

    public function testActiveAdminEmailsReturnsDedupedLowercasedActiveOnly(): void
    {
        $this->em->persist((new AdminUser())->setEmail('Admin@Example.com')->setPassword('x'));
        $this->em->persist((new AdminUser())->setEmail('admin@example.com')->setPassword('x'));
        $inactiveAdmin = (new AdminUser())->setEmail('inactive@example.com')->setPassword('x');
        $this->em->persist($inactiveAdmin);
        $inactiveAdmin->setStatus('Inactive', DocumentActor::system());
        $this->em->flush();

        $emails = $this->notifier($this->createStub(MailerInterface::class))->activeAdminEmails();

        self::assertSame(['admin@example.com'], $emails);
    }

    public function testCompanyRecipientEmailsPrefersPrimaryThenFallbackThenActiveCustomerUsers(): void
    {
        $withPrimary = (new Company())->setName('Acme')->setCode('ACME')->setPrimaryEmail('billing@acme.test');
        $this->em->persist($withPrimary);

        $noPrimary = (new Company())->setName('Beta')->setCode('BETA');
        $this->em->persist($noPrimary);

        $noPrimaryOrFallback = (new Company())->setName('Gamma')->setCode('GAMMA');
        $this->em->persist($noPrimaryOrFallback);
        $this->em->persist((new CustomerUser())->setEmail('active@gamma.test')->setPassword('x')->setCompany($noPrimaryOrFallback));
        $inactiveGammaUser = (new CustomerUser())->setEmail('inactive@gamma.test')->setPassword('x')->setCompany($noPrimaryOrFallback);
        $this->em->persist($inactiveGammaUser);
        $inactiveGammaUser->setStatus('Inactive', DocumentActor::system());
        $this->em->flush();

        $notifier = $this->notifier($this->createStub(MailerInterface::class));

        self::assertSame(['billing@acme.test'], $notifier->companyRecipientEmails($withPrimary, 'fallback@example.com'));
        self::assertSame(['fallback@example.com'], $notifier->companyRecipientEmails($noPrimary, 'fallback@example.com'));
        self::assertSame(['active@gamma.test'], $notifier->companyRecipientEmails($noPrimaryOrFallback, null));
    }

    public function testOrderConfirmationRecipientsIncludesBothUserAndCompanyPrimaryDeduped(): void
    {
        $company = (new Company())->setName('Acme')->setCode('ACME')->setPrimaryEmail('billing@acme.test');
        $this->em->persist($company);
        $this->em->flush();

        $notifier = $this->notifier($this->createStub(MailerInterface::class));

        // (a) Two distinct addresses both receive the confirmation.
        self::assertSame(
            ['buyer@acme.test', 'billing@acme.test'],
            $notifier->orderConfirmationRecipients($company, 'buyer@acme.test'),
        );
    }

    public function testOrderConfirmationRecipientsDedupesIdenticalAddressesCaseInsensitively(): void
    {
        $company = (new Company())->setName('Acme')->setCode('ACME')->setPrimaryEmail('Buyer@Acme.test');
        $this->em->persist($company);
        $this->em->flush();

        $notifier = $this->notifier($this->createStub(MailerInterface::class));

        // (b) Same address (differing only by case) collapses to a single recipient.
        self::assertSame(
            ['buyer@acme.test'],
            $notifier->orderConfirmationRecipients($company, 'buyer@acme.test'),
        );
    }

    public function testOrderConfirmationRecipientsFallsBackToJustOneSideWhenOtherIsBlank(): void
    {
        $noPrimary = (new Company())->setName('Beta')->setCode('BETA');
        $this->em->persist($noPrimary);

        $noUser = (new Company())->setName('Gamma')->setCode('GAMMA')->setPrimaryEmail('billing@gamma.test');
        $this->em->persist($noUser);
        $this->em->flush();

        $notifier = $this->notifier($this->createStub(MailerInterface::class));

        // No company primary -> just the ordering user.
        self::assertSame(['buyer@beta.test'], $notifier->orderConfirmationRecipients($noPrimary, 'buyer@beta.test'));
        // No ordering user email -> just the company primary.
        self::assertSame(['billing@gamma.test'], $notifier->orderConfirmationRecipients($noUser, null));
    }

    public function testOrderConfirmationRecipientsFallsBackToActiveUsersWhenBothBlank(): void
    {
        $company = (new Company())->setName('Delta')->setCode('DELTA');
        $this->em->persist($company);
        $this->em->persist((new CustomerUser())->setEmail('active@delta.test')->setPassword('x')->setCompany($company));
        $this->em->flush();

        $notifier = $this->notifier($this->createStub(MailerInterface::class));

        self::assertSame(['active@delta.test'], $notifier->orderConfirmationRecipients($company, null));
    }
}
