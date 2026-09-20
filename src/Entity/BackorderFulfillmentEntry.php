<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BackorderFulfillmentEntryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One backorder episode for one order line (#548): opened the moment the line first goes
 * non-Fulfilled, closed the moment it returns to Fulfilled.
 *
 * This table exists because the line itself forgets. Once SalesOrderLine::$backorderedQuantity is
 * back to zero, nothing in the schema remembers that the line was ever short, so a queue derived
 * from the lines could only ever show what is still outstanding — and the requirement is a queue
 * where an automatically-released episode shows up alongside a manually-released one, already
 * complete, in the same list.
 *
 * ## What it deliberately does not carry
 *
 * No quantity, no ETA, no release mode. The quantity and the ETA live on the line
 * (SalesOrderLine::$backorderedQuantity/$restockEta) and are read through it, so the two can never
 * drift; who released it and when lives in the order's SalesOrderLog, written by
 * BackorderReleaseService, so the queue links out to the timeline rather than keeping a second copy
 * of it.
 *
 * $product and $warehouse ARE denormalised, because those two are what the queue filters and sorts
 * by and what BackorderReleaseService::releaseAutomatically() looks entries up by — the same
 * denormalisation OrderInventoryReservation already makes, for the same reason.
 *
 * ## Enforced in code, not by the schema
 *
 * At most one Open entry per line. SQLite has no partial unique index in this repo's patterns, so
 * InventoryReconciliationSubscriber — the one place that opens and closes these — checks for an
 * existing Open entry before creating one.
 */
#[ORM\Entity(repositoryClass: BackorderFulfillmentEntryRepository::class)]
#[ORM\Table(name: 'backorder_fulfillment_entry')]
#[ORM\Index(name: 'idx_backorder_entry_status', columns: ['status'])]
#[ORM\Index(name: 'idx_backorder_entry_line', columns: ['line_id'])]
class BackorderFulfillmentEntry
{
    /** Still short. What the queue screen shows by default. */
    public const STATUS_OPEN = 'Open';

    /** The line reached Fulfilled — by an admin release, by an automatic one, or by being re-quantified. */
    public const STATUS_COMPLETE = 'Complete';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SalesOrder::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SalesOrder $order;

    #[ORM\ManyToOne(targetEntity: SalesOrderLine::class)]
    #[ORM\JoinColumn(name: 'line_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SalesOrderLine $line;

    /**
     * CASCADE, like both reservation ledgers and unlike SalesOrderLine::$product.
     *
     * A document line snapshots what was sold and must outlive the product's deletion — product
     * imports delete rows routinely — which is why sales_order_line has no foreign key here at all.
     * This row is not a snapshot of anything: it is live queue state, and a queue entry for a
     * product that no longer exists is not a state worth keeping.
     */
    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductCore $product;

    /** Nullable for the one case that produces it: a line whose region names no warehouse. */
    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'warehouse_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Warehouse $warehouse = null;

    /**
     * Open or Complete, as a plain string for the reason every other status column in this app is
     * one. Not a duplicate of SalesOrderLine::$fulfillmentStatus, which says how much of the LINE
     * is short; this says whether the EPISODE has finished.
     */
    #[ORM\Column(length: 20, options: ['default' => self::STATUS_OPEN])]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'completed_at', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getOrder(): SalesOrder { return $this->order; }
    public function setOrder(SalesOrder $order): self { $this->order = $order; return $this; }
    public function getLine(): SalesOrderLine { return $this->line; }
    public function setLine(SalesOrderLine $line): self { $this->line = $line; return $this; }
    public function getProduct(): ProductCore { return $this->product; }
    public function setProduct(ProductCore $product): self { $this->product = $product; return $this; }
    public function getWarehouse(): ?Warehouse { return $this->warehouse; }
    public function setWarehouse(?Warehouse $warehouse): self { $this->warehouse = $warehouse; return $this; }
    public function getStatus(): string { return $this->status; }
    public function isOpen(): bool { return $this->status === self::STATUS_OPEN; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }

    /** Idempotent: closing an already-closed episode keeps the first completion time. */
    public function complete(): self
    {
        if ($this->status !== self::STATUS_COMPLETE) {
            $this->status = self::STATUS_COMPLETE;
            $this->completedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        return sprintf(
            'Backorder on %s — %s',
            $this->order->getOrderNumber(),
            $this->product->getSku() ?: ('#' . ($this->product->getId() ?? '?')),
        );
    }
}
