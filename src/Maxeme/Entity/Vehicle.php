<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Maxeme\Repository\VehicleRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A client's vehicle (legacy CNSVehiclesBundle Vehicles). Soft-deleted like Client. Ids are the
 * legacy ids (see VehicleImporter).
 */
#[ORM\Entity(repositoryClass: VehicleRepository::class)]
#[ORM\Table(name: 'maxeme_vehicle')]
class Vehicle implements SoftDeletable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Client::class, inversedBy: 'vehicles')]
    #[ORM\JoinColumn(nullable: false)]
    private Client $client;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $manufacturer = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(nullable: true)]
    private ?int $year = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $vin = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $licensePlate = null;

    /** Free text in the legacy app ("120,000 km", "unknown", ...). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $mileage = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $lastUpdated;

    public function __construct(Client $client)
    {
        $this->client = $client;
        $this->lastUpdated = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getClient(): Client { return $this->client; }

    public function getManufacturer(): ?string { return $this->manufacturer; }
    public function setManufacturer(?string $manufacturer): self { $this->manufacturer = $manufacturer; return $this; }

    public function getModel(): ?string { return $this->model; }
    public function setModel(?string $model): self { $this->model = $model; return $this; }

    public function getYear(): ?int { return $this->year; }
    public function setYear(?int $year): self { $this->year = $year; return $this; }

    public function getVin(): ?string { return $this->vin; }
    public function setVin(?string $vin): self { $this->vin = $vin; return $this; }

    public function getLicensePlate(): ?string { return $this->licensePlate; }
    public function setLicensePlate(?string $licensePlate): self { $this->licensePlate = $licensePlate; return $this; }

    public function getMileage(): ?string { return $this->mileage; }
    public function setMileage(?string $mileage): self { $this->mileage = $mileage; return $this; }

    public function getColor(): ?string { return $this->color; }
    public function setColor(?string $color): self { $this->color = $color; return $this; }

    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): self { $this->note = $note; return $this; }

    public function isActive(): bool { return $this->active; }

    public function deactivate(): void
    {
        $this->active = false;
        $this->touch();
    }

    public function getLastUpdated(): \DateTimeImmutable { return $this->lastUpdated; }

    public function touch(?\DateTimeImmutable $at = null): void
    {
        $this->lastUpdated = $at ?? new \DateTimeImmutable();
    }

    /** "Year Make Model", the legacy Vehicles::getFullName(). */
    public function getFullName(): string
    {
        return trim(sprintf('%s %s %s', $this->year, $this->manufacturer, $this->model));
    }
}
