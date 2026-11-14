<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Maxeme\Enum\PhoneStatus;
use App\Maxeme\Enum\ServiceReminderQueueStatus;
use App\Maxeme\Repository\ServiceReminderQueueRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One service reminder email for a completed repair order (App\Maxeme\Service\ServiceReminderQueue):
 * the service that triggered it, its reminder days and the day it is due, the email address it was
 * (or will be) sent to, and where it stands (queued, sent, skipped, error, booked, resolved).
 */
#[ORM\Entity(repositoryClass: ServiceReminderQueueRepository::class)]
#[ORM\Table(name: 'maxeme_service_reminder_queue')]
#[ORM\Index(name: 'idx_mx_reminder_queue_status_due', columns: ['status', 'due_at'])]
#[ORM\Index(name: 'idx_mx_reminder_queue_client_vehicle', columns: ['client_id', 'vehicle_id'])]
class ServiceReminderQueueEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: ServiceReminderQueueStatus::class)]
    private ServiceReminderQueueStatus $status;

    #[ORM\ManyToOne(targetEntity: RepairOrder::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?RepairOrder $repairOrder;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Client $client;

    #[ORM\ManyToOne(targetEntity: Vehicle::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Vehicle $vehicle;

    /** The rule it came from: its template and message are used when it is sent. */
    #[ORM\ManyToOne(targetEntity: ServiceReminder::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ServiceReminder $rule;

    #[ORM\ManyToOne(targetEntity: ServiceItem::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ServiceItem $service;

    /** The service's name as it was on the repair order. */
    #[ORM\Column(length: 255)]
    private string $serviceName;

    #[ORM\Column]
    private int $reminderDays;

    /** The repair order's completion date (when it was queued). */
    #[ORM\Column]
    private \DateTimeImmutable $queuedAt;

    /** queuedAt + reminderDays: sent on or after this. */
    #[ORM\Column]
    private \DateTimeImmutable $dueAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email;

    /** Why it could not be sent (status error). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    #[ORM\Column]
    private \DateTimeImmutable $lastUpdated;

    /** How the call went (mockup aa322bb). */
    #[ORM\Column(length: 20, enumType: PhoneStatus::class, options: ['default' => 'not_called'])]
    private PhoneStatus $phoneStatus = PhoneStatus::NotCalled;

    /** Its own template and message, copied from the service reminder when queued (it may be deleted later). */
    #[ORM\ManyToOne(targetEntity: ServiceReminderTemplate::class)]
    #[ORM\JoinColumn(name: 'template_id', nullable: true, onDelete: 'SET NULL')]
    private ?ServiceReminderTemplate $template = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $message = null;

    /** The email that went out (the last one sent), shown by View email. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sentSubject = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $sentBody = null;

    public function __construct(RepairOrder $repairOrder, Client $client, Vehicle $vehicle, ServiceReminder $rule, string $serviceName, ServiceReminderQueueStatus $status, \DateTimeImmutable $queuedAt)
    {
        $this->repairOrder = $repairOrder;
        $this->client = $client;
        $this->vehicle = $vehicle;
        $this->rule = $rule;
        $this->service = $rule->getService();
        $this->serviceName = $serviceName;
        $this->reminderDays = $rule->getReminderDays();
        $this->status = $status;
        $this->queuedAt = $queuedAt;
        $this->dueAt = $rule->dueAfter($queuedAt);
        $this->email = $client->getEmail() ?: null;
        $this->template = $rule->getTemplate();
        $this->message = $rule->getMessage();
        $this->lastUpdated = new \DateTimeImmutable();
    }

    public function getPhoneStatus(): PhoneStatus { return $this->phoneStatus; }
    public function getTemplate(): ?ServiceReminderTemplate { return $this->template; }
    public function getMessage(): ?string { return $this->message; }
    public function getSentSubject(): ?string { return $this->sentSubject; }
    public function getSentBody(): ?string { return $this->sentBody; }

    public function setPhoneStatus(PhoneStatus $status): void
    {
        $this->phoneStatus = $status;
        $this->lastUpdated = new \DateTimeImmutable();
    }

    /** Skipped when queued, with the reason shown in its Email Status (audit fix 34181b0). */
    public function skip(string $reason): void
    {
        $this->error = $reason;
        $this->changeStatus(ServiceReminderQueueStatus::Skipped);
    }

    /** Booked or Resolved: dealt with; sending it again keeps that status. */
    public function isClosed(): bool
    {
        return in_array($this->status, [ServiceReminderQueueStatus::Booked, ServiceReminderQueueStatus::Resolved], true);
    }

    /** Send now / Resend / Resolved are offered (audit fix 34181b0): queued, sent or in error. */
    public function isLive(): bool
    {
        return in_array($this->status, [ServiceReminderQueueStatus::Queued, ServiceReminderQueueStatus::Sent, ServiceReminderQueueStatus::Error], true);
    }

    public function getId(): ?int { return $this->id; }
    public function getStatus(): ServiceReminderQueueStatus { return $this->status; }
    public function getRepairOrder(): ?RepairOrder { return $this->repairOrder; }
    public function getClient(): Client { return $this->client; }
    public function getVehicle(): Vehicle { return $this->vehicle; }
    public function getRule(): ?ServiceReminder { return $this->rule; }
    public function getService(): ?ServiceItem { return $this->service; }
    public function getServiceName(): string { return $this->serviceName; }
    public function getReminderDays(): int { return $this->reminderDays; }
    public function getQueuedAt(): \DateTimeImmutable { return $this->queuedAt; }
    public function getDueAt(): \DateTimeImmutable { return $this->dueAt; }
    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }
    public function getEmail(): ?string { return $this->email; }
    public function getError(): ?string { return $this->error; }
    public function getLastUpdated(): \DateTimeImmutable { return $this->lastUpdated; }

    /** Sent to $email (the client's address at the time, which may have changed since it was queued). */
    public function markSent(string $email, string $subject, string $body): void
    {
        $this->email = $email;
        $this->sentAt = new \DateTimeImmutable();
        $this->sentSubject = mb_substr($subject, 0, 255);
        $this->sentBody = $body;
        // A Booked or Resolved reminder sent again stays Booked / Resolved.
        if (!$this->isClosed()) {
            $this->error = null;
            $this->changeStatus(ServiceReminderQueueStatus::Sent);
        } else {
            $this->lastUpdated = new \DateTimeImmutable();
        }
    }

    public function markError(string $error): void
    {
        if ($this->isClosed()) {
            return;
        }
        $this->error = $error;
        $this->changeStatus(ServiceReminderQueueStatus::Error);
    }

    public function changeStatus(ServiceReminderQueueStatus $status): void
    {
        $this->status = $status;
        $this->lastUpdated = new \DateTimeImmutable();
    }
}
