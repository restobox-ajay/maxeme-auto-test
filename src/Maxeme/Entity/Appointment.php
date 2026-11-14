<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Entity\AdminUser;
use App\Maxeme\Enum\AppointmentStatus;
use App\Maxeme\Enum\AppointmentType;
use App\Maxeme\Repository\AppointmentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A booked visit of one client, with one of their vehicles (legacy CNSScheduleBundle Appointment);
 * Buying/selling cars and Other appointments may have no vehicle. Times are stored in UTC and shown
 * in the shop's timezone. Ids are the legacy ids.
 */
#[ORM\Entity(repositoryClass: AppointmentRepository::class)]
#[ORM\Table(name: 'maxeme_appointment')]
#[ORM\Index(name: 'idx_maxeme_appointment_start', columns: ['start_time'])]
class Appointment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Client $client;

    #[ORM\ManyToOne(targetEntity: Vehicle::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Vehicle $vehicle;

    #[ORM\Column(name: 'appt_type', length: 20, enumType: AppointmentType::class, options: ['default' => 'repair'])]
    private AppointmentType $type = AppointmentType::Repair;

    #[ORM\Column]
    private \DateTimeImmutable $startTime;

    #[ORM\Column]
    private \DateTimeImmutable $endTime;

    #[ORM\Column(length: 20, enumType: AppointmentStatus::class)]
    private AppointmentStatus $status = AppointmentStatus::New;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    /** The repair order it schedules (a repair order can have many); null for a legacy one without. */
    #[ORM\ManyToOne(targetEntity: RepairOrder::class, inversedBy: 'appointments')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?RepairOrder $repairOrder = null;

    /** The assigned technician (mockup 986d771): the same as its repair order's, or its own for a direct booking. */
    #[ORM\ManyToOne(targetEntity: AdminUser::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AdminUser $technician = null;

    /** A legacy free calendar colour; no longer used (the type decides the colour, mockup 689a4a1). */
    #[ORM\Column(length: 7, nullable: true)]
    private ?string $colour = null;

    /** When the shop promised the vehicle back, in UTC. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $promisedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $lastUpdated;

    public function __construct(Client $client, ?Vehicle $vehicle, \DateTimeImmutable $start, \DateTimeImmutable $end)
    {
        if ($vehicle !== null && $vehicle->getClient() !== $client) {
            throw new \DomainException('The vehicle belongs to another client.');
        }
        $this->client = $client;
        $this->vehicle = $vehicle;
        $this->lastUpdated = new \DateTimeImmutable();
        $this->reschedule($start, $end);
    }

    public function getId(): ?int { return $this->id; }
    public function getClient(): Client { return $this->client; }
    public function getVehicle(): ?Vehicle { return $this->vehicle; }
    public function getType(): AppointmentType { return $this->type; }
    public function getStartTime(): \DateTimeImmutable { return $this->startTime; }
    public function getEndTime(): \DateTimeImmutable { return $this->endTime; }
    public function getStatus(): AppointmentStatus { return $this->status; }
    public function getNote(): ?string { return $this->note; }
    public function getLastUpdated(): \DateTimeImmutable { return $this->lastUpdated; }
    public function getRepairOrder(): ?RepairOrder { return $this->repairOrder; }
    public function getPromisedAt(): ?\DateTimeImmutable { return $this->promisedAt; }
    public function getColour(): ?string { return $this->colour; }
    public function getTechnician(): ?AdminUser { return $this->technician; }

    /** Its own technician, else its repair order's: the one shown on the calendar and the lists. */
    public function getShownTechnician(): ?AdminUser
    {
        return $this->technician ?? $this->repairOrder?->getMasterTechnician();
    }

    public function setTechnician(?AdminUser $technician): void
    {
        if ($technician !== $this->technician) {
            $this->technician = $technician;
            $this->touch();
        }
    }

    /** A Bodywork, Buying/selling or Other appointment schedules no repair order. */
    public function setType(AppointmentType $type): void
    {
        $this->type = $type;
        if (!$type->hasRepairOrder()) {
            $this->repairOrder = null;
        }
        $this->touch();
    }

    /**
     * The calendar colour: a Bodywork, Buying/selling or Other appointment's own, always; null for a
     * repair appointment, which takes its status colour. What is on the repair order never changes
     * it (mockup 689a4a1, thread 13225).
     */
    public function getCalendarColour(): ?string
    {
        return $this->type->colour();
    }

    /** Booked from a repair order: it schedules that repair order's vehicle. */
    public function attachTo(RepairOrder $repairOrder): void
    {
        if (!$this->type->hasRepairOrder()) {
            throw new \DomainException(sprintf('A %s appointment has no repair order.', $this->type->label()));
        }
        if ($repairOrder->getVehicle() !== $this->vehicle) {
            throw new \DomainException('The appointment is for another vehicle than the repair order.');
        }
        $this->repairOrder = $repairOrder;
        $this->touch();
    }

    public function setPromisedAt(?\DateTimeImmutable $promisedAt): void
    {
        $this->promisedAt = $promisedAt;
        $this->touch();
    }

    /** Started before now: shown, not edited, on its repair order. */
    public function isPast(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        return $this->startTime < $now;
    }

    /** Another of the same client's vehicles, or none (Buying/selling cars, Other). */
    public function changeVehicle(?Vehicle $vehicle): void
    {
        if ($vehicle !== null && $vehicle->getClient() !== $this->client) {
            throw new \DomainException('The vehicle belongs to another client.');
        }
        $this->vehicle = $vehicle;
        $this->touch();
    }

    public function reschedule(\DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        if ($end <= $start) {
            throw new \DomainException('The appointment must end after it starts.');
        }
        $this->startTime = $start;
        $this->endTime = $end;
        $this->touch();
    }

    public function setNote(?string $note): void
    {
        $this->note = $note;
        $this->touch();
    }

    /** The calendar's Check-in: a new appointment is now being worked on. */
    public function checkIn(): void
    {
        if ($this->status !== AppointmentStatus::New) {
            throw new \DomainException('Only a new appointment can be checked in.');
        }
        $this->status = AppointmentStatus::InProgress;
        $this->touch();
    }

    /** Set by the invoice when it is paid, and when its repair order's work is completed. */
    public function complete(): void
    {
        $this->status = AppointmentStatus::Complete;
        $this->touch();
    }

    /** Set by hand on the appointment page. */
    public function setStatus(AppointmentStatus $status): void
    {
        $this->status = $status;
        $this->touch();
    }

    /** No longer schedules a repair order (the appointment page's Repair Order set to none). */
    public function detachRepairOrder(): void
    {
        $this->repairOrder = null;
        $this->touch();
    }

    /** Set by the invoice when it is saved unpaid: the work is under way (legacy save). */
    public function reopen(): void
    {
        $this->status = AppointmentStatus::InProgress;
        $this->touch();
    }

    /** Length in hours, e.g. 1.5 (legacy getDurationInHour()). */
    public function getDurationInHours(): float
    {
        return ($this->endTime->getTimestamp() - $this->startTime->getTimestamp()) / 3600;
    }

    private function touch(): void
    {
        $this->lastUpdated = new \DateTimeImmutable();
    }
}
