<?php

declare(strict_types=1);

namespace App\Maxeme\Entity;

use App\Entity\InventoryReservation;
use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Service\QuantityScale;
use Doctrine\ORM\Mapping as ORM;

/**
 * What a repair order holds of a part in one warehouse, and in which bucket (see
 * RepairOrderReservationSubject): the ledger core's InventoryReservationReconciler keeps in step
 * with the ProductInventory bucket columns, as it does a sales order's or an invoice's.
 */
#[ORM\Entity]
#[ORM\Table(name: 'maxeme_repair_order_inventory_reservation')]
#[ORM\UniqueConstraint(name: 'uniq_mx_ro_reservation', columns: ['repair_order_id', 'product_id', 'warehouse_id'])]
class RepairOrderInventoryReservation implements InventoryReservation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RepairOrder::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private RepairOrder $repairOrder;

    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ProductCore $product;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Warehouse $warehouse;

    #[ORM\Column(length: 20)]
    private string $bucket;

    #[ORM\Column(type: 'quantity')]
    private string $quantity = '0.0000';

    #[ORM\Column(type: 'quantity')]
    private string $syncedQuantity = '0.0000';

    /** Parts are not lot- or serial-tracked; kept for the ledger contract. */
    #[ORM\Column(nullable: true)]
    private ?int $lotId = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $serial = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(RepairOrder $repairOrder, ProductCore $product, Warehouse $warehouse, string $bucket)
    {
        $this->repairOrder = $repairOrder;
        $this->product = $product;
        $this->warehouse = $warehouse;
        $this->bucket = $bucket;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getRepairOrder(): RepairOrder { return $this->repairOrder; }
    public function getProduct(): ProductCore { return $this->product; }
    public function getWarehouse(): Warehouse { return $this->warehouse; }
    public function getBucket(): string { return $this->bucket; }
    public function setBucket(string $bucket): static { $this->bucket = $bucket; return $this; }
    public function getQuantity(): string { return $this->quantity; }
    public function setQuantity(string|int|float $quantity): static { $this->quantity = QuantityScale::canonical($quantity); return $this; }
    public function getSyncedQuantity(): string { return $this->syncedQuantity; }
    public function setSyncedQuantity(string|int|float $syncedQuantity): static { $this->syncedQuantity = QuantityScale::canonical($syncedQuantity); return $this; }
    public function getEffectiveQuantity(): string { return QuantityScale::canonical(max(0.0, (float) $this->quantity - (float) $this->syncedQuantity)); }
    public function getLotId(): ?int { return $this->lotId; }
    public function setLotId(?int $lotId): static { $this->lotId = $lotId; return $this; }
    public function getSerial(): ?string { return $this->serial; }
    public function setSerial(?string $serial): static { $this->serial = $serial; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): static { $this->updatedAt = new \DateTimeImmutable(); return $this; }
}
