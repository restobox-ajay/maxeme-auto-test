<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A country the storefront can hold an address in.
 *
 * Reference data, not operational data: two rows today (CA, US), seeded from
 * {@see \App\Service\RegionSeedData}. Read through {@see \App\Service\Region}, never queried
 * directly from a controller or template.
 *
 * Deliberately not named "Region" — FulfillmentRegion already means *warehouse* in this codebase,
 * so the geo_ prefix keeps the two apart at a glance.
 */
#[ORM\Entity]
#[ORM\Table(name: 'geo_country')]
#[ORM\UniqueConstraint(name: 'uniq_geo_country_code', fields: ['code'])]
class GeoCountry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** ISO 3166-1 alpha-2. This is what addresses store. */
    #[ORM\Column(length: 2)]
    private string $code = '';

    #[ORM\Column(length: 80)]
    private string $name = '';

    /**
     * Inactive removes the country from selection dropdowns. It deliberately does NOT invalidate
     * addresses already saved against it — deactivating means "stop offering this", not "break
     * historical data".
     */
    #[ORM\Column(length: 32)]
    private string $status = 'Active';

    #[ORM\Column(name: 'sort_order')]
    private int $sortOrder = 0;

    /** @var Collection<int, GeoProvince> */
    #[ORM\OneToMany(targetEntity: GeoProvince::class, mappedBy: 'country', cascade: ['persist'])]
    private Collection $provinces;

    public function __construct()
    {
        $this->provinces = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getCode(): string { return $this->code; }
    public function setCode(string $code): self { $this->code = strtoupper(trim($code)); return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function isActive(): bool { return strcasecmp($this->status, 'Active') === 0; }

    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): self { $this->sortOrder = $sortOrder; return $this; }

    /** @return Collection<int, GeoProvince> */
    public function getProvinces(): Collection { return $this->provinces; }

    public function addProvince(GeoProvince $province): self
    {
        if (!$this->provinces->contains($province)) {
            $this->provinces->add($province);
            $province->setCountry($this);
        }

        return $this;
    }
}
