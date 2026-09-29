<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Maxeme\Enum\AppointmentStatus;
use App\Maxeme\Repository\AppointmentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A booked visit of one client's vehicle (legacy CNSScheduleBundle Appointment). Times are stored
 * in UTC and shown in the shop's timezone. Ids are the legacy ids.
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
    #[ORM\JoinColumn(nullable: false)]
    private Vehicle $vehicle;

    #[ORM\Column]
    private \DateTimeImmutable $startTime;

    #[ORM\Column]
    private \DateTimeImmutable $endTime;

    #[ORM\Column(length: 20, enumType: AppointmentStatus::class)]
    private AppointmentStatus $status = AppointmentStatus::New;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    #[ORM\Column]
    private \DateTimeImmutable $lastUpdated;

    public function __construct(Vehicle $vehicle, \DateTimeImmutable $start, \DateTimeImmutable $end)
    {
        $this->client = $vehicle->getClient();
        $this->vehicle = $vehicle;
        $this->lastUpdated = new \DateTimeImmutable();
        $this->reschedule($start, $end);
    }

    public function getId(): ?int { return $this->id; }
    public function getClient(): Client { return $this->client; }
    public function getVehicle(): Vehicle { return $this->vehicle; }
    public function getStartTime(): \DateTimeImmutable { return $this->startTime; }
    public function getEndTime(): \DateTimeImmutable { return $this->endTime; }
    public function getStatus(): AppointmentStatus { return $this->status; }
    public function getNote(): ?string { return $this->note; }
    public function getLastUpdated(): \DateTimeImmutable { return $this->lastUpdated; }

    /** Another of the same client's vehicles. */
    public function changeVehicle(Vehicle $vehicle): void
    {
        if ($vehicle->getClient() !== $this->client) {
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

    /** Set by the invoice when it is paid. */
    public function complete(): void
    {
        $this->status = AppointmentStatus::Complete;
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
