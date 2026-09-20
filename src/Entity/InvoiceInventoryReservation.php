<?php

declare(strict_types=1);

namespace App\Entity;

use App\Service\QuantityScale;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ledger of what an Invoice currently has reserved against ProductInventory's pending/approved
 * buckets, keyed by (invoice, product, region). The invoice's half of #539 stage 3: the sales order
 * holds only what it has NOT yet billed (OrderInventoryReservation, `sales_hold`), and everything it
 * has billed is held here by the invoice that bills it.
 *
 * Read and written exclusively by InventoryReservationReconciler — the same reconciler that writes
 * OrderInventoryReservation, because the two ledgers differ in which document they hang off and
 * which buckets they may hold, never in how a bucket is diffed against them. Rows are deleted (not
 * zeroed) once a reservation drops to none, so "does a row exist" always answers "is this invoice
 * currently holding inventory for this product+region".
 *
 * Only `pending`, `approved`, and `shipped` may appear in $bucket. `sales_hold` is the order's and
 * has no meaning here — an invoice has billed its quantity by definition, so there is nothing
 * uninvoiced about it to hold.
 *
 * ## Widened by a lot/serial dimension, not replaced (2026-09-14 lot/serial/expiry plan)
 *
 * `$lotId`/`$serial` narrow a hold to a specific InventoryLot or unit, the same additive dimension
 * OrderInventoryReservation gains — see that class's docblock. The unique key widens to
 * `(invoice, product, warehouse, bucket, COALESCE(lot_id,0), COALESCE(serial,''))`: `bucket` joins
 * the key here for the first time, because a lot-specific row and this invoice's generic row for the
 * same product+warehouse can no longer share the unqualified 3-column key once a lot dimension
 * exists — an invoice holding `pending` on lot A and nothing yet on lot B needs both rows to fit
 * under one key shape. As with OrderInventoryReservation, the COALESCE this needs cannot be expressed
 * by a Doctrine `#[ORM\UniqueConstraint]` attribute, so — matching InventoryDetail's own
 * `uniq_inventory_detail` — the constraint exists only in the migration, not in this mapping.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoice_inventory_reservation')]
#[ORM\Index(name: 'idx_invoice_inventory_reservation_lot', fields: ['lotId'])]
class InvoiceInventoryReservation implements InventoryReservation
{
    /** Issued, awaiting fulfilment (InvoiceStatus::Pending). */
    public const BUCKET_PENDING = 'pending';

    /** Being fulfilled (InvoiceStatus::Processing) — released by import/recount only. */
    public const BUCKET_APPROVED = 'approved';

    /**
     * Fulfilled (InvoiceStatus::Completed) — the same claim as `approved`, relabeled, never an
     * addition to it (2026-09-15, docs/plans/2026-09-15-shipment-approved-to-shipped-bucket.md).
     * Same release rule: import/recount only.
     */
    public const BUCKET_SHIPPED = 'shipped';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(name: 'invoice_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Invoice $invoice;

    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductCore $product;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'warehouse_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Warehouse $warehouse;

    #[ORM\Column(length: 20)]
    private string $bucket = self::BUCKET_PENDING;

    #[ORM\Column(type: 'quantity')]
    private string $quantity = '0.0000';

    /**
     * Meaningful only for bucket=approved rows: the portion of $quantity already absorbed by the
     * most recent product-import recount for this (invoice, product, region) — see
     * Number1ProductImportBundle's clear_approved_balance handling. What actually contributes to
     * ProductInventory.approvedQuantity is max(0, quantity - syncedQuantity), not raw quantity, so a
     * recount doesn't get silently re-added the next time this invoice is touched.
     *
     * It lives on the INVOICE's ledger from stage 3 on, because `approved` is the invoice's bucket
     * now — the import stamps whatever holds the balance it is clearing.
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
    public function getInvoice(): Invoice { return $this->invoice; }
    public function setInvoice(Invoice $invoice): static { $this->invoice = $invoice; return $this; }
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
            'Invoice %s — %s @ %s',
            $this->invoice->getDocumentNumber(),
            $this->product->getSku() ?: ('#' . ($this->product->getId() ?? '?')),
            $this->warehouse->getName(),
        );
    }
}
