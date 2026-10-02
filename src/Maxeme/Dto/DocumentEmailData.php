<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use App\Maxeme\Document\PrintedDocument;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The "Save and Email" page (as Zoho Books has it): who it goes to, who is copied, the subject and
 * the message; the document goes along as a PDF.
 */
final class DocumentEmailData
{
    /** Comma-separated addresses; the first is To. */
    #[Assert\NotBlank(message: 'Enter who the email goes to.')]
    public ?string $to = null;

    /** Comma-separated addresses copied in. */
    public ?string $cc = null;

    #[Assert\NotBlank(message: 'Enter the subject.')]
    #[Assert\Length(max: 200)]
    public ?string $subject = null;

    #[Assert\NotBlank(message: 'Enter the message.')]
    #[Assert\Length(max: 5000)]
    public ?string $message = null;

    /** The page as it first opens: to the customer, the document's own subject, a short message. */
    public static function forDocument(PrintedDocument $document, string $shopName): self
    {
        $data = new self();
        $data->to = $document->email;
        $data->subject = sprintf('%s from %s', $document->emailSubject(), $shopName);
        $data->message = sprintf("Hello %s,\n\nPlease find the attached %s %s.\n\nRegards,\n%s", trim($document->clientName) ?: 'there', strtolower($document->kind->emailTitle()), $document->number, $shopName);

        return $data;
    }

    public static function fromRequest(Request $request): self
    {
        $data = new self();
        foreach (['to', 'cc', 'subject', 'message'] as $field) {
            $value = trim((string) $request->request->get($field, ''));
            $data->{$field} = $value !== '' ? $value : null;
        }

        return $data;
    }
}
