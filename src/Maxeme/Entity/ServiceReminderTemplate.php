<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A service reminder email: a friendly note asking the customer to call in (tap to call) or book
 * on the website (tap to the form). A ServiceReminder picks one; its TAGS are filled in when the
 * reminder goes out (sending is not built yet): {{message}} is the Service Reminder's own Message.
 */
#[ORM\Entity]
#[ORM\Table(name: 'maxeme_service_reminder_template')]
class ServiceReminderTemplate
{
    /** Tag => what it is replaced with (shown beside the body editor). */
    public const TAGS = [
        '{{message}}' => "The Service Reminder's message",
        '{{car model}}' => 'The vehicle, e.g. "2016 Honda Civic"',
        '{{client name}}' => "The client's name",
        '{{service}}' => 'The service, e.g. "Oil change"',
        '{{shop phone}}' => 'The shop\'s phone number. Tap to call: <a href="tel:{{shop phone}}">{{shop phone}}</a>',
        '{{booking link}}' => 'The website\'s booking form. Tap to book: <a href="{{booking link}}">Book online</a>',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(length: 255)]
    private string $subject = '';

    /** HTML, with TAGS. */
    #[ORM\Column(type: 'text')]
    private string $body = '';

    #[ORM\Column]
    private \DateTimeImmutable $lastUpdated;

    public function __construct()
    {
        $this->lastUpdated = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getSubject(): string { return $this->subject; }
    public function setSubject(string $subject): self { $this->subject = $subject; return $this; }

    public function getBody(): string { return $this->body; }
    public function setBody(string $body): self { $this->body = $body; return $this; }

    public function getLastUpdated(): \DateTimeImmutable { return $this->lastUpdated; }

    public function touch(): void
    {
        $this->lastUpdated = new \DateTimeImmutable();
    }
}
