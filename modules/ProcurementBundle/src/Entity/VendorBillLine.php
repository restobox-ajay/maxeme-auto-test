<?php

declare(strict_types=1);

namespace ProcurementBundle\Entity;

use App\Entity\DenominatedQuantity;
use App\Entity\ProductCore;
use App\Enum\SalesOrderLineFulfillmentStatus;
use App\Service\Uom\LineDenomination;
use Doctrine\ORM\Mapping as ORM;

/**
 * One charge on a vendor's bill (#555) — what they say they sent us and what they say it cost.
 *
 * Mirrors PurchaseOrderLine, including the deliberate absence of a `product_core` foreign key: a
 * document line snapshots what was charged and has to outlive the product's deletion.
 *
 * `$purchaseOrderLine` is what makes the three-way match a per-row question rather than a
 * document-level guess. Nullable in both senses: a bill may exist without a PO at all, and a bill
 * may legitimately carry a charge the PO does not have — freight, a substitution, a correction. A
 * line with no attribution matches against nothing, which is itself an exception worth surfacing
 * rather than an error.
 */
#[ORM\Entity]
#[ORM\Table(name: 'vendor_bill_line')]
#[ORM\Index(name: 'idx_bill_line_unit', fields: ['unitOfMeasure'])]
#[ORM\Index(name: 'idx_bill_line_bill', fields: ['bill'])]
#[ORM\Index(name: 'idx_bill_line_po_line', fields: ['purchaseOrderLine'])]
#[ORM\Index(name: 'idx_bill_line_product', fields: ['product'])]
class VendorBillLine implements PurchasedDocumentLine
{
    use DenominatedQuantity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: VendorBill::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'vendor_bill_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private VendorBill $bill;

    /**
     * `SET NULL`: losing the attribution must never delete the record of a charge.
     *
     * `inversedBy` because the purchase order line reads this back — it derives how much of itself
     * has been billed from the rows pointing at it (#658), and Doctrine requires both halves of a
     * bidirectional association to name each other.
     */
    #[ORM\ManyToOne(targetEntity: PurchaseOrderLine::class, inversedBy: 'billLines')]
    #[ORM\JoinColumn(name: 'purchase_order_line_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?PurchaseOrderLine $purchaseOrderLine = null;

    /** Mapped, with no FK behind it — see the class docblock. */
    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: true)]
    private ?ProductCore $product = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $sku = null;

    #[ORM\Column(name: 'vendor_sku', length: 80, nullable: true)]
    private ?string $vendorSku = null;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 4)]
    private string $quantity = '0.00';

    /** Four places, for the reason PurchaseOrderLine::$unitCost has four: unit costs divide. */
    #[ORM\Column(name: 'unit_cost', type: 'decimal', precision: 18, scale: 6)]
    private string $unitCost = '0.0000';

    /** Legacy free-text U/M snapshot — full parity with PurchaseOrderLine::$unit; see its docblock. */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $unit = null;

    /** Per-line shipping weight — full parity with SalesOrderLine::$weight/InvoiceLine::$weight. */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $weight = null;

    /** Which warehouse THIS line's goods landed at, overriding the bill's own warehouse — full parity with PurchaseOrderLine::$location; see its docblock. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $subtotal = '0.00';

    /**
     * 'E', 'G' or 'S' — the SHARED tax vocabulary, snapshotted from the product when the line was
     * entered.
     *
     * The same column, the same three values and the same `TaxContext::mapTaxCode()` reading as
     * `invoice_line.tax_code`, because the owner's ruling is that tax rules are identical in both
     * directions: a taxable good is taxable whether we buy it or sell it. `ProductCore` names the
     * class once, as `sales_tax_code`, and both sides read that one field — the word "sales" in the
     * column name is an artefact of which side was built first, not a statement that a second
     * purchase-side class exists.
     *
     * Snapshotted rather than joined, like every other figure on this row: re-classing a product
     * next year must not restate a bill entered this year.
     *
     * Null maps to 'E', Exempt, which is both the only default that cannot overcharge and an
     * accurate statement about every line written before this column existed.
     */
    #[ORM\Column(name: 'tax_code', length: 8, nullable: true)]
    private ?string $taxCode = null;

    /** The lot/serial actually charged for — full parity with PurchaseOrderLine::$batch. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $batch = null;

    #[ORM\Column(name: 'sort_order', options: ['default' => 0])]
    private int $sortOrder = 0;

    public function getId(): ?int { return $this->id; }
    public function getBill(): VendorBill { return $this->bill; }

    /**
     * Is this row on a bill yet.
     *
     * `$bill` is a non-nullable typed property, so reading it before the row has been added to a
     * bill is a fatal Error rather than a null. That window is real and one line wide: a save
     * attributes a new line to its purchase order line — which links it into that line's
     * collection — before handing it to the bill. Anything reading a billed quantity in between
     * would crash, so the two readers check this first.
     */
    public function isAttached(): bool
    {
        return isset($this->bill);
    }
    public function setBill(VendorBill $bill): self { $this->bill = $bill; return $this; }
    public function getPurchaseOrderLine(): ?PurchaseOrderLine { return $this->purchaseOrderLine; }

    /**
     * Attribution, kept in step on BOTH sides (#658).
     *
     * The purchase order line derives how much of itself has been billed by reading the bill lines
     * that point at it, and a collection that is only correct after a flush-and-reload is a
     * collection the guard cannot trust inside the request that changed it. Doctrine hydrates the
     * field directly on load, so this runs only when application code sets the attribution — which
     * is the case where both sides have to agree.
     */
    public function setPurchaseOrderLine(?PurchaseOrderLine $purchaseOrderLine): self
    {
        if ($this->purchaseOrderLine === $purchaseOrderLine) {
            return $this;
        }

        $this->purchaseOrderLine?->unlinkBillLine($this);
        $this->purchaseOrderLine = $purchaseOrderLine;
        $purchaseOrderLine?->linkBillLine($this);

        return $this;
    }
    public function getProduct(): ?ProductCore { return $this->product; }
    public function setProduct(?ProductCore $product): self { $this->product = $product; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getSku(): ?string { return $this->sku; }
    public function setSku(?string $sku): self { $this->sku = $sku; return $this; }
    public function getVendorSku(): ?string { return $this->vendorSku; }
    public function setVendorSku(?string $vendorSku): self { $this->vendorSku = $vendorSku; return $this; }
    public function getQuantity(): string { return $this->quantity; }
    /**
     * Restating the base figure forgets how it was entered (#601, #646).
     *
     * Writing this column directly says "the row is this many BASE units", and the only
     * truthful entered figure for that is the same number in base units — which is what
     * `quantity_entered` NULL and `unit_id` NULL mean. See DenominatedQuantity.
     */
    public function setQuantity(string $quantity): self
    {
        $this->quantity = $quantity;
        $this->forgetEnteredExpression();

        return $this;
    }
    public function getUnitCost(): string { return $this->unitCost; }
    public function setUnitCost(string $unitCost): self { $this->unitCost = $unitCost; return $this; }
    public function getUnit(): ?string { return $this->unit; }
    public function setUnit(?string $unit): self { $this->unit = $unit; return $this; }
    public function getWeight(): ?string { return $this->weight; }
    public function setWeight(?string $weight): self { $this->weight = $weight; return $this; }
    public function getLocation(): ?string { return $this->location; }
    public function setLocation(?string $location): self { $this->location = $location; return $this; }

    /**
     * The receiving status of the goods this charge is FOR, read through the purchase order line
     * it draws down — reused, not reimplemented, exactly as PurchaseOrderLine::getFulfillmentStatus()
     * itself reuses SalesOrderLineFulfillmentStatus rather than restating its arithmetic a third
     * time. A bill with no PO attribution has nothing to await — the charge is the whole of what it
     * describes — so it reads Fulfilled, the same default every line answers before this existed.
     */
    public function getFulfillmentStatus(): SalesOrderLineFulfillmentStatus
    {
        return $this->purchaseOrderLine?->getFulfillmentStatus() ?? SalesOrderLineFulfillmentStatus::Fulfilled;
    }
    public function getSubtotal(): string { return $this->subtotal; }
    public function setSubtotal(string $subtotal): self { $this->subtotal = $subtotal; return $this; }
    public function getTaxCode(): ?string { return $this->taxCode; }
    public function setTaxCode(?string $taxCode): self { $this->taxCode = $taxCode; return $this; }
    public function getBatch(): ?string { return $this->batch; }
    public function setBatch(?string $batch): self { $this->batch = $batch; return $this; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): self { $this->sortOrder = $sortOrder; return $this; }

    /** The base figure #601 denominates everything in: `quantity`, unchanged by phase 3. */
    public function getQuantityBase(): string { return (string) $this->quantity; }

    protected function storeQuantityBase(string $base): void { $this->setQuantity($base); }

    // ── How the line READS (full parity with SalesOrderLine/InvoiceLine, #601/#659) ────────────

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

    /** The stored per-base unit cost expressed per {@see getDisplayUnitLabel()}. */
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
