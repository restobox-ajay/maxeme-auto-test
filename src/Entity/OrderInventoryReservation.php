<?php

declare(strict_types=1);

namespace App\Entity;

use App\Service\QuantityScale;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ledger of what a SalesOrder currently has reserved against ProductInventory, keyed by
 * (order, product, warehouse, bucket).
 *
 * An order holds what it has not yet billed — its (ordered − invoiced) remainder — and everything it
 * HAS billed is held by the invoice that bills it (InvoiceInventoryReservation). Two concrete
 * entities rather than one table with a nullable order_id/invoice_id, deliberately: see the
 * InventoryReservation interface for why, and note the consequence that a bucket resolver cannot
 * hand either entity a bucket it has no business in.
 *
 * #539 stage 3 left this holding ONE bucket. #548 split that remainder in two — the part with stock
 * behind it and the part without — so an order may now hold `sales_hold` and `backordered` for the
 * same product and warehouse simultaneously, which is why `bucket` is part of the unique key.
 * `sales_hold + backordered` still sums to exactly the uninvoiced remainder, so the split changes
 * the attribution and not the total.
 *
 * Read and written exclusively by App\Service\Inventory\InventoryReservationReconciler — rows are
 * deleted (not zeroed) once a reservation drops to none, so "does a row exist" always answers "is
 * this order currently holding inventory for this product+region".
 *
 * ## Widened by a lot/serial dimension, not replaced (2026-09-14 lot/serial/expiry plan)
 *
 * `$lotId`/`$serial` narrow a hold to a specific InventoryLot or unit the same way `product` and
 * `warehouse` already narrow it — one more dimension in the SAME subtraction, nothing new invented.
 * Both are null on every hold before this widening and on every hold today for a product nobody has
 * opted into lot/serial tracking, which is what keeps this an addition and not a behavior change.
 *
 * The unique key widens to match: `(order, product, warehouse, bucket, COALESCE(lot_id,0),
 * COALESCE(serial,''))`. That COALESCE cannot be expressed by a Doctrine `#[ORM\UniqueConstraint]`
 * attribute — the same limitation InventoryDetail's own `uniq_inventory_detail` index lives with —
 * so, like that entity, the constraint exists only in the migration that creates it and not in this
 * class's mapping.
 */
#[ORM\Entity]
#[ORM\Table(name: 'order_inventory_reservation')]
#[ORM\Index(name: 'idx_order_inventory_reservation_lot', fields: ['lotId'])]
class OrderInventoryReservation implements InventoryReservation
{
    /** The stocked part of the order's uninvoiced remainder (#539 stage 3, narrowed by #548). */
    public const BUCKET_SALES_HOLD = 'sales_hold';

    /**
     * The unstocked part: units this order is owed that the warehouse does not have (#548).
     *
     * The second bucket an order may hold, and the reason `bucket` joined the unique key above. A
     * split line holds both at once for the same (order, product, warehouse) — six units of stock
     * in `sales_hold` and four promises in `backordered` — which the three-column key could not
     * represent.
     *
     * Still one ledger, not two tables: the rows are the same shape and the same reconciler writes
     * them, through a second InventoryReservationSubject rather than a copy of anything. See
     * SalesOrderBackorderReservationSubject.
     */
    public const BUCKET_BACKORDERED = 'backordered';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SalesOrder::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SalesOrder $order;

    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductCore $product;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'warehouse_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Warehouse $warehouse;

    #[ORM\Column(length: 20)]
    private string $bucket = self::BUCKET_SALES_HOLD;

    #[ORM\Column(type: 'quantity')]
    private string $quantity = '0.0000';

    /**
     * Always 0 on this entity, and carried anyway.
     *
     * The baseline only ever means something in the approved bucket — it is the portion of a
     * quantity already absorbed by a product-import recount (see Number1ProductImportBundle's
     * clear_approved_balance handling) — and from #539 stage 3 `approved` is the invoice's bucket,
     * so the live baselines are on InvoiceInventoryReservation::$syncedQuantity.
     *
     * It stays here because the two ledgers share a shape, and that shared shape is what lets one
     * reconciler write both instead of two copies of the same diff (see InventoryReservation). A
     * column that is structurally always zero is a cheaper thing to carry than a second copy of
     * InventoryReservationReconciler.
     */
    #[ORM\Column(type: 'quantity')]
    private string $syncedQuantity = '0.0000';

    /** See the class docblock's "widened by a lot/serial dimension" note, and InventoryReservation::getLotId(). */
    #[ORM\Column(name: 'lot_id', nullable: true)]
    private ?int $lotId = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $serial = null;

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
    public function getOrder(): SalesOrder { return $this->order; }
    public function setOrder(SalesOrder $order): static { $this->order = $order; return $this; }
    public function getProduct(): ProductCore { return $this->product; }
    public function setProduct(ProductCore $product): static { $this->product = $product; return $this; }
    public function getWarehouse(): Warehouse { return $this->warehouse; }
    public function setWarehouse(Warehouse $warehouse): static { $this->warehouse = $warehouse; return $this; }
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

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        return sprintf(
            'Order %s — %s @ %s',
            $this->order->getOrderNumber(),
            $this->product->getSku() ?: ('#' . ($this->product->getId() ?? '?')),
            $this->warehouse->getName(),
        );
    }
}
