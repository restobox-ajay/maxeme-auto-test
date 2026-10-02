<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Entity\AbstractPartyNote;
use App\Entity\AdminUser;
use App\Maxeme\Enum\AppointmentStatus;
use App\Maxeme\Enum\RepairOrderStatus;
use App\Maxeme\Repository\RepairOrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A repair order: the shop's main record of one visit's work. Everything starts here; its
 * appointments schedule it, its services (with their lines) say what is done, custom fees and
 * discounts sit under the subtotal, and its quote, invoices and work order are printed from it.
 *
 * Every field is optional (a caller may only report a strange noise). The totals are stored,
 * recalculated by RepairOrderCalculator on every save; GST and PST rates are copied from
 * Settings › Tax Rates when it is created, so a later rate change does not alter it.
 */
#[ORM\Entity(repositoryClass: RepairOrderRepository::class)]
#[ORM\Table(name: 'maxeme_repair_order')]
#[ORM\Index(name: 'idx_maxeme_repair_order_status', columns: ['status'])]
class RepairOrder implements HasNotes
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 24, enumType: RepairOrderStatus::class)]
    private RepairOrderStatus $status = RepairOrderStatus::EstimateBeingBuilt;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Client $client = null;

    #[ORM\ManyToOne(targetEntity: Vehicle::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Vehicle $vehicle = null;

    /** The odometer when the car came in, as typed. */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $mileage = null;

    /** The service advisor: any admin user (the one who started it, by default). */
    #[ORM\ManyToOne(targetEntity: AdminUser::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?AdminUser $advisor = null;

    /** The key tag, free text. */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $tagKey = null;

    /** Printed at the top of the work order (Settings › Technicians). */
    #[ORM\ManyToOne(targetEntity: Technician::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Technician $masterTechnician = null;

    /** The admin's short description: used internally, on the calendar and the repair order list. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    /** The customer's own words, as reception typed them for the technician. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $concern = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $gstRate = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $pstRate = 0;

    /** Σ service prices + their charge-through lines. */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $subtotal = '0.00';

    /** Σ custom fees − Σ custom discounts. */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $chargeTotal = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $gst = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $pst = '0.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $total = '0.00';

    #[ORM\Column]
    private \DateTimeImmutable $createdOn;

    #[ORM\Column]
    private \DateTimeImmutable $lastUpdated;

    /** @var Collection<int, RepairOrderJob> */
    #[ORM\OneToMany(targetEntity: RepairOrderJob::class, mappedBy: 'repairOrder', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $jobs;

    /** @var Collection<int, RepairOrderCharge> */
    #[ORM\OneToMany(targetEntity: RepairOrderCharge::class, mappedBy: 'repairOrder', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $charges;

    /** @var Collection<int, RepairOrderNote> */
    #[ORM\OneToMany(targetEntity: RepairOrderNote::class, mappedBy: 'repairOrder')]
    #[ORM\OrderBy(['createdAt' => 'DESC', 'id' => 'DESC'])]
    private Collection $notes;

    /** @var Collection<int, Appointment> */
    #[ORM\OneToMany(targetEntity: Appointment::class, mappedBy: 'repairOrder')]
    #[ORM\OrderBy(['startTime' => 'ASC', 'id' => 'ASC'])]
    private Collection $appointments;

    public function __construct(int $gstRate, int $pstRate)
    {
        $this->gstRate = $gstRate;
        $this->pstRate = $pstRate;
        $this->createdOn = new \DateTimeImmutable();
        $this->lastUpdated = $this->createdOn;
        $this->jobs = new ArrayCollection();
        $this->charges = new ArrayCollection();
        $this->notes = new ArrayCollection();
        $this->appointments = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getStatus(): RepairOrderStatus { return $this->status; }
    /** Once the work is done, every appointment of it that was not called off is completed. */
    public function setStatus(RepairOrderStatus $status): self
    {
        $this->status = $status;
        if ($status->isWorkDone()) {
            foreach ($this->appointments as $appointment) {
                if (!$appointment->getStatus()->isCalledOff() && $appointment->getStatus() !== AppointmentStatus::Complete) {
                    $appointment->complete();
                }
            }
        }

        return $this;
    }

    public function getClient(): ?Client { return $this->client; }
    public function getVehicle(): ?Vehicle { return $this->vehicle; }

    /** The customer, and one of their vehicles (or none). */
    public function setCustomer(?Client $client, ?Vehicle $vehicle): self
    {
        if ($vehicle !== null && $vehicle->getClient() !== $client) {
            throw new \DomainException('The vehicle belongs to another client.');
        }
        $this->client = $client;
        $this->vehicle = $vehicle;

        return $this;
    }

    /** The form's ids (RepairOrderData). */
    public function getClientId(): ?int { return $this->client?->getId(); }
    public function getVehicleId(): ?int { return $this->vehicle?->getId(); }
    public function getAdvisorId(): ?int { return $this->advisor?->getId(); }
    public function getMasterTechnicianId(): ?int { return $this->masterTechnician?->getId(); }

    public function getMileage(): ?string { return $this->mileage; }
    public function setMileage(?string $mileage): self { $this->mileage = $mileage; return $this; }

    public function getAdvisor(): ?AdminUser { return $this->advisor; }
    public function setAdvisor(?AdminUser $advisor): self { $this->advisor = $advisor; return $this; }

    public function getTagKey(): ?string { return $this->tagKey; }
    public function setTagKey(?string $tagKey): self { $this->tagKey = $tagKey; return $this; }

    public function getMasterTechnician(): ?Technician { return $this->masterTechnician; }
    public function setMasterTechnician(?Technician $technician): self { $this->masterTechnician = $technician; return $this; }

    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): self { $this->name = $name; return $this; }

    public function getConcern(): ?string { return $this->concern; }
    public function setConcern(?string $concern): self { $this->concern = $concern; return $this; }

    public function getGstRate(): int { return $this->gstRate; }
    public function getPstRate(): int { return $this->pstRate; }

    public function getSubtotal(): string { return $this->subtotal; }
    public function getChargeTotal(): string { return $this->chargeTotal; }
    public function getGst(): string { return $this->gst; }
    public function getPst(): string { return $this->pst; }
    public function getTotal(): string { return $this->total; }

    /** Stores RepairOrderCalculator's result. */
    public function setTotals(string $subtotal, string $chargeTotal, string $gst, string $pst, string $total): void
    {
        $this->subtotal = $subtotal;
        $this->chargeTotal = $chargeTotal;
        $this->gst = $gst;
        $this->pst = $pst;
        $this->total = $total;
    }

    public function getCreatedOn(): \DateTimeImmutable { return $this->createdOn; }
    public function getLastUpdated(): \DateTimeImmutable { return $this->lastUpdated; }

    public function touch(): void
    {
        $this->lastUpdated = new \DateTimeImmutable();
    }

    /** @return list<RepairOrderJob> in order */
    public function getJobs(): array { return array_values($this->jobs->toArray()); }

    /** @param list<RepairOrderJob> $jobs this repair order's, in order; one left out is deleted */
    public function replaceJobs(array $jobs): void
    {
        self::replace($this->jobs, $jobs, $this);
    }

    /** @return list<RepairOrderCharge> in order */
    public function getCharges(): array { return array_values($this->charges->toArray()); }

    /** @param list<RepairOrderCharge> $charges this repair order's, in order; one left out is deleted */
    public function replaceCharges(array $charges): void
    {
        self::replace($this->charges, $charges, $this);
    }

    /** @return Collection<int, RepairOrderNote> newest first */
    public function getNotes(): Collection { return $this->notes; }

    public function newNote(): AbstractPartyNote
    {
        return new RepairOrderNote($this);
    }

    /** @return list<Appointment> by start time */
    public function getAppointments(): array { return array_values($this->appointments->toArray()); }

    /**
     * @template T of RepairOrderJob|RepairOrderCharge
     *
     * @param Collection<int, T> $collection
     * @param list<T>            $items
     */
    private static function replace(Collection $collection, array $items, self $owner): void
    {
        foreach ($collection as $item) {
            if (!in_array($item, $items, true)) {
                $collection->removeElement($item);
            }
        }
        foreach ($items as $position => $item) {
            if ($item->getRepairOrder() !== $owner) {
                throw new \InvalidArgumentException('A line of another repair order cannot be added.');
            }
            $item->setPosition($position);
            if (!$collection->contains($item)) {
                $collection->add($item);
            }
        }
    }
}
