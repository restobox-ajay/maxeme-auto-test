<?php

namespace App\EventSubscriber;

use App\Entity\EmailLog;
use App\Service\EmailBodyRedactor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mime\Email;

final class MailerLogSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EmailBodyRedactor $bodyRedactor,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SentMessageEvent::class => 'onSent',
            FailedMessageEvent::class => 'onFailed',
        ];
    }

    public function onSent(SentMessageEvent $event): void
    {
        $original = $event->getMessage()->getOriginalMessage();
        if (!$original instanceof Email) {
            return;
        }

        $this->persistLog($original, 'Sent');
    }

    public function onFailed(FailedMessageEvent $event): void
    {
        $message = $event->getMessage();
        if (!$message instanceof Email) {
            return;
        }

        $this->persistLog($message, 'Failed');
    }

    private function persistLog(Email $email, string $status): void
    {
        try {
            $to = array_map(static fn($addr) => $addr->getAddress(), $email->getTo());
            $recipient = $to !== [] ? implode(', ', $to) : '(none)';
            $subject = trim((string) $email->getSubject());
            $templateCode = $subject !== '' ? $subject : 'Email';
            $body = $email->getHtmlBody() ?: $email->getTextBody();
            // Password-reset / account-setup mails carry a live token in their URL; it must never
            // reach the (admin-readable, LIKE-searchable) email_log table.
            if (is_string($body)) {
                $body = $this->bodyRedactor->redact($body);
            }

            $log = (new EmailLog())
                ->setTemplateCode($templateCode)
                ->setRecipient($recipient)
                ->setStatus($status)
                ->setBody($body);

            $this->entityManager->persist($log);
            $this->entityManager->flush();
        } catch (\Throwable) {
            // Never break email delivery due to logging failures.
        }
    }
}
