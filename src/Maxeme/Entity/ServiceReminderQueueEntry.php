<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

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
        $this->lastUpdated = new \DateTimeImmutable();
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
    public function markSent(string $email): void
    {
        $this->email = $email;
        $this->sentAt = new \DateTimeImmutable();
        $this->error = null;
        $this->changeStatus(ServiceReminderQueueStatus::Sent);
    }

    public function markError(string $error): void
    {
        $this->error = $error;
        $this->changeStatus(ServiceReminderQueueStatus::Error);
    }

    public function changeStatus(ServiceReminderQueueStatus $status): void
    {
        $this->status = $status;
        $this->lastUpdated = new \DateTimeImmutable();
    }
}
