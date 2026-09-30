<?php

declare(strict_types=1);

namespace App\Maxeme\Document;

use App\Maxeme\Audit\ActivityRecorder;
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
 * others in copy, from core's configured sender.
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
            ->subject($kind->emailSubject($invoice))
            ->html($this->twig->render('maxeme/invoice/email.html.twig', ['kind' => $kind]))
            ->attach($this->pdf->render($invoice, $kind), $kind->filename($invoice), 'application/pdf');

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Could not email {document}.', ['document' => $kind->filename($invoice), 'exception' => $exception]);

            return false;
        }

        $this->activity->emailed($invoice, $kind, implode(', ', array_map(static fn (Address $address): string => $address->getAddress(), [...$email->getTo(), ...$email->getCc()])));

        return true;
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
