<?php

declare(strict_types=1);

namespace App\Entity;

use App\Service\DisplayNumber;
use App\Service\QuantityScale;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per actual change to any bucket column on `product_inventory`, from any source —
 * cart-hold sync, order or invoice reconciliation, the hourly recalc cron, a product import's
 * approved-balance reset, a goods receipt, a transfer, a bin adjustment. Deliberately a dedicated,
 * structured log rather than relying solely on AuditLogSubscriber's generic entity-diff logging:
 * these numbers have a history of being a major source of bugs/support complaints, and a query-able
 * (product, bucket, from, to, who, when, why) shape is what makes that debuggable after the fact.
 *
 * ## Nothing writes these rows by hand (#582)
 *
 * Rows are produced by App\EventSubscriber\InventoryBucketChangeLogger, a Doctrine `onFlush`
 * listener that reads the changeset of every ProductInventory being written. Before that, logging
 * was a call the caller had to remember: six buckets had callers that remembered and four did not,
 * so `received`, `transfer_out`, `quarantine` and `incoming` had no history at all — 9,907 rows on
 * the dev database and not one of them warehouse-side. Logging now lives inside the operation
 * instead of beside it, so a caller changing a bucket does not know this table exists and cannot
 * skip it.
 *
 * A recompute that writes back the value already there produces no changeset entry and therefore no
 * row here. That property is load-bearing: the recalc crons rewrite every bucket on every run, and
 * a log that recorded "5 → 5" hourly would bury the changes that matter.
 *
 * ## What the quantities mean
 *
 * `previousQuantity` and `newQuantity` are the column's value before and after the flush —
 * **the recompute result attributed to an operation, not that operation's increment**. The
 * distinction is easy to get backwards and the arithmetic silently disagrees if you do:
 * TransferOrderService::recomputeTransferBuckets() sets `transfer_out_quantity` to the whole
 * dispatched total over `transfer_order_line` for that product and warehouse (#584), not to what
 * this particular dispatch put on the truck. A row saying `transfer_out: 4 → 16` after a 12-unit
 * dispatch is that total moving, and the 12 is a subtraction the reader does — it is not a claim
 * that this operation dealt in 12 units, and where an operation touches a bucket someone else also
 * moved, it will not equal one either. The same applies to `write_off` and `quarantine`, which
 * StockMovementService::syncCoreTotal() recomputes from the detail rows.
 */
#[ORM\Entity]
#[ORM\Table(name: 'inventory_bucket_change_log')]
#[ORM\Index(name: 'idx_bucket_change_log_group', fields: ['groupId'])]
class InventoryBucketChangeLog
{
    public const BUCKET_CART_HOLD = 'cart_hold';
    /** The sales order's uninvoiced remainder (#539 stage 3) — see OrderInventoryReservation. */
    public const BUCKET_SALES_HOLD = 'sales_hold';
    public const BUCKET_PENDING = 'pending';
    public const BUCKET_APPROVED = 'approved';
    /** An invoice reaching Completed (2026-09-15) — the same claim as `approved`, relabeled, never an addition. */
    public const BUCKET_SHIPPED = 'shipped';
    /** Promised beyond stock (#548) — a split of the order's hold, not an addition to it. */
    public const BUCKET_BACKORDERED = 'backordered';
    /** Arrived and on the shelf, not yet in the external system's file (#564). */
    public const BUCKET_RECEIVED = 'received';
    /** On a purchase order, not yet arrived (#564). Not sellable, not in the availability sum. */
    public const BUCKET_INCOMING = 'incoming';
    /** Physically present but withheld — received-not-inspected, damaged, recalled (#564). */
    public const BUCKET_QUARANTINE = 'quarantine';
    /** Dispatched FROM this warehouse on an internal transfer; cumulative over the lines (#584). */
    public const BUCKET_TRANSFER_OUT = 'transfer_out';
    /** Received INTO this warehouse on an internal transfer; cumulative over the lines (#584). */
    public const BUCKET_TRANSFER_IN = 'transfer_in';
    /** Damaged, expired, scrapped or lost — gone, or unsellable for good (#581). */
    public const BUCKET_WRITE_OFF = 'write_off';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductCore $product;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'warehouse_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Warehouse $warehouse;

    #[ORM\Column(length: 20)]
    private string $bucket;

    #[ORM\Column(type: 'quantity')]
    private string $previousQuantity;

    #[ORM\Column(type: 'quantity')]
    private string $newQuantity;

    /** Short machine-readable category, e.g. 'cart_add', 'cart_expired', 'order_reconciled', 'import_approved_reset', 'cron_correction'. */
    #[ORM\Column(length: 60)]
    private string $action;

    /** User identifier who triggered the change, or 'System'/'console:<command>' for automated sources. */
    #[ORM\Column(length: 190)]
    private string $triggeredBy;

    /**
     * The `inventory_movement_group` row for the physical operation that caused this, or NULL
     * (#582).
     *
     * **NULL is meaningful, not missing.** The two states are:
     *
     *   NOT NULL  stock physically moved: a receipt, a putaway, a transfer, a pick, an adjustment
     *   NULL      no physical operation: a cart hold, a document reconcile, an import rebaseline
     *
     * A sell-side hold is a claim against stock that has not gone anywhere, so the reconcilers
     * never open an operation with a group and their rows are null BY CONSTRUCTION. That is why
     * this is a two-valued fact worth querying rather than a field that is usually empty: filtering
     * on `group_id IS NULL` separates the promises from the movements exactly.
     *
     * ## Why an integer and not a Doctrine association
     *
     * `inventory_movement_group` belongs to modules/InventoryDepthBundle, and that module is
     * deletable — config/bundles.php discovers modules by glob precisely so a deleted folder is one
     * fewer match rather than a boot crash. A core entity holding
     * `#[ORM\ManyToOne(targetEntity: InventoryMovementGroup::class)]` would turn that deletion into
     * a metadata load failure across the whole application, which is a far worse trade than
     * dereferencing this by hand on the rare occasion something wants the group itself.
     *
     * The foreign key is still REAL: the migration that adds this column declares
     * `REFERENCES inventory_movement_group (id) ON DELETE SET NULL`, because the module's tables are
     * created by the shared root migration chain and therefore exist whether or not the module's
     * code does. SET NULL rather than CASCADE — losing the link is a smaller loss than losing the
     * audit row, and an audit trail that deletes itself is not one. The metadata does not describe
     * that constraint, so a SchemaTool-built test database has the column without it; that gap is
     * the same one every other mapping-versus-migration difference in this project lives in, and
     * bin/ci-migration-replay's column check is what keeps it to exactly this.
     *
     * @see App\Contract\Inventory\InventoryOperationGroupInterface
     */
    #[ORM\Column(name: 'group_id', nullable: true)]
    private ?int $groupId = null;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    public function __construct()
    {
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProduct(): ProductCore { return $this->product; }
    public function setProduct(ProductCore $product): self { $this->product = $product; return $this; }
    public function getWarehouse(): Warehouse { return $this->warehouse; }
    public function setWarehouse(Warehouse $warehouse): self { $this->warehouse = $warehouse; return $this; }
    public function getBucket(): string { return $this->bucket; }
    public function setBucket(string $bucket): self { $this->bucket = $bucket; return $this; }
    public function getPreviousQuantity(): string { return $this->previousQuantity; }
    public function setPreviousQuantity(string|int|float $previousQuantity): self { $this->previousQuantity = QuantityScale::canonical($previousQuantity); return $this; }
    public function getNewQuantity(): string { return $this->newQuantity; }
    public function setNewQuantity(string|int|float $newQuantity): self { $this->newQuantity = QuantityScale::canonical($newQuantity); return $this; }
    public function getAction(): string { return $this->action; }
    public function setAction(string $action): self { $this->action = $action; return $this; }
    public function getTriggeredBy(): string { return $this->triggeredBy; }
    public function setTriggeredBy(string $triggeredBy): self { $this->triggeredBy = $triggeredBy; return $this; }
    public function getGroupId(): ?int { return $this->groupId; }
    public function setGroupId(?int $groupId): self { $this->groupId = $groupId; return $this; }
    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }

    /** Feeds AuditLogSubscriber's automatic label resolution (not that it needs to run on this — see EXCLUDED — but kept for consistency). */
    public function getLabel(): string
    {
        return sprintf(
            // `%s` through DisplayNumber and not `%d`: a bucket that moved 0.4 read "0 → 0".
            '%s @ %s — %s: %s → %s (%s)',
            $this->product->getSku() ?: ('#' . ($this->product->getId() ?? '?')),
            $this->warehouse->getName(),
            $this->bucket,
            (new DisplayNumber())->qty($this->previousQuantity),
            (new DisplayNumber())->qty($this->newQuantity),
            $this->action,
        );
    }
}
