<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Maxeme\Repository\ServiceItemRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A labour / service catalogue item, picked on invoices and work orders (legacy
 * CNSServiceBundle Service). Soft-deleted. Ids are the legacy ids.
 */
#[ORM\Entity(repositoryClass: ServiceItemRepository::class)]
#[ORM\Table(name: 'maxeme_service')]
class ServiceItem implements SoftDeletable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $preferredName = null;

    /** Standard price, in dollars (CDN). */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $price = null;

    /** Its catalog; the category's colour is the service's on the calendar unless ownColour overrides it. */
    #[ORM\ManyToOne(targetEntity: ServiceCategory::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?ServiceCategory $category = null;

    /** Config › Settings › Tax Classes. Optional: legacy services have none. */
    #[ORM\ManyToOne(targetEntity: TaxClass::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?TaxClass $taxClass = null;

    /** "#1f77b4": the service's own calendar colour, or null to use its category's. */
    #[ORM\Column(length: 7, nullable: true)]
    private ?string $ownColour = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    /** Set on create and on every edit (the legacy app only ever set it on create). */
    #[ORM\Column]
    private \DateTimeImmutable $lastUpdated;

    public function __construct()
    {
        $this->lastUpdated = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getPreferredName(): ?string { return $this->preferredName; }
    public function setPreferredName(?string $preferredName): self { $this->preferredName = $preferredName; return $this; }

    public function getPrice(): ?string { return $this->price; }

    /** "Name (Preferred)", as the invoice builder lists it. */
    public function getFullName(): string
    {
        return $this->preferredName !== null && $this->preferredName !== '' ? sprintf('%s (%s)', $this->name, $this->preferredName) : $this->name;
    }
    public function setPrice(?string $price): self { $this->price = $price; return $this; }

    public function getCategory(): ?ServiceCategory { return $this->category; }
    public function setCategory(?ServiceCategory $category): self { $this->category = $category; return $this; }

    /** For the service form's Category select. */
    public function getCategoryId(): ?int { return $this->category?->getId(); }

    public function getTaxClass(): ?TaxClass { return $this->taxClass; }
    public function setTaxClass(?TaxClass $taxClass): self { $this->taxClass = $taxClass; return $this; }

    /** For the service form's Tax Class select. */
    public function getTaxClassId(): ?int { return $this->taxClass?->getId(); }

    public function getOwnColour(): ?string { return $this->ownColour; }
    public function setOwnColour(?string $ownColour): self { $this->ownColour = $ownColour !== null ? strtolower($ownColour) : null; return $this; }

    /** The calendar colour: its own, else its category's (or that category's parent's); null when none is set. */
    public function getColour(): ?string { return $this->ownColour ?? $this->category?->getEffectiveColour(); }

    public function isActive(): bool { return $this->active; }

    public function deactivate(): void
    {
        $this->active = false;
        $this->touch();
    }

    public function getLastUpdated(): \DateTimeImmutable { return $this->lastUpdated; }

    public function touch(): void
    {
        $this->lastUpdated = new \DateTimeImmutable();
    }
}
