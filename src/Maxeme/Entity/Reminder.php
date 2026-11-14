<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Maxeme\Enum\PhoneStatus;
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

    /** How the call went (mockup aa322bb). */
    #[ORM\Column(length: 20, enumType: PhoneStatus::class, options: ['default' => 'not_called'])]
    private PhoneStatus $phoneStatus = PhoneStatus::NotCalled;

    /** Delayed: the reason given, and when; the reminder's own note stays as it is (mockup 1b7f972). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $delayNote = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $delayedAt = null;

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

    public function getPhoneStatus(): PhoneStatus { return $this->phoneStatus; }
    public function getDelayNote(): ?string { return $this->delayNote; }
    public function getDelayedAt(): ?\DateTimeImmutable { return $this->delayedAt; }
    public function getCreatedOn(): \DateTimeImmutable { return $this->createdOn; }

    public function setPhoneStatus(PhoneStatus $status): void
    {
        $this->phoneStatus = $status;
        $this->lastModified = new \DateTimeImmutable();
    }

    /**
     * Resolved / Delayed / Declined, only while it is open. Delayed needs a reason, kept apart from the
     * reminder's note (mockup 1b7f972).
     *
     * @throws \DomainException
     */
    public function changeStatus(ReminderStatus $status, ?string $delayReason = null): void
    {
        if (!$this->status->isOpen()) {
            throw new \DomainException(sprintf('This reminder is %s already.', strtolower($this->status->label())));
        }
        if ($status === ReminderStatus::Delayed) {
            if ($delayReason === null || trim($delayReason) === '') {
                throw new \DomainException('Enter the reason for the delay.');
            }
            $this->delayNote = trim($delayReason);
            $this->delayedAt = new \DateTimeImmutable();
        }
        $this->status = $status;
        $this->lastModified = new \DateTimeImmutable();
    }
}
