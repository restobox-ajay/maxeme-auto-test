<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use App\Entity\DenominatedQuantity;
use App\Entity\ProductCore;
use App\Enum\SalesOrderLineFulfillmentStatus;
use App\Service\QuantityScale;
use App\Service\Uom\LineDenomination;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One product row on a purchase order (#555) — what we asked for, and how much of it has turned up.
 *
 * ## No foreign key to product_core, deliberately
 *
 * The join column is mapped and the migration emits **no** `FOREIGN KEY` for it, which is exactly
 * what `sales_order_line` does and for exactly the same reason. A document line snapshots what was
 * bought and has to outlive the product's deletion: product imports delete rows routinely, and this
 * database already holds two thousand order lines naming products that no longer exist, every one a
 * correct record of something that was bought or sold. An FK there made a migration unrunnable
 * once; see the "No foreign key here, still" note on migrations/Version20260823102000.php.
 *
 * `name`, `sku` and `unitCost` are frozen copies for the same reason. A PO printed today and read
 * in three years has to say what it said when it was sent.
 *
 * ## quantityReceived is maintained, not derived
 *
 * It could be summed from the receipt lines pointing here, and it is not: the arrivals view, the
 * open-PO list and the exception screens all filter and sort on "what is still outstanding", which
 * a method cannot do. It is written in the same transaction as the receipt that changed it — by
 * ReceivingService, which is the only thing that touches it — so the two cannot drift. That is the
 * same bargain `product_inventory.quantity` strikes with `inventory_detail` one layer down.
 *
 * ## quantityBilled is DERIVED, and quantityReceived is not
 *
 * #658 asked for a `quantityBilled` column beside `quantity_received`. It is a method instead, and
 * the difference between the two figures is the reason.
 *
 * `quantityReceived` is maintained because goods arriving is an EVENT with exactly one writer —
 * `ReceivingService`, in the same transaction as the receipt — and because the arrivals view, the
 * open-PO list and the exception screens all filter and sort on what is still outstanding, which a
 * method cannot do.
 *
 * Billing has neither property. A bill is EDITABLE while it is a draft, it can be voided, and
 * whether it counts at all depends on its status — so a stored figure would need writing at save,
 * at approve, at void, at dispute and at every line the save rebuilt, and the one place that forgot
 * would leave the column claiming a PO line was billed for something no bill says. The sell side
 * settled this question already and settled it this way: *"a stored 'uninvoiced quantity' column is
 * a second answer to a question the invoice lines already answer, and it drifts the first time an
 * invoice is edited"* — `SalesOrder`'s invoiced-quantity block. Two places holding one fact is also
 * the shape behind #589, #590 and #591 in this repository.
 *
 * So the figure is summed from the bill lines that point here, exactly as `SalesOrder` sums its
 * invoice lines, and nothing can disagree with it because nothing else holds it.
 *
 * ## Quantities are decimals
 *
 * Purchase quantities divide: cases, weights, partial units. `NUMERIC(14, 4)` matches
 * `sales_order_line.quantity`; `unitCost` carries six places because a case cost divided by units
 * per case rarely lands on two, and rounding it at the line is how a bill stops matching its PO.
 * Both widened in #645 — four decimals so a third of a case is expressible, six so a per-unit price
 * derived from a per-case one keeps its residue below a cent.
 */
#[ORM\Entity]
#[ORM\Table(name: 'purchase_order_line')]
#[ORM\Index(name: 'idx_po_line_unit', fields: ['unitOfMeasure'])]
#[ORM\Index(name: 'idx_po_line_order', fields: ['purchaseOrder'])]
#[ORM\Index(name: 'idx_po_line_product', fields: ['product'])]
class PurchaseOrderLine implements PurchasedDocumentLine
{
    use DenominatedQuantity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PurchaseOrder::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'purchase_order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private PurchaseOrder $purchaseOrder;

    /** Nullable, and with no FK behind it — see the class docblock. */
    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: true)]
    private ?ProductCore $product = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    /** Our SKU, snapshotted. */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $sku = null;

    /**
     * What THEY call it.
     *
     * Not decoration: vendors send confirmations and packing slips in their own part numbers, and
     * matching those to our SKUs by hand is where receiving errors come from.
     */
    #[ORM\Column(name: 'vendor_sku', length: 80, nullable: true)]
    private ?string $vendorSku = null;

    // Spelt at the column's own scale, not at two places: the column is `decimal(14, 4)` and every
    // writer now goes through `App\Service\QuantityScale`, so a line that has never been written
    // must read the same shape as one that has.
    #[ORM\Column(name: 'quantity_ordered', type: 'decimal', precision: 14, scale: 4)]
    private string $quantityOrdered = '0.0000';

    #[ORM\Column(name: 'quantity_received', type: 'decimal', precision: 14, scale: 4, options: ['default' => '0.00'])]
    private string $quantityReceived = '0.0000';

    #[ORM\Column(name: 'unit_cost', type: 'decimal', precision: 18, scale: 6)]
    private string $unitCost = '0.0000';

    /**
     * Legacy free-text U/M snapshot — full parity with SalesOrderLine::$unit/InvoiceLine::$unit.
     *
     * Not the real unit: {@see DenominatedQuantity::$unitOfMeasure} (`unit_id`) is. This is the
     * per-row label a blank line types for itself, and the fallback {@see LineDenomination::label()}
     * reads when a product row names no unit at all — see that method's own docblock for why a
     * typed string beats the product's live base unit code on a document that already prints.
     */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $unit = null;

    /** Per-line shipping weight — full parity with SalesOrderLine::$weight/InvoiceLine::$weight. */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $weight = null;

    /**
     * Which warehouse THIS line is received at, overriding the order's own `$warehouse` — full
     * parity with SalesOrderLine::$location, adapted to the buy side's own destination concept.
     *
     * SalesOrderLine's `location` picks a fulfillment REGION because that is what decides which of
     * the company's stock pools a line draws from; a purchase order has no regions, but it does
     * have the same shape of question — which of several warehouses this particular line's goods
     * are going to — so the list this picks from is warehouse NAMES, not region names, and NULL
     * means what it always means here: read the order's own `$warehouse`.
     *
     * A plain string snapshot rather than a Warehouse FK, for the same reason `$unit` is a string
     * and not a live FK: a line printed today has to say what it said, and a warehouse renamed or
     * retired next year must not silently restate a PO issued last year.
     */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $subtotal = '0.00';

    /**
     * What tax class this line is bought under — 'E', 'G' or 'S' (#655).
     *
     * The SAME vocabulary and the same column as `sales_order_line.tax_code`, because the owner's
     * ruling is that tax is shared: a taxable good is taxable whether we are buying it or selling
     * it, and the rules are driven by the class rather than by the direction of the transaction. It
     * is read by `App\Contract\Tax\TaxContext` and priced by the very same calculators the sell
     * side uses.
     *
     * Nullable, and null means Exempt — `TaxContext::mapTaxCode()` maps a null or empty code to
     * 'E'. That is the only default that cannot overcharge, and it is what every purchase order
     * line written before this column existed truthfully meant: nothing was taxing them.
     *
     * Defaulted at save from `ProductCore::getSalesTaxCode()`, the product's own class, which an
     * admin can then override on the row. "salesTaxCode" is the sell side's name for a fact about
     * the PRODUCT rather than about the sale, which is exactly why reading it here is not a
     * borrowing — it is the same column answering the same question.
     */
    #[ORM\Column(name: 'tax_code', length: 8, nullable: true)]
    private ?string $taxCode = null;

    /**
     * The lot/serial actually ordered — full parity with SalesOrderLine::$batch/InvoiceLine::$batch.
     *
     * Same shape, same rule: a single free-text snapshot, never a structured lot/serial/expiry set
     * of columns, because that is what the sell side's own `batch` column is. It is a different,
     * lighter-weight fact than the Receiving screen's CaptureRequirement-driven identity capture —
     * see `_receive_row.html.twig` — which stays the authoritative record of what physically arrived.
     */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $batch = null;

    #[ORM\Column(name: 'sort_order', options: ['default' => 0])]
    private int $sortOrder = 0;

    /**
     * The bill lines charging against this row — the inverse of `VendorBillLine::$purchaseOrderLine`.
     *
     * Mapping only: the owning side is the bill line's existing `purchase_order_line_id`, so this
     * adds no column and needs no migration. Not cascaded and not orphanRemoval, for the reason
     * `PurchaseOrder::$receipts` is neither: a bill is a document in its own right and deleting a
     * purchase order must never delete the record of what a vendor charged.
     *
     * @var Collection<int, VendorBillLine>
     */
    #[ORM\OneToMany(targetEntity: VendorBillLine::class, mappedBy: 'purchaseOrderLine')]
    private Collection $billLines;

    public function __construct()
    {
        $this->billLines = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getPurchaseOrder(): PurchaseOrder { return $this->purchaseOrder; }
    public function setPurchaseOrder(PurchaseOrder $purchaseOrder): self { $this->purchaseOrder = $purchaseOrder; return $this; }
    public function getProduct(): ?ProductCore { return $this->product; }
    public function setProduct(?ProductCore $product): self { $this->product = $product; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getSku(): ?string { return $this->sku; }
    public function setSku(?string $sku): self { $this->sku = $sku; return $this; }
    public function getVendorSku(): ?string { return $this->vendorSku; }
    public function setVendorSku(?string $vendorSku): self { $this->vendorSku = $vendorSku; return $this; }
    public function getQuantityOrdered(): string { return $this->quantityOrdered; }
    /**
     * Restating the base figure forgets how it was entered (#601, #646).
     *
     * Writing this column directly says "the row is this many BASE units", and the only
     * truthful entered figure for that is the same number in base units — which is what
     * `quantity_entered` NULL and `unit_id` NULL mean. See DenominatedQuantity.
     */
    public function setQuantityOrdered(string $quantityOrdered): self
    {
        $this->quantityOrdered = $quantityOrdered;
        $this->forgetEnteredExpression();

        return $this;
    }
    public function getQuantityReceived(): string { return $this->quantityReceived; }

    /**
     * Written only by ReceivingService, in the same transaction as the receipt that moved it.
     *
     * Public because PHP has no friend classes and a service is the right owner; narrow enough that
     * misuse is loud. Nothing on an admin form posts to this.
     */
    public function setQuantityReceived(string $quantityReceived): self { $this->quantityReceived = $quantityReceived; return $this; }

    public function getUnitCost(): string { return $this->unitCost; }
    public function setUnitCost(string $unitCost): self { $this->unitCost = $unitCost; return $this; }
    public function getUnit(): ?string { return $this->unit; }
    public function setUnit(?string $unit): self { $this->unit = $unit; return $this; }
    public function getWeight(): ?string { return $this->weight; }
    public function setWeight(?string $weight): self { $this->weight = $weight; return $this; }
    public function getLocation(): ?string { return $this->location; }
    public function setLocation(?string $location): self { $this->location = $location; return $this; }
    public function getSubtotal(): string { return $this->subtotal; }
    public function setSubtotal(string $subtotal): self { $this->subtotal = $subtotal; return $this; }
    public function getTaxCode(): ?string { return $this->taxCode; }
    public function setTaxCode(?string $taxCode): self { $this->taxCode = $taxCode; return $this; }
    public function getBatch(): ?string { return $this->batch; }
    public function setBatch(?string $batch): self { $this->batch = $batch; return $this; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): self { $this->sortOrder = $sortOrder; return $this; }

    /**
     * `quantity_ordered − quantity_received`, floored at zero.
     *
     * The whole vendor-backorder model. This reads the same way to a user as #548's customer
     * backorder and is **not the same mechanism**: nothing is reserved, no customer is promised
     * anything, and no bucket on `product_inventory` is involved. It is arithmetic on two columns.
     *
     * Floored because over-receipt is allowed — 250 arriving against a PO for 240 is recorded and
     * flagged, not refused, since refusing means the warehouse cannot say what is on the dock. A
     * negative outstanding would then read as "we still owe them ten", which is the opposite of
     * what happened.
     */
    public function getQuantityOutstanding(): string
    {
        $outstanding = QuantityScale::sub($this->quantityOrdered, $this->quantityReceived);

        return QuantityScale::compare($outstanding, 0) > 0 ? $outstanding : QuantityScale::canonical(0);
    }

    /** Has this line had everything it asked for — or more. */
    public function isComplete(): bool
    {
        return QuantityScale::compare($this->quantityReceived, $this->quantityOrdered) >= 0;
    }

    /** Has anything at all arrived against it. */
    public function hasReceipts(): bool
    {
        return QuantityScale::compare($this->quantityReceived, 0) > 0;
    }

    /**
     * The buy-side reading of the same status SalesOrderLine::$fulfillmentStatus caches (#548) —
     * reused rather than reimplemented, because "how much of a quantity is still outstanding
     * against how much was wanted" is the identical arithmetic in both directions: SalesOrderLine
     * asks it of quantity-vs-backordered, this asks it of quantity-vs-outstanding.
     *
     * Derived on every read and never stored, unlike SalesOrderLine's own cached column — this
     * line already derives every other billing/receiving figure the same way (see
     * getQuantityBilled()'s own docblock on why a stored copy of a two-column fact is a second
     * answer that can drift), and a receipt booked in by ReceivingService already recomputes
     * `quantityReceived` in the one transaction that changes it, so there is no write path this
     * would need to hook that isn't already covered by reading fresh.
     */
    public function getFulfillmentStatus(): SalesOrderLineFulfillmentStatus
    {
        return SalesOrderLineFulfillmentStatus::forQuantities(
            $this->quantityOrdered,
            $this->getQuantityOutstanding(),
        );
    }

    /** More arrived than was ordered. Recorded and flagged on the match, never refused. */
    public function isOverReceived(): bool
    {
        return QuantityScale::compare($this->quantityReceived, $this->quantityOrdered) > 0;
    }

    /**
     * Is this line's unit cost settled money — the price half of the editing rules (#47).
     *
     * **Bills, not receipts, and the distinction is the whole point.** Receiving records that goods
     * arrived on a dock; it says nothing at all about what is owed for them. Billing is where a
     * price stops being our expectation and becomes a figure somebody has accepted, so a line that
     * has been received but not yet billed is still price-correctable — which is the common case
     * this exists to allow. A PO issued at the wrong price with one receipt against it has a
     * correction path, where collapsing the two events into one "has been touched" test would leave
     * it with none.
     *
     * Read through `getQuantityCharged()`, which counts bills that {@see VendorBillStatus::counts()}
     * — everything but a Draft and a Void. Deliberately NOT `getQuantityBilled()`, which is the
     * wider "claims quantity against this line" rule and includes drafts: a draft authorises
     * nothing, and a price somebody is halfway through typing must not freeze the order it is being
     * typed against. That is the same split the two methods were written for, read from the side
     * that asks about money.
     *
     * What happens instead of a refusal, once a bill IS in: the bill's own price is what is owed,
     * and the disagreement is reported as `MatchLine::EXCEPTION_PRICE_VARIANCE` by
     * ThreeWayMatchService. A bill at a price the order did not expect is never refused — it is the
     * exception a buyer is meant to look at.
     */
    public function isPriceSettled(): bool
    {
        return QuantityScale::compare($this->getQuantityCharged(), 0) > 0;
    }

    /** @return Collection<int, VendorBillLine> */
    public function getBillLines(): Collection { return $this->billLines; }

    /**
     * Both halves of the attribution, moved together.
     *
     * @internal called only by VendorBillLine::setPurchaseOrderLine(), which is the owning side.
     *           There is deliberately no public adder: a bill line belongs to a bill, and attaching
     *           one from this end would create a charge nobody had entered.
     */
    public function linkBillLine(VendorBillLine $line): void
    {
        if (!$this->billLines->contains($line)) {
            $this->billLines->add($line);
        }
    }

    /** @internal counterpart of linkBillLine(). */
    public function unlinkBillLine(VendorBillLine $line): void
    {
        $this->billLines->removeElement($line);
    }

    /**
     * How much of this line has been billed, in BASE units — the figure #658 asked for as a column.
     *
     * Derived on every read and never stored; see the class docblock for why. Summed from
     * `VendorBillLine::$quantity`, which is the base figure under UoM phase 3 — a bill line entered
     * as 40 Cases of a 12-pack resolves to 480 there and counts as 480 here, so a document entered
     * in one denomination cannot out-bill a purchase order written in another.
     *
     * ## Which bills count, and why a draft does
     *
     * Every bill except a VOID one. A void bill is withdrawn and charges nothing; anything else —
     * including a draft somebody is still typing — is a claim on this line, and two drafts each
     * claiming the whole line is precisely the over-billing this exists to refuse. The guard would
     * be blind to the second one otherwise, and both would then approve.
     *
     * That is deliberately NOT `VendorBillStatus::counts()`, which answers a different question —
     * "is this a charge against the business" — and correctly excludes drafts, because an accrual
     * must not vanish the moment somebody starts typing a bill for it. Two questions, two rules,
     * each stated where it is used. See `VendorBillStatus::claimsOrderedQuantity()`.
     *
     * @param ?VendorBill $excluding a bill whose own lines do not count — the one being saved, which
     *                               must not be measured against the quantity it itself already holds
     */
    public function getQuantityBilled(?VendorBill $excluding = null): string
    {
        $billed = QuantityScale::canonical(0);
        foreach ($this->billLines as $billLine) {
            if (!$billLine->isAttached()) {
                // Mid-save: attributed to this line, not yet handed to its bill. It has no status
                // to consult and no document behind it, so it claims nothing until it does.
                continue;
            }

            $bill = $billLine->getBill();
            if (!$bill->getStatusEnum()->claimsOrderedQuantity()) {
                continue;
            }

            if ($excluding instanceof VendorBill && $bill === $excluding) {
                continue;
            }

            $billed = QuantityScale::add($billed, $billLine->getQuantity());
        }

        return $billed;
    }

    /**
     * How much of this line LIVE bills charge for — drafts and void bills excluded (#658).
     *
     * The other of the two billed figures, and the one the exception and accrual screens ask for.
     * It answers "what is a charge against the business", where getQuantityBilled() above answers
     * "what is claimed against this line". They differ on the draft, deliberately: an accrual for
     * goods received must not disappear the moment somebody starts typing the bill for them, and a
     * second draft bill must not be able to claim a line the first draft already claimed in full.
     *
     * See `VendorBillStatus::counts()` for the rule this reads, which is the rule
     * `ExceptionController` has always applied to the same question — it is now stated once and
     * read from both places rather than being a query that screen wrote for itself.
     */
    public function getQuantityCharged(): string
    {
        $charged = QuantityScale::canonical(0);
        foreach ($this->billLines as $billLine) {
            if (!$billLine->isAttached() || !$billLine->getBill()->getStatusEnum()->counts()) {
                continue;
            }

            $charged = QuantityScale::add($charged, $billLine->getQuantity());
        }

        return $charged;
    }

    /**
     * Is this line charged for more than any document supports — the over-billing that has already
     * happened, as opposed to the one the guard refuses (#658).
     *
     * The comparison is against the GREATER of ordered and received, and both terms are needed:
     *
     *  - against ORDERED, because two bills of 100 each against a purchase order for 100 is the
     *    classic double payment, and nothing else reports it — the three-way match compares one
     *    bill line at a time and has no term for what other bills already hold;
     *  - against RECEIVED, because over-receipt is deliberately allowed here (250 arriving against
     *    a PO for 240 is recorded and flagged, never refused) and paying for what actually turned
     *    up is legitimate. Comparing only with ordered would report every accepted over-delivery as
     *    an over-billing.
     *
     * Past both, there is no purchase order and no receipt saying we owe it.
     */
    public function isOverBilled(): bool
    {
        $supported = QuantityScale::compare($this->quantityOrdered, $this->quantityReceived) >= 0
            ? $this->quantityOrdered
            : $this->quantityReceived;

        return QuantityScale::compare($this->getQuantityCharged(), $supported) > 0;
    }

    /**
     * `quantity_ordered − quantity_billed`, floored at zero — what is still billable here.
     *
     * Floored for the reason `SalesOrder::uninvoicedQuantityFor()` is floored: a bill line may not
     * exceed what remains, so a negative would mean data written before this rule existed, and a
     * negative remainder would then let the NEXT bill quietly borrow quantity back.
     *
     * Measured against what was ORDERED and not against what arrived, which is the same choice the
     * sell side makes. A bill for goods that have not turned up is a real exception with its own
     * name — `billed_not_received` — and the three-way match is where it is reported. Refusing it
     * here as well would make the match screen unreachable for the case it exists to show.
     */
    public function getQuantityUnbilled(?VendorBill $excluding = null): string
    {
        $remaining = QuantityScale::sub($this->quantityOrdered, $this->getQuantityBilled($excluding));

        return QuantityScale::compare($remaining, 0) > 0 ? $remaining : QuantityScale::canonical(0);
    }

    /** Has every ordered unit on this line been billed for — or more. */
    public function isFullyBilled(): bool
    {
        return QuantityScale::compare($this->getQuantityBilled(), $this->quantityOrdered) >= 0;
    }

    /** The base figure #601 denominates everything in: `quantityOrdered`, unchanged by phase 3. */
    public function getQuantityBase(): string { return (string) $this->quantityOrdered; }

    protected function storeQuantityBase(string $base): void { $this->setQuantityOrdered($base); }

    // ── How the line READS (full parity with SalesOrderLine/InvoiceLine, #601/#659) ────────────
    //
    // Everything below is derived at render from the figures the row already stores — the base
    // quantity, the unit, and the per-base rate — and none of it is ever written back.

    /** The code of the unit this line was entered in, or the base unit's when it names none. */
    public function getDisplayUnitLabel(): string
    {
        return LineDenomination::label($this->getUnitOfMeasure(), $this->unit, $this->product);
    }

    /** `EA` — what {@see getQuantityBase()} is counted in. */
    public function getBaseUnitLabel(): string
    {
        return LineDenomination::baseLabel($this->unit, $this->product);
    }

    /**
     * The stored per-base unit cost expressed per {@see getDisplayUnitLabel()} — the buy-side name
     * for what SalesOrderLine::getDisplayUnitPrice() reads, since a purchase document has a cost and
     * no resale price.
     */
    public function getDisplayUnitCost(): ?string
    {
        if ($this->getUnitOfMeasure() === null) {
            return $this->unitCost;
        }

        return LineDenomination::toUnitPrice($this->unitCost, $this->getUnitOfMeasure(), LineDenomination::baseUnitOf($this->product));
    }

    /** The stored per-base rate at its full six places — `0.500000`, not `0.5`. */
    public function getBaseUnitRate(): string
    {
        return number_format((float) $this->unitCost, LineDenomination::RATE_SCALE, '.', '');
    }

    /** Base quantity x base rate, rounded once, at the line. */
    public function getLineTotal(): ?string
    {
        return LineDenomination::lineTotal($this->getQuantityBase(), $this->unitCost);
    }
}
