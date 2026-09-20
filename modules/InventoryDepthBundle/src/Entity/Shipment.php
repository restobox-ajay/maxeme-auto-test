<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Entity;

use App\Entity\Company;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * The physical record of goods leaving the building, against one or more invoices for one customer
 * (`docs/plans/2026-09-14-shipment-dispatch.md`).
 *
 * ## Not a `CommercialDocument`
 *
 * States what left the building, never what is owed for it — the money is the Invoice(s) it ships
 * against, and a combined shipment can name several of them for one customer, so there is no single
 * total or currency to copy even in principle. Same shape as `GoodsReceipt`'s own exemption, mirrored
 * for the outbound side: see `EveryDocumentDeclaresItsContractTest::NOT_COMMERCIAL`.
 *
 * ## Why `company`, not `invoice`
 *
 * The first draft of this plan gave the header a single, NOT NULL `invoice` field — mirroring
 * `GoodsReceipt::$purchaseOrder` — which only works if one shipment always maps to exactly one
 * invoice. Combined shipments (one truck, several invoices for the same customer) mean it does not:
 * the thing every line on a shipment actually has in common is the customer, not any one invoice.
 * `ShipmentLine::$invoiceLine` is what enforces "always against an invoice, never standalone" now —
 * see that class.
 *
 * ## Voided, never deleted
 *
 * Same discipline as everywhere else in this codebase: a void is a second fact recorded alongside
 * the original, not an unwrite. `ShipmentVoidService` is the only writer of the four void fields.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shipment')]
class Shipment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(name: 'shipment_number', length: 60, unique: true)]
    private string $shipmentNumber = '';

    /** Idempotency key — a resubmitted form finds the existing shipment and applies nothing. */
    #[ORM\Column(name: 'client_operation_id', length: 190, nullable: true, unique: true)]
    private ?string $clientOperationId = null;

    #[ORM\Column(name: 'shipped_at')]
    private \DateTimeImmutable $shippedAt;

    #[ORM\Column(name: 'shipped_by', length: 190, nullable: true)]
    private ?string $shippedBy = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'voided_at', nullable: true)]
    private ?\DateTimeImmutable $voidedAt = null;

    #[ORM\Column(name: 'voided_by', length: 190, nullable: true)]
    private ?string $voidedBy = null;

    #[ORM\Column(name: 'void_reason', type: 'text', nullable: true)]
    private ?string $voidReason = null;

    /** @var Collection<int, ShipmentLine> */
    #[ORM\OneToMany(mappedBy: 'shipment', targetEntity: ShipmentLine::class, cascade: ['persist'])]
    private Collection $lines;

    public function __construct()
    {
        $this->lines = new ArrayCollection();
        $this->shippedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCompany(): Company { return $this->company; }
    public function setCompany(Company $company): self { $this->company = $company; return $this; }
    public function getShipmentNumber(): string { return $this->shipmentNumber; }
    public function setShipmentNumber(string $shipmentNumber): self { $this->shipmentNumber = $shipmentNumber; return $this; }
    public function getClientOperationId(): ?string { return $this->clientOperationId; }
    public function setClientOperationId(?string $clientOperationId): self { $this->clientOperationId = $clientOperationId; return $this; }
    public function getShippedAt(): \DateTimeImmutable { return $this->shippedAt; }
    public function setShippedAt(\DateTimeImmutable $shippedAt): self { $this->shippedAt = $shippedAt; return $this; }
    public function getShippedBy(): ?string { return $this->shippedBy; }
    public function setShippedBy(?string $shippedBy): self { $this->shippedBy = $shippedBy; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }
    public function getVoidedAt(): ?\DateTimeImmutable { return $this->voidedAt; }
    public function getVoidedBy(): ?string { return $this->voidedBy; }
    public function getVoidReason(): ?string { return $this->voidReason; }

    public function isVoided(): bool
    {
        return $this->voidedAt !== null;
    }

    /** Only ShipmentVoidService calls this — see its own docblock for the discipline. */
    public function markVoided(?string $actor, ?string $reason): self
    {
        $this->voidedAt = new \DateTimeImmutable();
        $this->voidedBy = $actor;
        $this->voidReason = $reason;

        return $this;
    }

    public function addLine(ShipmentLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setShipment($this);
        }

        return $this;
    }

    /** @return Collection<int, ShipmentLine> */
    public function getLines(): Collection
    {
        return $this->lines;
    }
}
