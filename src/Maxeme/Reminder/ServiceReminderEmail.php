<?php

declare(strict_types=1);

namespace App\Maxeme\Reminder;

use App\Maxeme\Audit\ActivityRecorder;
use App\Maxeme\Entity\ServiceReminderQueueEntry;
use App\Maxeme\Entity\ServiceReminderTemplate;
use App\Maxeme\Enum\ServiceReminderQueueStatus;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * A queued service reminder as an email: its Service Reminder's template with the TAGS filled in
 * (ServiceReminderTemplate::TAGS), or a plain note with the message when the rule has no template.
 * send() delivers it to the client's current email address and records the outcome on the entry:
 * Sent (and the rule's Emails Sent count), or Error with the reason.
 */
final class ServiceReminderEmail
{
    /** @param array{phone: string, booking_url: string} $company */
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly AppSettings $appSettings,
        private readonly ValidatorInterface $validator,
        private readonly EntityManagerInterface $entityManager,
        private readonly ActivityRecorder $activity,
        private readonly LoggerInterface $logger,
        #[Autowire(param: 'maxeme.company')]
        private readonly array $company,
    ) {
    }

    /** @return array{subject: string, html: string} */
    public function render(ServiceReminderQueueEntry $entry): array
    {
        $rule = $entry->getRule();
        $message = nl2br(self::escape((string) $rule?->getMessage()));
        $template = $rule?->getTemplate();
        if (!$template instanceof ServiceReminderTemplate) {
            return [
                'subject' => sprintf('Time for your %s', $entry->getServiceName()),
                'html' => sprintf('<p>Hi %s,</p><p>%s</p>', self::escape($entry->getClient()->getFullName()), $message !== '' ? $message : sprintf('Your %s is due for its %s. Call us at %s to book.', self::escape($entry->getVehicle()->getFullName()), self::escape($entry->getServiceName()), self::escape($this->company['phone']))),
            ];
        }

        $values = [
            '{{car model}}' => $entry->getVehicle()->getFullName(),
            '{{client name}}' => $entry->getClient()->getFullName(),
            '{{service}}' => $entry->getServiceName(),
            '{{shop phone}}' => $this->company['phone'],
            '{{booking link}}' => $this->company['booking_url'],
        ];

        return [
            'subject' => strtr($template->getSubject(), $values + ['{{message}}' => (string) $rule?->getMessage()]),
            'html' => strtr($template->getBody(), array_map(self::escape(...), $values) + ['{{message}}' => $message]),
        ];
    }

    /** Sends (or re-sends) the reminder now; the caller flushes. */
    public function send(ServiceReminderQueueEntry $entry): bool
    {
        $address = trim((string) $entry->getClient()->getEmail());
        if ($address === '' || count($this->validator->validate($address, new Assert\Email())) > 0) {
            $entry->markError($address === '' ? 'The client has no email address.' : sprintf('"%s" is not a valid email address.', $address));

            return false;
        }

        $content = $this->render($entry);
        $email = $this->appSettings->applyFromAddress(new Email(), AppSettings::FROM_SUPPORT)
            ->to($address)
            ->subject($content['subject'])
            ->html($content['html']);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Could not send service reminder {id}.', ['id' => $entry->getId(), 'exception' => $exception]);
            $entry->markError('The mailer failed: ' . $exception->getMessage());

            return false;
        }

        $resend = $entry->getStatus() === ServiceReminderQueueStatus::Sent;
        $entry->markSent($address);
        $entry->getRule()?->recordEmailSent();
        $this->entityManager->flush();
        $this->activity->reminderEmailed($entry->getId(), sprintf('%s service reminder "%s" to %s.', $resend ? 'Re-sent' : 'Sent', $entry->getServiceName(), $address));

        return true;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }
}
