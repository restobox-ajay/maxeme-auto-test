<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SalesTaxRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SalesTaxRepository::class)]
#[ORM\Table(name: 'sales_tax')]
class SalesTax
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $provinceName = '';

    #[ORM\Column(length: 16)]
    private string $abbreviation = '';

    #[ORM\Column(length: 40)]
    private string $taxType = '';

    #[ORM\Column(type: 'decimal', precision: 8, scale: 3, options: ['default' => 0])]
    private string $rate = '0.000';

    #[ORM\Column(length: 32, options: ['default' => 'Active'])]
    private string $status = 'Active';

    #[ORM\Column(length: 100, unique: true, nullable: true)]
    private ?string $slug = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $source = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProvinceName(): string
    {
        return $this->provinceName;
    }

    public function setProvinceName(string $provinceName): static
    {
        $this->provinceName = $provinceName;
        return $this;
    }

    public function getAbbreviation(): string
    {
        return $this->abbreviation;
    }

    public function setAbbreviation(string $abbreviation): static
    {
        $this->abbreviation = $abbreviation;
        return $this;
    }

    public function getTaxType(): string
    {
        return $this->taxType;
    }

    public function setTaxType(string $taxType): static
    {
        $this->taxType = $taxType;
        return $this;
    }

    public function getRate(): float
    {
        return (float) $this->rate;
    }

    public function setRate(float $rate): static
    {
        $this->rate = (string) $rate;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = $slug;
        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(?string $source): static
    {
        $this->source = $source;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
