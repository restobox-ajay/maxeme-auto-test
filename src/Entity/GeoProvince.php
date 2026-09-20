<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A province, territory or state within a {@see GeoCountry}.
 *
 * Called "province" because that is this codebase's existing word for the field on every level —
 * company_address.province, sales_tax.province_name, TaxContext::$province, the billing_province /
 * ship_province form fields — and it has always held US states too. Introducing "subdivision" would
 * mean two words for one concept, which is the opposite of the point.
 *
 * Reference data, seeded from {@see \App\Service\RegionSeedData} (13 Canadian + 50 US). Read through
 * {@see \App\Service\Region}.
 *
 * Addresses store this entity's `code` as a plain validated string, never a foreign key: an order's
 * recorded province must not change because a row here was renamed.
 */
#[ORM\Entity]
#[ORM\Table(name: 'geo_province')]
#[ORM\UniqueConstraint(name: 'uniq_geo_province', fields: ['country', 'code'])]
#[ORM\Index(name: 'idx_geo_province_status', fields: ['status'])]
class GeoProvince
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: GeoCountry::class, inversedBy: 'provinces')]
    #[ORM\JoinColumn(name: 'country_id', referencedColumnName: 'id', nullable: false)]
    private GeoCountry $country;

    /** Codes are unique per country only — 'CA' is both Canada and California. */
    #[ORM\Column(length: 6)]
    private string $code = '';

    #[ORM\Column(length: 120)]
    private string $name = '';

    /** Inactive hides it from dropdowns; saved addresses referencing it stay valid. */
    #[ORM\Column(length: 32)]
    private string $status = 'Active';

    #[ORM\Column(name: 'sort_order')]
    private int $sortOrder = 0;

    public function getId(): ?int { return $this->id; }

    public function getCountry(): GeoCountry { return $this->country; }
    public function setCountry(GeoCountry $country): self { $this->country = $country; return $this; }

    public function getCode(): string { return $this->code; }
    public function setCode(string $code): self { $this->code = strtoupper(trim($code)); return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = trim($name); return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function isActive(): bool { return strcasecmp($this->status, 'Active') === 0; }

    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): self { $this->sortOrder = $sortOrder; return $this; }
}
