<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use App\Entity\Warehouse;
use App\Service\QuantityScale;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use InventoryDepthBundle\Entity\InventoryMovementGroup;

/**
 * What actually turned up (#555). The hinge of the whole job.
 *
 * ## This does not write stock
 *
 * `$movementGroup` is the join to #550, and it is a record of a call rather than a substitute for
 * one. ReceivingService builds an `InventoryDepthBundle\Movement\MovementRequest` and hands it to
 * `StockMovementService::apply()` — the same service the adjustment screen and the cycle count call
 * — and stores the group it gets back here. One implementation of "stock entered", three ways to
 * trigger it.
 *
 * That is not tidiness. A second path into `inventory_detail` diverges on validation and on audit,
 * and receiving is precisely where lot, expiry and serial capture must be enforced identically to
 * everywhere else. It is also what keeps this bundle removable: delete it and stock still enters
 * the building through the adjustment screen, which is how #550 says stock enters before receiving
 * exists.
 *
 * The group id is nullable because a receipt can legitimately record goods that produced no
 * movement at all — see GoodsReceiptLine::$movementApplied.
 *
 * ## A receipt without a purchase order is legitimate
 *
 * `$purchaseOrder` is nullable on purpose. Goods arrive that nobody raised a PO for — a sample, a
 * warranty replacement, a delivery against a phone call — and refusing to record them means the
 * warehouse is wrong. It is flagged on the three-way match rather than blocked at the door.
 *
 * ## A receipt is never edited. It is voided (#613)
 *
 * There is still no correction path and no `updated_at`. A receipt states what was on the dock at a
 * moment, and the movements it caused are already in a ledger that only appends. What #613 added is
 * the one thing an adjustment could never do: a receipt booked to the wrong purchase order or the
 * wrong warehouse leaves `purchase_order_line.quantity_received`, the derived PO status and every
 * three-way match permanently wrong, and no amount of adjusting stock fixes the paperwork.
 *
 * So a void is a SECOND FACT, in exactly the discipline above:
 *
 *  - the stock is put back by a NEW movement group, not by unwriting the old one. `$movementGroup`
 *    still names what the receipt did; `$voidMovementGroup` names what undid it, and both are in
 *    the ledger forever;
 *  - the receipt's own lines are untouched. It still says what was on the dock;
 *  - the three stamps below are the whole of the change to this row, and `voided_at` is the flag
 *    every reader goes by. Nothing is deleted, and the receipt keeps its number — which is the same
 *    bargain a cancelled purchase order and a voided vendor bill already strike.
 *
 * `purchase_order_line.quantity_received` IS decremented, because it is not a ledger: it is the
 * maintained running total ReceivingService credits, and the whole reason it is maintained rather
 * than summed is that it is written in the same transaction as the receipt that changed it. A void
 * is such a change.
 *
 * ## Deliberately outside CommercialDocument, for now (#636)
 *
 * #636 made every document implement `App\Contract\Document\CommercialDocument`. This one does not,
 * and the omission is argued rather than accidental —
 * `App\Tests\Architecture\EveryDocumentDeclaresItsContractTest` names it as an exclusion so that a
 * FUTURE document cannot skip the contract in the same silence.
 *
 * What it cannot answer today: the contract wants a currency and a total, and a receipt stores
 * neither. It should not. The money is the `VendorBill`, and the three-way match works precisely
 * BECAUSE the receipt and the bill are two documents that can disagree; a total on the receipt
 * would be a second, drifting copy of the bill's. A receipt raised against no purchase order at
 * all — which this class explicitly allows — has no price to copy in the first place. Implementing
 * the contract would therefore mean inventing a '0.00' to satisfy an interface.
 *
 * It does not extend `AbstractPurchaseDocument` either, and could not usefully: that base exists to
 * hold `currency`, `subtotal`, `tax`, `total` and a calendar `document_date`, and a receipt has
 * none of those. What it shares with a bill is a vendor and a vendor-name snapshot, which is two
 * columns, not a base class.
 *
 * ## Why "for now", and not "never"
 *
 * A receipt is the one document in the estate that is genuinely BOTH logistical and commercial.
 * Goods received and not yet billed is a real liability — it is what the three-way match on
 * `/admin/bundles/procurement/exceptions` surfaces, and what SAP calls GR/IR clearing — because
 * these goods crossed the company boundary, and that crossing is the accounting event. A warehouse
 * transfer, whose two ends are both ours, creates no such obligation and never will.
 *
 * So the eventual answer is probably that a receipt implements a goods/logistical contract AND a
 * commercial one, which is an argument for interfaces over abstract bases: a class extends one base
 * and implements as many contracts as it truthfully satisfies. That second contract is parked, not
 * declined. Until it exists, a receipt says what it is here and in the exclusion list, and states
 * no money it does not have.
 */
#[ORM\Entity(repositoryClass: \ProcurementBundle\Repository\GoodsReceiptRepository::class)]
#[ORM\Table(name: 'goods_receipt')]
#[ORM\UniqueConstraint(name: 'uniq_receipt_number', fields: ['receiptNumber'])]
#[ORM\UniqueConstraint(name: 'uniq_receipt_operation', fields: ['clientOperationId'])]
#[ORM\Index(name: 'idx_receipt_po', fields: ['purchaseOrder'])]
#[ORM\Index(name: 'idx_receipt_vendor', fields: ['vendor'])]
#[ORM\Index(name: 'idx_receipt_received', fields: ['receivedAt'])]
class GoodsReceipt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'receipt_number', length: 32)]
    private string $receiptNumber = '';

    /**
     * The caller's idempotency key for the submission that produced this receipt.
     *
     * Its own column rather than being inferred from `$movementGroup`'s, which is where this
     * started and where it was quietly wrong: a delivery of nothing but `simple`-inventory products
     * writes no movement group at all, so there was nothing to recognise a resubmission by, and a
     * double-submitted form would have written a second receipt for the same goods. Idempotency
     * that only works when stock happened to move is the kind that fails on the one delivery nobody
     * checked.
     *
     * Nullable, with a unique index that lets NULLs repeat — a receipt written by a command or an
     * import with no natural key is not a duplicate of every other one.
     */
    #[ORM\Column(name: 'client_operation_id', length: 64, nullable: true)]
    private ?string $clientOperationId = null;

    /** Null is a real answer — see the class docblock. */
    #[ORM\ManyToOne(targetEntity: PurchaseOrder::class, inversedBy: 'receipts')]
    #[ORM\JoinColumn(name: 'purchase_order_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?PurchaseOrder $purchaseOrder = null;

    #[ORM\ManyToOne(targetEntity: Vendor::class)]
    #[ORM\JoinColumn(name: 'vendor_id', referencedColumnName: 'id', nullable: false)]
    private Vendor $vendor;

    /** Snapshotted for the reason every counterparty name on a document is. */
    #[ORM\Column(name: 'vendor_name', length: 200)]
    private string $vendorName = '';

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(name: 'warehouse_id', referencedColumnName: 'id', nullable: false)]
    private Warehouse $warehouse;

    /**
     * The movement group #550 wrote for this receipt.
     *
     * `SET NULL` and not `CASCADE`: **data outlives the bundle**, and it outlives its neighbour's
     * data too. Losing the movement history must never delete the record that goods arrived. The
     * two are separate facts and the join between them is the only part that is allowed to go.
     */
    #[ORM\ManyToOne(targetEntity: InventoryMovementGroup::class)]
    #[ORM\JoinColumn(name: 'movement_group_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?InventoryMovementGroup $movementGroup = null;

    /** Their paperwork's own number, which is what a discrepancy conversation starts from. */
    #[ORM\Column(name: 'packing_slip', length: 80, nullable: true)]
    private ?string $packingSlip = null;

    /** A real instant, unlike a document date: goods arrive at a time, on a dock. */
    #[ORM\Column(name: 'received_at')]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(name: 'received_by', length: 160, nullable: true)]
    private ?string $receivedBy = null;

    /**
     * Where the goods left from, frozen at receipt (#606).
     *
     * Nothing recorded this before, so a supplier who shipped from a depot that is not their head
     * office produced a receipt that could not say which depot — exactly the fact you want when a
     * delivery is late or short, and the one #597's lead times will need.
     *
     * A frozen copy and not a join, for the same reason `PurchaseOrder::$vendorAddress` is one:
     * editing the depot's address changes what the NEXT delivery says and nothing about a delivery
     * that already happened.
     *
     * NULL on every receipt that exists, and NULL on any new receipt from a vendor with no address
     * at all — "we did not record it" has to stay distinguishable from "it was blank".
     */
    #[ORM\Column(name: 'ship_from_address', type: 'text', nullable: true)]
    private ?string $shipFromAddress = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    /**
     * When this receipt was withdrawn, and the flag every reader goes by (#613).
     *
     * Null on every receipt that has ever existed, which is what makes the column inert: a receipt
     * with no void stamp behaves exactly as it did before this column was added.
     */
    #[ORM\Column(name: 'voided_at', nullable: true)]
    private ?\DateTimeImmutable $voidedAt = null;

    #[ORM\Column(name: 'voided_by', length: 160, nullable: true)]
    private ?string $voidedBy = null;

    /**
     * Why. Required by the void action rather than by the column, for the reason
     * PurchaseOrder::closeShort() demands one: withdrawing a document that moved stock is a
     * decision, and a decision with no reason on it is unauditable a fortnight later.
     */
    #[ORM\Column(name: 'void_reason', type: 'text', nullable: true)]
    private ?string $voidReason = null;

    /**
     * The movement group that put the stock back — the second fact, beside `$movementGroup` and
     * never in place of it.
     *
     * `SET NULL` for the same reason `$movementGroup` is: losing the movement history must never
     * delete the record that a receipt was withdrawn.
     */
    #[ORM\ManyToOne(targetEntity: InventoryMovementGroup::class)]
    #[ORM\JoinColumn(name: 'void_movement_group_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?InventoryMovementGroup $voidMovementGroup = null;

    /** @var Collection<int, GoodsReceiptLine> */
    #[ORM\OneToMany(targetEntity: GoodsReceiptLine::class, mappedBy: 'receipt', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lines;

    public function __construct()
    {
        $this->receivedAt = new \DateTimeImmutable();
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getReceiptNumber(): string { return $this->receiptNumber; }
    public function setReceiptNumber(string $receiptNumber): self { $this->receiptNumber = $receiptNumber; return $this; }
    public function getClientOperationId(): ?string { return $this->clientOperationId; }

    /** Empty is stored as null — "no key given" is not a key, and one stored '' would swallow every later keyless delivery. */
    public function setClientOperationId(?string $clientOperationId): self
    {
        $clientOperationId = trim((string) $clientOperationId);
        $this->clientOperationId = $clientOperationId === '' ? null : $clientOperationId;

        return $this;
    }

    public function getPurchaseOrder(): ?PurchaseOrder { return $this->purchaseOrder; }
    public function setPurchaseOrder(?PurchaseOrder $purchaseOrder): self { $this->purchaseOrder = $purchaseOrder; return $this; }
    public function getVendor(): Vendor { return $this->vendor; }

    public function setVendor(Vendor $vendor): self
    {
        $this->vendor = $vendor;
        if ($this->vendorName === '') {
            $this->vendorName = $vendor->getName();
        }

        return $this;
    }

    public function getVendorName(): string { return $this->vendorName; }
    public function setVendorName(string $vendorName): self { $this->vendorName = $vendorName; return $this; }
    public function getWarehouse(): Warehouse { return $this->warehouse; }
    public function setWarehouse(Warehouse $warehouse): self { $this->warehouse = $warehouse; return $this; }
    public function getMovementGroup(): ?InventoryMovementGroup { return $this->movementGroup; }
    public function setMovementGroup(?InventoryMovementGroup $movementGroup): self { $this->movementGroup = $movementGroup; return $this; }
    public function getPackingSlip(): ?string { return $this->packingSlip; }
    public function setPackingSlip(?string $packingSlip): self { $this->packingSlip = $packingSlip; return $this; }
    public function getReceivedAt(): \DateTimeImmutable { return $this->receivedAt; }
    public function setReceivedAt(\DateTimeImmutable $receivedAt): self { $this->receivedAt = $receivedAt; return $this; }
    public function getReceivedBy(): ?string { return $this->receivedBy; }
    public function setReceivedBy(?string $receivedBy): self { $this->receivedBy = $receivedBy; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): self { $this->notes = $notes; return $this; }
    public function getShipFromAddress(): ?string { return $this->shipFromAddress; }
    public function setShipFromAddress(?string $shipFromAddress): self { $this->shipFromAddress = $shipFromAddress; return $this; }

    /** @return Collection<int, GoodsReceiptLine> */
    public function getLines(): Collection { return $this->lines; }

    public function addLine(GoodsReceiptLine $line): self
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
            $line->setReceipt($this);
        }

        return $this;
    }

    public function getVoidedAt(): ?\DateTimeImmutable { return $this->voidedAt; }
    public function getVoidedBy(): ?string { return $this->voidedBy; }
    public function getVoidReason(): ?string { return $this->voidReason; }
    public function getVoidMovementGroup(): ?InventoryMovementGroup { return $this->voidMovementGroup; }

    /** Has this receipt been withdrawn. The one question every reader asks about the stamps. */
    public function isVoided(): bool
    {
        return $this->voidedAt !== null;
    }

    /**
     * Stamps the void onto the row.
     *
     * A named action rather than three setters, so that "voided" cannot be half-true: there is no
     * way to write `voided_at` without saying who and why, and no way to write the reason without
     * the row actually being void. The stock half is ReceiptVoidService's — this records the
     * decision, and the service is the only caller.
     *
     * @throws \LogicException on a second void, which would put the stock back twice
     */
    public function markVoided(?string $actor, string $reason, ?InventoryMovementGroup $group): self
    {
        if ($this->voidedAt !== null) {
            throw new \LogicException(sprintf('%s was already voided on %s.', $this->receiptNumber, $this->voidedAt->format('Y-m-d H:i')));
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new \LogicException('Voiding a receipt needs a reason — it is a decision, and one with nothing on it is unauditable.');
        }

        $this->voidedAt = new \DateTimeImmutable();
        $this->voidedBy = $actor;
        $this->voidReason = $reason;
        $this->voidMovementGroup = $group;

        return $this;
    }

    /** Goods that arrived with nobody having asked for them. Flagged on the match, never blocked. */
    public function isUnordered(): bool
    {
        return $this->purchaseOrder === null;
    }

    /** Total units booked in on this receipt, across every line. */
    public function getTotalQuantity(): string
    {
        $total = QuantityScale::canonical(0);
        foreach ($this->lines as $line) {
            $total = QuantityScale::add($total, $line->getQuantity());
        }

        return $total;
    }

    /**
     * Lines that landed in the warehouse's unspecified row rather than a named bin/lot/serial — see
     * GoodsReceiptLine::$movementApplied. `received` was still credited and a detail row still
     * written for these; nothing specific was just said about where.
     *
     * @return list<GoodsReceiptLine>
     */
    public function getLinesNotStocked(): array
    {
        $rows = [];
        foreach ($this->lines as $line) {
            if (!$line->isMovementApplied()) {
                $rows[] = $line;
            }
        }

        return $rows;
    }

    /**
     * The short-dated overrides on this receipt — the lines somebody accepted inside the minimum
     * shelf life, with the reason each was accepted for (item 68).
     *
     * Walked off the lines rather than given a foreign key of its own. The exception belongs to a
     * LINE — one line, one expiry, one shortfall — and a second `goods_receipt_id` column on it
     * would be the receipt stored twice, reachable two ways, able to disagree.
     *
     * @return list<ShortDatedReceipt>
     */
    public function getShortDated(): array
    {
        $rows = [];
        foreach ($this->lines as $line) {
            $short = $line->getShortDated();
            if ($short instanceof ShortDatedReceipt) {
                $rows[] = $short;
            }
        }

        return $rows;
    }

    /**
     * The subset of those that arrived already EXPIRED (item 69).
     *
     * A narrower read of the same rows rather than a second collection: the grid marks the two
     * findings differently because they are differently bad, and "how many of these were dead on
     * arrival" is not answerable from a count of the whole list.
     *
     * @return list<ShortDatedReceipt>
     */
    public function getExpiredOnArrival(): array
    {
        return array_values(array_filter(
            $this->getShortDated(),
            static fn (ShortDatedReceipt $row): bool => $row->isAlreadyExpired(),
        ));
    }

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        return $this->receiptNumber !== '' ? $this->receiptNumber : '#' . (string) $this->id;
    }
}
