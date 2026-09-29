<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Maxeme\Enum\ReminderStatus;
use App\Maxeme\Repository\ReminderRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A follow-up to call a client back about a vehicle last seen months ago (legacy Reminder).
 * Created by App\Maxeme\Service\ReminderGenerator. Ids are the legacy ids.
 */
#[ORM\Entity(repositoryClass: ReminderRepository::class)]
#[ORM\Table(name: 'maxeme_reminder')]
class Reminder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: ReminderStatus::class)]
    private ReminderStatus $status = ReminderStatus::New;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note;

    #[ORM\Column]
    private \DateTimeImmutable $createdOn;

    #[ORM\Column]
    private \DateTimeImmutable $lastModified;

    /** The day it came due. */
    #[ORM\Column]
    private \DateTimeImmutable $reminderDate;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Client::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Client $client,
        #[ORM\ManyToOne(targetEntity: Vehicle::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Vehicle $vehicle,
        /** The last visit's invoice ("Previous Appointment"). */
        #[ORM\ManyToOne(targetEntity: Invoice::class)]
        #[ORM\JoinColumn(onDelete: 'SET NULL')]
        private ?Invoice $invoice,
        ?string $note,
        \DateTimeImmutable $reminderDate,
    ) {
        $this->note = $note;
        $this->createdOn = new \DateTimeImmutable();
        $this->lastModified = $this->createdOn;
        $this->reminderDate = $reminderDate;
    }

    public function getId(): ?int { return $this->id; }
    public function getClient(): Client { return $this->client; }
    public function getVehicle(): Vehicle { return $this->vehicle; }
    public function getInvoice(): ?Invoice { return $this->invoice; }
    public function getStatus(): ReminderStatus { return $this->status; }
    public function getNote(): ?string { return $this->note; }
    public function getReminderDate(): \DateTimeImmutable { return $this->reminderDate; }

    /** Resolved / Delayed / Declined; a note, when given, replaces the reminder note (legacy updateReminderAction). */
    public function changeStatus(ReminderStatus $status, ?string $note = null): void
    {
        $this->status = $status;
        if ($note !== null && $note !== '') {
            $this->note = $note;
        }
        $this->lastModified = new \DateTimeImmutable();
    }
}
