<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\EmailLog;
use App\EventSubscriber\MailerLogSubscriber;
use App\Service\EmailBodyRedactor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class MailerLogSubscriberTest extends TestCase
{
    private function makeSentMessageEvent(Email $email): SentMessageEvent
    {
        $sentMessage = new SentMessage($email, new Envelope(new Address('from@example.com'), [new Address('to@example.com')]));

        return new SentMessageEvent($sentMessage);
    }

    public function testGetSubscribedEventsMapsSentAndFailed(): void
    {
        self::assertSame([
            SentMessageEvent::class => 'onSent',
            FailedMessageEvent::class => 'onFailed',
        ], MailerLogSubscriber::getSubscribedEvents());
    }

    public function testOnSentPersistsEmailLogWithSubjectRecipientAndHtmlBody(): void
    {
        $email = (new Email())
            ->from('from@example.com')
            ->to('one@example.com', 'two@example.com')
            ->subject('Welcome aboard')
            ->html('<p>Hi</p>')
            ->text('Hi');

        $captured = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')
            ->with(self::callback(function ($log) use (&$captured) {
                $captured = $log;
                return $log instanceof EmailLog;
            }));
        $em->expects(self::once())->method('flush');

        (new MailerLogSubscriber($em, new EmailBodyRedactor()))->onSent($this->makeSentMessageEvent($email));

        self::assertSame('Welcome aboard', $captured->getTemplateCode());
        self::assertSame('one@example.com, two@example.com', $captured->getRecipient());
        self::assertSame('Sent', $captured->getStatus());
        self::assertSame('<p>Hi</p>', $captured->getBody());
    }

    public function testOnSentRedactsResetTokenBeforePersistingTheBody(): void
    {
        $token = str_repeat('9f3a', 16);
        $email = (new Email())
            ->from('from@example.com')
            ->to('one@example.com')
            ->subject('Reset your password')
            ->html('<p>Hi</p><p><a href="https://shop.example.test/auth/reset-password?token=' . $token . '">Reset</a></p>');

        $captured = null;
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($log) use (&$captured) {
            $captured = $log;
        });

        (new MailerLogSubscriber($em, new EmailBodyRedactor()))->onSent($this->makeSentMessageEvent($email));

        self::assertStringNotContainsString($token, $captured->getBody());
        self::assertDoesNotMatchRegularExpression('/[0-9a-fA-F]{64}/', $captured->getBody());
        self::assertStringContainsString('?token=' . EmailBodyRedactor::MASK, $captured->getBody());
        self::assertStringContainsString('<p>Hi</p>', $captured->getBody());
    }

    public function testOnSentFallsBackToTextBodyWhenNoHtmlBody(): void
    {
        $email = (new Email())
            ->from('from@example.com')
            ->to('one@example.com')
            ->subject('Plain text email')
            ->text('Hi there');

        $captured = null;
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($log) use (&$captured) {
            $captured = $log;
        });

        (new MailerLogSubscriber($em, new EmailBodyRedactor()))->onSent($this->makeSentMessageEvent($email));

        self::assertSame('Hi there', $captured->getBody());
    }

    public function testOnSentFallsBackToEmailTemplateCodeWhenSubjectIsBlank(): void
    {
        $email = (new Email())
            ->from('from@example.com')
            ->to('one@example.com')
            ->text('Hi');

        $captured = null;
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($log) use (&$captured) {
            $captured = $log;
        });

        (new MailerLogSubscriber($em, new EmailBodyRedactor()))->onSent($this->makeSentMessageEvent($email));

        self::assertSame('Email', $captured->getTemplateCode());
    }

    public function testOnSentSetsNoneRecipientPlaceholderWhenOnlyBccIsSet(): void
    {
        $email = (new Email())
            ->from('from@example.com')
            ->bcc('hidden@example.com')
            ->subject('Bcc only')
            ->text('Hi');

        $captured = null;
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($log) use (&$captured) {
            $captured = $log;
        });

        (new MailerLogSubscriber($em, new EmailBodyRedactor()))->onSent($this->makeSentMessageEvent($email));

        self::assertSame('(none)', $captured->getRecipient());
    }

    public function testOnSentSkipsWhenOriginalMessageIsNotAnEmail(): void
    {
        $sentMessage = new SentMessage(new RawMessage('raw content'), new Envelope(new Address('from@example.com'), [new Address('to@example.com')]));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        (new MailerLogSubscriber($em, new EmailBodyRedactor()))->onSent(new SentMessageEvent($sentMessage));
    }

    public function testOnFailedPersistsEmailLogWithFailedStatus(): void
    {
        $email = (new Email())
            ->from('from@example.com')
            ->to('one@example.com')
            ->subject('Order confirmation')
            ->html('<p>Body</p>');

        $captured = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')
            ->with(self::callback(function ($log) use (&$captured) {
                $captured = $log;
                return $log instanceof EmailLog;
            }));
        $em->expects(self::once())->method('flush');

        (new MailerLogSubscriber($em, new EmailBodyRedactor()))->onFailed(new FailedMessageEvent($email, new \RuntimeException('smtp down')));

        self::assertSame('Failed', $captured->getStatus());
        self::assertSame('Order confirmation', $captured->getTemplateCode());
        self::assertSame('one@example.com', $captured->getRecipient());
    }

    public function testOnFailedSkipsWhenMessageIsNotAnEmail(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        (new MailerLogSubscriber($em, new EmailBodyRedactor()))->onFailed(new FailedMessageEvent(new RawMessage('raw content'), new \RuntimeException('smtp down')));
    }

    public function testOnSentSwallowsExceptionsFromPersistingTheLog(): void
    {
        $email = (new Email())
            ->from('from@example.com')
            ->to('one@example.com')
            ->subject('Welcome')
            ->text('Hi');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willThrowException(new \RuntimeException('db down'));
        $em->expects(self::never())->method('flush');

        (new MailerLogSubscriber($em, new EmailBodyRedactor()))->onSent($this->makeSentMessageEvent($email));

        $this->addToAssertionCount(1);
    }
}
