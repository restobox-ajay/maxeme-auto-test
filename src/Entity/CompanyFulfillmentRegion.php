<?php

namespace App\Entity;

use App\Repository\CompanyFulfillmentRegionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CompanyFulfillmentRegionRepository::class)]
#[ORM\Table(name: 'company_fulfillment_region')]
#[ORM\UniqueConstraint(name: 'uniq_company_fulfillment_region', columns: ['company_id', 'fulfillment_region_id'])]
class CompanyFulfillmentRegion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: FulfillmentRegion::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private FulfillmentRegion $fulfillmentRegion;

    #[ORM\ManyToOne(targetEntity: PriceList::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?PriceList $priceList = null;

    #[ORM\Column(length: 32)]
    private string $status = 'Inactive';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getCompany(): Company { return $this->company; }
    public function setCompany(Company $company): self { $this->company = $company; return $this; }

    public function getFulfillmentRegion(): FulfillmentRegion { return $this->fulfillmentRegion; }
    public function setFulfillmentRegion(FulfillmentRegion $fulfillmentRegion): self { $this->fulfillmentRegion = $fulfillmentRegion; return $this; }

    public function getPriceList(): ?PriceList { return $this->priceList; }
    public function setPriceList(?PriceList $priceList): self { $this->priceList = $priceList; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function isActive(): bool { return $this->status === 'Active'; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): self { $this->updatedAt = new \DateTimeImmutable(); return $this; }
}
