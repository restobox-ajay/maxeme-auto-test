<?php

namespace App\Entity;

use App\Repository\ProductInventoryRepository;
use App\Service\QuantityScale;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductInventoryRepository::class)]
#[ORM\Table(name: 'product_inventory')]
#[ORM\UniqueConstraint(name: 'uniq_product_inventory_product_location', fields: ['product', 'warehouse'])]
class ProductInventory
{
    /**
     * What {@see remainingBackorderCapacity()} answers when no ceiling is configured: the largest
     * quantity `decimal(14, 4)` can hold.
     *
     * It used to be `PHP_INT_MAX`, which was a fine sentinel while capacities were `int` and is not
     * one now that they are decimal strings — 9,223,372,036,854,775,807 does not survive being
     * scaled to ten-thousandths, and the conversion overflows rather than saying so. The column's
     * own maximum is a bounded sentinel that every comparison still reads as "more than anybody is
     * ever going to ask for", because a cap above it could not be stored in the first place.
     */
    public const UNCAPPED_BACKORDER_CAPACITY = '9999999999.9999';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductCore $product;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'warehouse_id', referencedColumnName: 'id', nullable: true)]
    private ?Warehouse $warehouse = null;

    #[ORM\Column(type: 'quantity')]
    private string $quantity = '0.0000';

    #[ORM\Column(type: 'quantity')]
    private string $reservedQuantity = '0.0000';

    // Inventory held by items sitting in customer carts, not yet an order. Released
    // automatically when a cart hold expires (see CartHoldService) or the cart converts
    // to an order.
    #[ORM\Column(type: 'quantity')]
    private string $cartHoldQuantity = '0.0000';

    // Committed to sales orders that have been accepted but not yet billed: the (ordered −
    // invoiced) remainder of every order Approved or later (#539 stage 3, see
    // OrderInventoryBucketResolver). Released as invoices are raised against the order, or
    // outright when it is voided.
    //
    // A fourth bucket rather than more Pending, for the reason cart_hold is its own: one bucket
    // per source table is what makes a drift attributable to the side that caused it. SO-held and
    // invoice-held quantities are disjoint by construction, so a bug in the uninvoiced calculation
    // shows up here as a bucket disagreeing with its ledger — inside Pending it would just look
    // like a slightly larger Pending, invisibly, forever.
    #[ORM\Column(type: 'quantity')]
    private string $salesHoldQuantity = '0.0000';

    // Committed to invoices currently in the Pending bucket — issued and awaiting fulfilment
    // (see InvoiceInventoryBucketResolver).
    #[ORM\Column(type: 'quantity')]
    private string $pendingQuantity = '0.0000';

    // Committed to invoices currently Processing — being fulfilled, not yet Completed. See
    // InvoiceInventoryBucketResolver. Transfers to $shippedQuantity the moment the invoice reaches
    // Completed (docs/plans/2026-09-15-shipment-approved-to-shipped-bucket.md) — a relabeling, not
    // a release: the two together never exceed what was originally approved for a given invoice.
    #[ORM\Column(type: 'quantity')]
    private string $approvedQuantity = '0.0000';

    // Committed to invoices that have reached Completed. Exactly like $approvedQuantity — same
    // "released by import/recount only" rule, same reason (this app has no other mechanism that
    // decrements Starting Inventory on shipment) — split into its own bucket only so
    // InventoryDetail's `sold`-status rows (2026-09-14 shipment-dispatch plan) have an independent
    // figure to reconcile against, which `approved` alone could not be without conflating two
    // different invoice stages in one bucket.
    #[ORM\Column(type: 'quantity')]
    private string $shippedQuantity = '0.0000';

    /**
     * Promised beyond what this warehouse holds (#548): the sum of every open order line's
     * backordered quantity for this product here.
     *
     * A bucket like the four above, and written by the same InventoryReservationReconciler through
     * the same ledger — see SalesOrderBackorderReservationSubject. What makes it different from
     * them is only what it means: the others say "these units exist and are spoken for", this one
     * says "these units are owed and do not exist yet".
     *
     * It is a SPLIT of the order's hold, not an addition to it: SalesOrderReservationSubject nets
     * the same quantity out of `sales_hold`, and InvoiceReservationSubject nets it out of
     * `pending`/`approved` when an invoice bills a backordered line. So the total subtracted from
     * availability is exactly what it was before this bucket existed — the bucket says which part
     * of it has stock behind it, and changes no number.
     */
    #[ORM\Column(type: 'quantity', name: 'backordered_quantity')]
    private string $backorderedQuantity = '0.0000';

    /**
     * Whether this product may be ordered beyond stock HERE (#548). Off for every row until an
     * admin turns it on, which is the whole invariant of the feature: with this false,
     * BackorderSplitResolver always resolves zero backordered units and every refusal behaves
     * exactly as it did before.
     *
     * Its own column rather than "cap is null means off" so an admin can suspend backordering for
     * a week without losing the cap they configured.
     */
    #[ORM\Column(name: 'allow_backorder', options: ['default' => false])]
    private bool $allowBackorder = false;

    /**
     * The ceiling on $backorderedQuantity. NULL means no ceiling — only meaningful while
     * $allowBackorder is true. Requested units beyond it are refused outright, the same hard
     * refusal an oversell has always been; nothing waitlists past the cap.
     */
    #[ORM\Column(type: 'quantity', name: 'max_backorder_quantity', nullable: true)]
    private ?string $maxBackorderQuantity = null;

    /**
     * Preorder mode. When true, every restock decrements $maxBackorderQuantity by what arrived, so
     * incoming stock does not quietly reopen room to promise more. Off by default because nothing
     * else in this app shrinks a limit by itself, and most catalogues want a static ceiling.
     */
    #[ORM\Column(name: 'backorder_cap_shrinks_on_restock', options: ['default' => false])]
    private bool $backorderCapShrinksOnRestock = false;

    /**
     * Whether a restock immediately assigns arriving stock to the waiting queue, FIFO, instead of
     * waiting for an admin to do it on /admin/backorders. Independent of the cap-shrink flag: a row
     * may have either, both or neither. Both routes run the same
     * BackorderReleaseService::applyRelease(), so the only difference is whose name the audit trail
     * carries.
     */
    #[ORM\Column(name: 'auto_release_on_restock', options: ['default' => false])]
    private bool $autoReleaseOnRestock = false;

    /**
     * Arrived and on the shelf, not yet in the external system's file (#564).
     *
     * The first POSITIVE bucket. Every one above is stock leaving; this is stock that turned up
     * since the last import. It exists because `quantity` is not this application's number — it is
     * a snapshot imported from whatever the client actually runs — so receiving may not raise it.
     * The next import would overwrite whatever we wrote, and the disagreement would be ambiguous
     * after the fact: either their count predates our delivery, or it already included it and
     * something was booked twice.
     *
     * Sellable, so it is in getAvailableQuantity(). Cleared by an import with
     * `clear_received_balance` ticked, exactly the way `approved` clears.
     */
    #[ORM\Column(type: 'quantity', name: 'received_quantity', options: ['default' => 0])]
    private string $receivedQuantity = '0.0000';

    /**
     * On a purchase order, not yet arrived (#564).
     *
     * NOT sellable and deliberately absent from getAvailableQuantity() — it is a forecast, not
     * stock, the same distinction `backordered` makes on the negative side. Cleared by converting
     * to `received` when the goods are booked in.
     */
    #[ORM\Column(type: 'quantity', name: 'incoming_quantity', options: ['default' => 0])]
    private string $incomingQuantity = '0.0000';

    /**
     * Physically present but withheld — received-not-inspected, damaged pending disposition,
     * recalled.
     *
     * NEGATIVE, and now wired (#581): a cached sum of the `quarantine` and `returned` detail rows.
     * A return is quarantine in substance — goods on the shelf that nobody has yet said are fit to
     * sell again — so the two share a bucket.
     *
     * Distinct from a write-off, which is stock that will not come back. Quarantined stock is
     * present and countable and may well return to sale; the difference is a human decision, which
     * is also why nothing clears this automatically.
     *
     * It shipped as a column with no logic under #564, when its sign and its clearing rule were
     * open questions. They are answered here.
     */
    #[ORM\Column(type: 'quantity', name: 'quarantine_quantity', options: ['default' => 0])]
    private string $quarantineQuantity = '0.0000';

    /**
     * Everything that has ever left this warehouse on an internal transfer (#584).
     *
     * CUMULATIVE, not "on a truck right now", and that redefinition is the whole of #584. It used
     * to be a cached sum of the `in_transit` detail rows, which meant it fell back to zero the
     * moment a receipt consumed them — and the source warehouse sprang back to full availability
     * for stock that is now standing in another building. The permanent loss had to go somewhere,
     * so TransferOrderService wrote it into `received` as a negative, which entangled a
     * WarehouseOpsBundle fact with a bucket ProcurementBundle's flag gates: with ProcurementBundle
     * Inactive the negative left the sum, the source read 50 of 50 sellable, and the depth rows
     * said 40. The columns disagreed with `inventory_detail` while availability looked plausible.
     *
     * So the pair below is derived from the transfer DOCUMENTS instead:
     *
     *     transfer_out = SUM(transfer_order_line.quantity_dispatched) where this warehouse is FROM
     *     transfer_in  = SUM(transfer_order_line.quantity_received)   where this warehouse is TO
     *
     *                    West (from), quantity 50      East (to), quantity 0
     *                    out   in   available          out   in   available
     *   in flight         10    0      40                0    0       0
     *   arrived           10    0      40                0   10      10
     *
     * Neither ever goes down, which is the point: the departure is permanent, and `quantity` cannot
     * record it because that is the client's imported figure and not ours to reduce.
     *
     * NEGATIVE, for the reason every hold is: the units are still counted in `quantity` because the
     * external system has not been told they moved. "The source cannot sell what is on the truck",
     * and it cannot sell what came off the truck somewhere else either.
     *
     * Dispatched 10 / received 8 leaves West at −10 and East at +8 deliberately. The two lost in
     * transit stay missing — they are still sitting on the source's `in_transit` row, unsellable,
     * which is where lost stock belongs until somebody writes it off on purpose. Closing the
     * document is not the same as pretending they arrived.
     *
     * Written by WarehouseOpsBundle, which owns transfer orders, so both gate on THAT bundle rather
     * than on the one that gates `received` — see BundleBucketAvailabilityGate.
     */
    #[ORM\Column(type: 'quantity', name: 'transfer_out_quantity', options: ['default' => 0])]
    private string $transferOutQuantity = '0.0000';

    /**
     * Everything that has ever arrived at this warehouse on an internal transfer (#584).
     *
     * POSITIVE, and the mirror of `transfer_out` above — same writer, same document, same
     * cumulative reading, opposite sign. See that docblock for the whole argument.
     *
     * It exists because the alternative was to keep crediting an arrival to `received`, and that is
     * a different fact: `received` is ProcurementBundle's "a lorry turned up from a vendor and the
     * client's file does not know", gated on ProcurementBundle. An internal transfer is
     * WarehouseOpsBundle's, and a build running transfers without procurement — which is exactly
     * the configuration that exposed #584 — would have had its arrivals silently excluded from
     * availability by somebody else's flag.
     *
     * MovementRequest::receivedDelta() therefore no longer credits a transfer arrival to `received`
     * at the destination. That change and this column are the same change: one of them without the
     * other counts the same units twice.
     */
    #[ORM\Column(type: 'quantity', name: 'transfer_in_quantity', options: ['default' => 0])]
    private string $transferInQuantity = '0.0000';

    /**
     * Written off: damaged, expired, scrapped or lost (#581).
     *
     * Stock the business no longer has or can no longer sell, and will not get back. The client's
     * external system has not been told, so `quantity` still includes it and availability has to
     * take it off.
     *
     * Deliberately NOT including `staged` or `sold`. Those units are already held by the invoice
     * that bills them — `pending`/`approved`, which never release, because this app has no
     * mechanism that decrements Starting Inventory on shipment. Counting them here as well would
     * subtract the same units twice.
     *
     * NEGATIVE, and a cached sum of the detail rows in those statuses — the same shape and the same
     * self-maintaining property `transfer_out` has. `in_transit` is deliberately NOT in it: that is
     * `transfer_out`'s, and a unit in both would be withheld twice.
     *
     * Before this existed `received` carried all of it. That kept availability right and the
     * meaning wrong — a pick, a ship or a lot expiring wrote a bucket that means "stock arrived",
     * so `clear_received_balance` cleared a mixture of arrivals and departures while an admin
     * believed they were baselining deliveries.
     */
    #[ORM\Column(type: 'quantity', name: 'write_off_quantity', options: ['default' => 0])]
    private string $writeOffQuantity = '0.0000';

    /**
     * Whether the two transfer buckets — `transfer_out` and `transfer_in` — participate in
     * availability. Transient — not a column.
     *
     * Stamped by BundleBucketAvailabilityGate while WarehouseOpsBundle is Active. Same rule and
     * same reasoning as the flags below: off means the terms leave the sum, nothing is zeroed, and
     * switching back on needs no recount.
     *
     * ONE flag for both, deliberately (#584), exactly as `depthBucketsCount` is one flag for two
     * buckets. There is one writer — TransferOrderService — one bundle, and one question: is this
     * instance tracking transfers at all? Two flags that must always agree is a bug waiting to
     * happen, and the shape of that bug is unpleasant: half a transfer in the sum, so a warehouse
     * that has both sent and received reads as short or long by the difference, with no column
     * looking wrong on its own.
     */
    private bool $transferBucketsCount = true;

    /**
     * Whether the two buckets InventoryDepthBundle keeps — `write_off` and `quarantine` — take part
     * in availability. Transient, same gate and same rule as the flag above.
     *
     * One flag for both because there is one writer for both: StockMovementService recomputes each
     * from the detail rows, and detail rows only exist while the depth bundle is on. Splitting them
     * would be two flags that can never disagree.
     */
    private bool $depthBucketsCount = true;

    /**
     * Whether the positive buckets participate in availability. Transient — not a column.
     *
     * Stamped by BundleBucketAvailabilityGate on load: true while the bundle that WRITES
     * these buckets is Active, false when it is not.
     *
     * Defaults to true so that an instance with the bundle never installed is correct with no
     * listener involved — `received` is permanently 0 there, so adding it changes nothing.
     *
     * The tempting shortcut is to always add the column and skip the gate entirely. That is only
     * safe if the bundle was never on: enable receiving, book 50 units, disable it, and the column
     * still says 50. Always-adding would keep 50 units of a switched-off feature in everyone's
     * availability, invisibly. Nothing is zeroed on deactivation and nothing is recomputed on
     * re-enable — off means the terms are not in the sum, and the rows sit untouched in between.
     */
    private bool $positiveBucketsCount = true;

    // Stage 1 of #425: column only. Not yet included in getAvailableQuantity() or shown
    // anywhere inventory breakdown math is displayed — that is stage 2.
    #[ORM\Column(type: 'quantity')]
    private string $manualAdjustment = '0.0000';

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProduct(): ProductCore { return $this->product; }
    public function setProduct(ProductCore $product): self { $this->product = $product; return $this; }
    public function getWarehouse(): ?Warehouse { return $this->warehouse; }
    public function setWarehouse(?Warehouse $warehouse): self { $this->warehouse = $warehouse; return $this; }
    /**
     * Every quantity on this row is a decimal STRING since `QuantityType` became a decimal type —
     * `"2.5000"`, not `2`. The setters take `string|int|float` so the several hundred call sites
     * that hand them a plain `int` are unchanged, and spell what they are given through
     * {@see QuantityScale::canonical()} so one figure has one spelling on the row. How
     * many places a store actually KEEPS is `QuantityScale::round()`, applied where the figure
     * enters the application; this is the column's own scale and rounds nothing away.
     */
    public function getQuantity(): string { return $this->quantity; }
    public function setQuantity(string|int|float $quantity): self { $this->quantity = QuantityScale::canonical($quantity); return $this; }
    public function getReservedQuantity(): string { return $this->reservedQuantity; }
    public function setReservedQuantity(string|int|float $reservedQuantity): self { $this->reservedQuantity = QuantityScale::canonical($reservedQuantity); return $this; }

    public function getCartHoldQuantity(): string { return $this->cartHoldQuantity; }
    public function setCartHoldQuantity(string|int|float $cartHoldQuantity): self { $this->cartHoldQuantity = QuantityScale::canonical(max(0, (float) $cartHoldQuantity)); return $this; }
    public function adjustCartHold(string|int|float $delta): self { $this->cartHoldQuantity = QuantityScale::canonical(max(0, (float) $this->cartHoldQuantity + (float) $delta)); return $this; }

    public function getSalesHoldQuantity(): string { return $this->salesHoldQuantity; }
    public function setSalesHoldQuantity(string|int|float $salesHoldQuantity): self { $this->salesHoldQuantity = QuantityScale::canonical(max(0, (float) $salesHoldQuantity)); return $this; }
    public function adjustSalesHold(string|int|float $delta): self { $this->salesHoldQuantity = QuantityScale::canonical(max(0, (float) $this->salesHoldQuantity + (float) $delta)); return $this; }

    public function getPendingQuantity(): string { return $this->pendingQuantity; }
    public function setPendingQuantity(string|int|float $pendingQuantity): self { $this->pendingQuantity = QuantityScale::canonical(max(0, (float) $pendingQuantity)); return $this; }
    public function adjustPending(string|int|float $delta): self { $this->pendingQuantity = QuantityScale::canonical(max(0, (float) $this->pendingQuantity + (float) $delta)); return $this; }

    public function getApprovedQuantity(): string { return $this->approvedQuantity; }
    public function setApprovedQuantity(string|int|float $approvedQuantity): self { $this->approvedQuantity = QuantityScale::canonical(max(0, (float) $approvedQuantity)); return $this; }
    public function adjustApproved(string|int|float $delta): self { $this->approvedQuantity = QuantityScale::canonical(max(0, (float) $this->approvedQuantity + (float) $delta)); return $this; }

    public function getShippedQuantity(): string { return $this->shippedQuantity; }
    public function setShippedQuantity(string|int|float $shippedQuantity): self { $this->shippedQuantity = QuantityScale::canonical(max(0, (float) $shippedQuantity)); return $this; }
    public function adjustShipped(string|int|float $delta): self { $this->shippedQuantity = QuantityScale::canonical(max(0, (float) $this->shippedQuantity + (float) $delta)); return $this; }

    public function getBackorderedQuantity(): string { return $this->backorderedQuantity; }
    public function setBackorderedQuantity(string|int|float $backorderedQuantity): self { $this->backorderedQuantity = QuantityScale::canonical(max(0, (float) $backorderedQuantity)); return $this; }
    public function adjustBackordered(string|int|float $delta): self { $this->backorderedQuantity = QuantityScale::canonical(max(0, (float) $this->backorderedQuantity + (float) $delta)); return $this; }

    public function isAllowBackorder(): bool { return $this->allowBackorder; }
    public function setAllowBackorder(bool $allowBackorder): self { $this->allowBackorder = $allowBackorder; return $this; }

    public function getMaxBackorderQuantity(): ?string { return $this->maxBackorderQuantity; }
    public function setMaxBackorderQuantity(string|int|float|null $maxBackorderQuantity): self { $this->maxBackorderQuantity = $maxBackorderQuantity === null ? null : QuantityScale::canonical(max(0, (float) $maxBackorderQuantity)); return $this; }

    public function isBackorderCapShrinksOnRestock(): bool { return $this->backorderCapShrinksOnRestock; }
    public function setBackorderCapShrinksOnRestock(bool $shrinks): self { $this->backorderCapShrinksOnRestock = $shrinks; return $this; }

    public function isAutoReleaseOnRestock(): bool { return $this->autoReleaseOnRestock; }
    public function setAutoReleaseOnRestock(bool $autoRelease): self { $this->autoReleaseOnRestock = $autoRelease; return $this; }

    public function getManualAdjustment(): string { return $this->manualAdjustment; }
    public function setManualAdjustment(string|int|float $manualAdjustment): self { $this->manualAdjustment = QuantityScale::canonical($manualAdjustment); return $this; }

    /**
     * Starting Inventory minus every hold bucket. Deliberately NOT clamped at 0 here — an
     * admin needs to see a real oversell as a negative number. Customer-facing code clamps
     * separately (see AbstractCustomerController::availableQuantityForProduct()).
     *
     * Every bucket has to appear in this subtraction; one left out is silent, since the quantity
     * still sits in its ledger and its cache column and simply never reaches availability.
     *
     * `backordered` appears here for that rule and not to change the answer (#548). It is carved
     * OUT of the same documents' `sales_hold`/`pending`/`approved` rather than added alongside
     * them, so a build with no backordered units anywhere — which is every build until an admin
     * opts a SKU in — computes the identical number it always did.
     */
    public function getAvailableQuantity(): string
    {
        // Summed as floats and then spelt at the column's scale. Eleven terms of at most four
        // decimal places each are far inside a double's exact range, and canonicalising the total
        // at the column's own scale is what removes the 1e-16 residue a binary sum leaves behind —
        // so `0.1 + 0.2` here is `0.3000` and not `0.30000000000000004`. Nothing is rounded away:
        // every term was already a figure the column could hold.
        return QuantityScale::canonical(
            (float) $this->quantity
            + ($this->positiveBucketsCount ? (float) $this->receivedQuantity : 0)
            + ($this->transferBucketsCount ? (float) $this->transferInQuantity : 0)
            - ($this->transferBucketsCount ? (float) $this->transferOutQuantity : 0)
            - ($this->depthBucketsCount ? (float) $this->writeOffQuantity : 0)
            - ($this->depthBucketsCount ? (float) $this->quarantineQuantity : 0)
            - (float) $this->cartHoldQuantity
            - (float) $this->salesHoldQuantity
            - (float) $this->pendingQuantity
            - (float) $this->approvedQuantity
            - (float) $this->shippedQuantity
            - (float) $this->backorderedQuantity,
        );
    }

    public function getReceivedQuantity(): string { return $this->receivedQuantity; }
    public function setReceivedQuantity(string|int|float $receivedQuantity): self { $this->receivedQuantity = QuantityScale::canonical($receivedQuantity); return $this; }
    public function getTransferOutQuantity(): string { return $this->transferOutQuantity; }
    public function setTransferOutQuantity(string|int|float $transferOutQuantity): self { $this->transferOutQuantity = QuantityScale::canonical(max(0, (float) $transferOutQuantity)); return $this; }
    // There is deliberately no adjustTransferOut()/adjustTransferIn() (#584). Both are DERIVED from
    // the transfer documents and rewritten whole, the way `sales_hold` is derived from orders and
    // `pending` from invoices — so they self-heal, and a half-applied increment cannot leave a
    // warehouse permanently short. An adjust() on the API is an invitation to accumulate instead,
    // which is the failure mode this issue existed to remove. The unused one that was here has gone.
    public function getTransferInQuantity(): string { return $this->transferInQuantity; }
    public function setTransferInQuantity(string|int|float $transferInQuantity): self { $this->transferInQuantity = QuantityScale::canonical(max(0, (float) $transferInQuantity)); return $this; }
    public function setTransferBucketsCount(bool $counts): self { $this->transferBucketsCount = $counts; return $this; }

    /**
     * Reads the gate flag back, the way positiveBucketsCount() already did.
     *
     * Added for #36: the inventory grid has to say, per column, whether that term is in THIS
     * row's availability — a column reading 40 that availability is ignoring is the whole
     * reconciliation defect in miniature. Taking the answer from the row the figure came from,
     * rather than asking BundleStatusRepository a second time, is what stops the explanation
     * disagreeing with the number it explains.
     */
    public function transferBucketsCount(): bool { return $this->transferBucketsCount; }
    public function getWriteOffQuantity(): string { return $this->writeOffQuantity; }
    public function setWriteOffQuantity(string|int|float $writeOffQuantity): self { $this->writeOffQuantity = QuantityScale::canonical(max(0, (float) $writeOffQuantity)); return $this; }
    public function setDepthBucketsCount(bool $counts): self { $this->depthBucketsCount = $counts; return $this; }

    /** Same reason as transferBucketsCount() above (#36). */
    public function depthBucketsCount(): bool { return $this->depthBucketsCount; }
    public function getQuarantineQuantity(): string { return $this->quarantineQuantity; }
    public function setQuarantineQuantity(string|int|float $quarantineQuantity): self { $this->quarantineQuantity = QuantityScale::canonical(max(0, (float) $quarantineQuantity)); return $this; }

    public function getIncomingQuantity(): string { return $this->incomingQuantity; }
    public function setIncomingQuantity(string|int|float $incomingQuantity): self { $this->incomingQuantity = QuantityScale::canonical($incomingQuantity); return $this; }

    /**
     * Set by BundleBucketAvailabilityGate on load. Not persisted, and not something call sites
     * should be setting — the gate is one decision for the whole instance, made in one place.
     */
    public function setPositiveBucketsCount(bool $positiveBucketsCount): self { $this->positiveBucketsCount = $positiveBucketsCount; return $this; }
    public function positiveBucketsCount(): bool { return $this->positiveBucketsCount; }

    /**
     * Stock on hand that no REAL hold is standing on — availability with the promises added back.
     *
     * What a backorder release has to draw on, and NOT getAvailableQuantity() (#548). A promised
     * unit is subtracted from availability precisely so nobody else can take it; measuring the
     * release against that figure would mean a restock that exactly covers the queue reads as zero
     * spare and releases nothing, leaving the stock sitting next to the orders it arrived for.
     *
     * The distinction is "who is this stock for": availability answers it for a NEW order, this
     * answers it for the ones already waiting.
     */
    public function getStockAvailableToRelease(): string
    {
        return QuantityScale::canonical(
            (float) $this->getAvailableQuantity() + (float) $this->backorderedQuantity,
        );
    }

    /**
     * How much more may still be promised here — PHP_INT_MAX when no cap is configured.
     *
     * $ownBackorderHold is what the order being validated already contributes to
     * $backorderedQuantity, added back so re-saving an unchanged order never blocks against its own
     * prior promise. Exactly the reason AdminOrderStockValidator adds an order's stock hold back;
     * this is the same mistake on the other bucket.
     */
    public function remainingBackorderCapacity(string|int|float $ownBackorderHold = 0): string
    {
        if ($this->maxBackorderQuantity === null) {
            return self::UNCAPPED_BACKORDER_CAPACITY;
        }

        return QuantityScale::canonical(
            (float) $this->maxBackorderQuantity
            - max(0.0, (float) $this->backorderedQuantity - max(0.0, (float) $ownBackorderHold)),
        );
    }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function touch(): self { $this->updatedAt = new \DateTimeImmutable(); return $this; }

    /** Feeds AuditLogSubscriber's automatic label resolution so audit entries read like "SKU123 @ West" instead of a bare id. */
    public function getLabel(): string
    {
        return sprintf(
            '%s @ %s',
            $this->product->getSku() ?: ('#' . ($this->product->getId() ?? '?')),
            $this->warehouse?->getName() ?? 'All warehouses',
        );
    }
}
