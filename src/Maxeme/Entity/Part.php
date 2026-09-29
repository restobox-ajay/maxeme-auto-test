<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Maxeme\Enum\PartType;
use App\Maxeme\Repository\PartRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A stocked part (legacy CNSInventoryBundle Parts). Soft-deleted. Ids are the legacy ids.
 *
 * The quantity only moves through App\Maxeme\Service\StockLedger, so every change leaves an
 * InventoryHistory row.
 */
#[ORM\Entity(repositoryClass: PartRepository::class)]
#[ORM\Table(name: 'maxeme_part')]
class Part implements SoftDeletable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** "VIN #" in the legacy grid: in practice the part number. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $vin = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $manufacturer = null;

    #[ORM\Column(length: 20, nullable: true, enumType: PartType::class)]
    private ?PartType $type = PartType::Unit;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $vendor = null;

    /** Cost, in dollars (CDN). */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $unitPrice = null;

    /** Price charged, in dollars (CDN). */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $salePrice = null;

    /** Units in stock; negative when more were sold than received. */
    #[ORM\Column(options: ['default' => 0])]
    private int $quantity = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    public function getId(): ?int { return $this->id; }

    public function getVin(): ?string { return $this->vin; }
    public function setVin(?string $vin): self { $this->vin = $vin; return $this; }

    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): self { $this->name = $name; return $this; }

    public function getManufacturer(): ?string { return $this->manufacturer; }
    public function setManufacturer(?string $manufacturer): self { $this->manufacturer = $manufacturer; return $this; }

    public function getType(): ?PartType { return $this->type; }
    public function setType(?PartType $type): self { $this->type = $type; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): self { $this->description = $description; return $this; }

    public function getVendor(): ?string { return $this->vendor; }
    public function setVendor(?string $vendor): self { $this->vendor = $vendor; return $this; }

    public function getUnitPrice(): ?string { return $this->unitPrice; }
    public function setUnitPrice(?string $unitPrice): self { $this->unitPrice = $unitPrice; return $this; }

    public function getSalePrice(): ?string { return $this->salePrice; }
    public function setSalePrice(?string $salePrice): self { $this->salePrice = $salePrice; return $this; }

    public function getQuantity(): int { return $this->quantity; }

    /** Only for App\Maxeme\Service\StockLedger, which records the movement. */
    public function adjustQuantity(int $delta): void
    {
        $this->quantity += $delta;
    }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }

    public function isActive(): bool { return $this->active; }

    public function deactivate(): void
    {
        $this->active = false;
    }

    public function getDisplayName(): string
    {
        return trim(sprintf('%s %s', $this->vin, $this->name)) ?: sprintf('Part #%d', $this->id);
    }
}
