<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Entity;

use App\Entity\InvoiceLine;
use App\Entity\ProductCore;
use Doctrine\ORM\Mapping as ORM;

/**
 * One SKU, one quantity, on one shipment — question 1 of
 * `docs/plans/2026-09-14-shipment-dispatch.md`, always answerable, for every product.
 *
 * ## `invoiceLine` is what makes "always against an invoice" real
 *
 * Nullable at the column level (`SET NULL` on delete) for the same reason
 * `invoice_line.sales_order_line_id` is: losing the attribution later must never delete the record
 * that goods actually left. But `ShipmentService::ship()` refuses to create a line with none —
 * there is no "unordered" sell-side shipment to build one for.
 *
 * ## `lotId`/`serial` — question 2, only when it applies
 *
 * Populated only for a line whose product is dimensional AND tracks lots/serials outbound
 * (`TrackingPolicy`), and always copied from `InvoiceLine::$lotId`/`$serial` — never chosen
 * independently. See the plan's "Question 2" section for why: the identity was already captured,
 * and validated, at invoice time. `$movementApplied` records whether this line actually produced a
 * `TYPE_SHIP` movement (question 2) or is pure paperwork (question 1 only) — the same distinction
 * `GoodsReceiptLine::$movementApplied` draws on the way in.
 *
 * ## No foreign key to product_core
 *
 * Same reason as `GoodsReceiptLine::$product`: a shipment line has to outlive the product's
 * deletion, so the join column is mapped but the migration emits no `REFERENCES` constraint for it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shipment_line')]
#[ORM\Index(name: 'idx_shipment_line_product', fields: ['product'])]
#[ORM\Index(name: 'idx_shipment_line_lot', fields: ['lotId'])]
class ShipmentLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Shipment::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'shipment_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Shipment $shipment;

    #[ORM\ManyToOne(targetEntity: InvoiceLine::class)]
    #[ORM\JoinColumn(name: 'invoice_line_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?InvoiceLine $invoiceLine = null;

    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: true)]
    private ?ProductCore $product = null;

    /** Snapshot, so the line still reads correctly after the product is gone. */
    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $sku = null;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 4)]
    private string $quantity = '0.0000';

    #[ORM\Column(name: 'lot_id', nullable: true)]
    private ?int $lotId = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $serial = null;

    /** Whether this line produced a real TYPE_SHIP movement, or is pure paperwork. */
    #[ORM\Column(name: 'movement_applied')]
    private bool $movementApplied = false;

    public function getId(): ?int { return $this->id; }
    public function getShipment(): Shipment { return $this->shipment; }
    public function setShipment(Shipment $shipment): self { $this->shipment = $shipment; return $this; }
    public function getInvoiceLine(): ?InvoiceLine { return $this->invoiceLine; }
    public function setInvoiceLine(?InvoiceLine $invoiceLine): self { $this->invoiceLine = $invoiceLine; return $this; }
    public function getProduct(): ?ProductCore { return $this->product; }
    public function setProduct(?ProductCore $product): self { $this->product = $product; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getSku(): ?string { return $this->sku; }
    public function setSku(?string $sku): self { $this->sku = $sku; return $this; }
    public function getQuantity(): string { return $this->quantity; }
    public function setQuantity(string $quantity): self { $this->quantity = $quantity; return $this; }
    public function getLotId(): ?int { return $this->lotId; }
    public function setLotId(?int $lotId): self { $this->lotId = $lotId; return $this; }
    public function getSerial(): ?string { return $this->serial; }
    public function setSerial(?string $serial): self { $this->serial = $serial; return $this; }
    public function isMovementApplied(): bool { return $this->movementApplied; }
    public function setMovementApplied(bool $movementApplied): self { $this->movementApplied = $movementApplied; return $this; }
}
