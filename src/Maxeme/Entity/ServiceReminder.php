<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Maxeme\Repository\ServiceReminderRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * How long after a service to remind the customer (Parts & Services › Service Reminders): e.g.
 * Oil change, 90 days after its repair order is completed, with this template and message. One per
 * service. A completed repair order queues it (App\Maxeme\Reminder\ServiceReminderQueue);
 * Emails Sent counts what the queue sent.
 */
#[ORM\Entity(repositoryClass: ServiceReminderRepository::class)]
#[ORM\Table(name: 'maxeme_service_reminder')]
#[ORM\UniqueConstraint(name: 'uniq_maxeme_service_reminder_service', columns: ['service_id'])]
class ServiceReminder implements ReminderRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ServiceItem::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ServiceItem $service;

    #[ORM\Column]
    private int $reminderDays = 0;

    #[ORM\ManyToOne(targetEntity: ServiceReminderTemplate::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ServiceReminderTemplate $template = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $message = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $emailsSent = 0;

    #[ORM\Column]
    private \DateTimeImmutable $lastUpdated;

    public function __construct(ServiceItem $service)
    {
        $this->service = $service;
        $this->lastUpdated = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getService(): ServiceItem { return $this->service; }
    public function setService(ServiceItem $service): self { $this->service = $service; return $this; }

    /** For the form's Service select. */
    public function getServiceId(): ?int { return $this->service->getId(); }

    public function getReminderDays(): int { return $this->reminderDays; }
    public function setReminderDays(int $reminderDays): self { $this->reminderDays = $reminderDays; return $this; }

    public function getTemplate(): ?ServiceReminderTemplate { return $this->template; }
    public function setTemplate(?ServiceReminderTemplate $template): self { $this->template = $template; return $this; }

    /** For the form's Template select. */
    public function getTemplateId(): ?int { return $this->template?->getId(); }

    public function getMessage(): ?string { return $this->message; }
    public function setMessage(?string $message): self { $this->message = $message; return $this; }

    public function dueAfter(\DateTimeImmutable $completedAt): \DateTimeImmutable
    {
        return $completedAt->modify(sprintf('+%d days', $this->reminderDays));
    }

    public function getEmailsSent(): int { return $this->emailsSent; }

    public function recordEmailSent(): void
    {
        ++$this->emailsSent;
    }

    public function getLastUpdated(): \DateTimeImmutable { return $this->lastUpdated; }

    public function touch(): void
    {
        $this->lastUpdated = new \DateTimeImmutable();
    }
}
