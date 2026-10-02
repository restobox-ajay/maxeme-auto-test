<?php

declare(strict_types=1);

namespace App\Maxeme\Document;

use App\Maxeme\Audit\ActivityRecorder;
use App\Maxeme\Dto\DocumentEmailData;
use App\Maxeme\Entity\Invoice;
use App\Service\AppSettings;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Twig\Environment;

/**
 * "Save and Email PDF invoice" / "Save and Email Work Order PDF" (legacy
 * invoiceSendEmailPdfAction): the document as a PDF attachment, to the first address with the
 * others in copy, from core's configured sender. A PrintedDocument goes with the subject and
 * message typed on its email page (sendDocument()).
 */
final class DocumentMailer
{
    public function __construct(
        private readonly PdfRenderer $pdf,
        private readonly MailerInterface $mailer,
        private readonly AppSettings $appSettings,
        private readonly Environment $twig,
        private readonly ValidatorInterface $validator,
        private readonly LoggerInterface $logger,
        private readonly ActivityRecorder $activity,
        private readonly DocumentNumbers $numbers,
    ) {
    }

    /**
     * @param string $recipients comma-separated addresses, as typed in the Email-Ids field
     *
     * @throws \InvalidArgumentException when the addresses are missing or invalid
     *
     * @return bool whether the email was handed to the mailer
     */
    public function send(Invoice $invoice, DocumentKind $kind, string $recipients): bool
    {
        $addresses = $this->addresses($recipients);

        $email = $this->appSettings->applyFromAddress(new Email(), AppSettings::FROM_SUPPORT)
            ->to(array_shift($addresses))
            ->cc(...$addresses)
            ->subject($this->numbers->emailSubject($invoice, $kind))
            ->html($this->twig->render('maxeme/invoice/email.html.twig', ['kind' => $kind]))
            ->attach($this->pdf->render($invoice, $kind), $this->numbers->filename($invoice, $kind), 'application/pdf');

        if (!$this->deliver($email, $this->numbers->filename($invoice, $kind))) {
            return false;
        }
        $this->activity->emailed($invoice, $kind, self::recipients($email));

        return true;
    }

    /**
     * An invoice, quote or work order, with the subject and message typed on its email page.
     *
     * @param string $entityType / $entityId the record it is filed under in the Activity Log
     *
     * @throws \InvalidArgumentException when an address is missing or invalid
     */
    public function sendDocument(PrintedDocument $document, DocumentEmailData $message, string $entityType, ?int $entityId): bool
    {
        $to = $this->addresses((string) $message->to);
        $cc = $message->cc !== null ? $this->addresses($message->cc) : [];

        $email = $this->appSettings->applyFromAddress(new Email(), AppSettings::FROM_SUPPORT)
            ->to(...$to)
            ->cc(...$cc)
            ->subject((string) $message->subject)
            ->html(nl2br(htmlspecialchars((string) $message->message, \ENT_QUOTES | \ENT_HTML5, 'UTF-8')))
            ->attach($this->pdf->renderDocument($document), $document->filename(), 'application/pdf');

        if (!$this->deliver($email, $document->filename())) {
            return false;
        }
        $this->activity->emailedDocument($document, $entityType, $entityId, self::recipients($email));

        return true;
    }

    private function deliver(Email $email, string $filename): bool
    {
        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Could not email {document}.', ['document' => $filename, 'exception' => $exception]);

            return false;
        }

        return true;
    }

    private static function recipients(Email $email): string
    {
        return implode(', ', array_map(static fn (Address $address): string => $address->getAddress(), [...$email->getTo(), ...$email->getCc()]));
    }

    /** @return non-empty-list<Address> */
    private function addresses(string $recipients): array
    {
        $list = array_values(array_filter(array_map('trim', explode(',', $recipients)), static fn (string $address): bool => $address !== ''));
        if ($list === []) {
            throw new \InvalidArgumentException('This field is required.');
        }

        foreach ($list as $address) {
            if (count($this->validator->validate($address, new Assert\Email())) > 0) {
                throw new \InvalidArgumentException('Please provide valid email-Ids.');
            }
        }

        return array_map(static fn (string $address): Address => new Address($address), $list);
    }
}
