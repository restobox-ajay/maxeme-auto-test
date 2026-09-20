<?php

namespace App\Entity;

use App\Repository\FulfillmentRegionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FulfillmentRegionRepository::class)]
#[ORM\Table(name: 'fulfillment_region')]
class FulfillmentRegion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 160)]
    private string $name = '';

    #[ORM\Column(length: 32)]
    private string $status = 'Active';

    #[ORM\Column]
    private bool $guestVisible = false;

    #[ORM\ManyToOne(targetEntity: PriceList::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?PriceList $guestPriceList = null;

    /** Ticked on the region checklist of every brand-new company, in admin and on registration. */
    #[ORM\Column]
    private bool $defaultForNewCompany = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }
    public function isGuestVisible(): bool { return $this->guestVisible; }
    public function setGuestVisible(bool $guestVisible): self { $this->guestVisible = $guestVisible; return $this; }
    public function getGuestPriceList(): ?PriceList { return $this->guestPriceList; }
    public function setGuestPriceList(?PriceList $guestPriceList): self { $this->guestPriceList = $guestPriceList; return $this; }
    public function isDefaultForNewCompany(): bool { return $this->defaultForNewCompany; }
    public function setDefaultForNewCompany(bool $defaultForNewCompany): self { $this->defaultForNewCompany = $defaultForNewCompany; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
