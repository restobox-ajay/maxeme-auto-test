<?php

declare(strict_types=1);

namespace App\Entity;

use App\Service\QuantityScale;
use App\Service\Uom\LineDenomination;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A product row on an invoice. Mirrors SalesOrderLine — an invoice bills goods, so its rows carry
 * resolved figures rather than the nullable "TBD" ones an estimate line allows.
 *
 * Its own entity rather than a shared one for the reason DocumentLine states: a mapped superclass
 * cannot parametrize targetEntity, so each document subtype owns its rows and only the shape is
 * shared through the interface.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoice_line')]
#[ORM\Index(name: 'idx_invoice_line_unit', fields: ['unitOfMeasure'])]
#[ORM\Index(name: 'idx_invoice_line_lot', fields: ['lotId'])]
class InvoiceLine implements CostedDocumentLine, DenominatedLine
{
    use DenominatedQuantity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'invoice_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Invoice $invoice;

    /**
     * The order row this one bills, when it bills one.
     *
     * Nullable in both directions of the word: an invoice may exist without an order at all, and
     * #539 explicitly allows an invoice to carry a SKU that is not on its order — added at ship
     * time, or a fee. Attributing the row to its source is what lets uninvoiced quantity be
     * derived per order line rather than guessed by matching SKUs after the fact.
     *
     * SET NULL rather than CASCADE: losing the attribution must never silently delete the billing
     * record of goods that were actually sent.
     */
    #[ORM\ManyToOne(targetEntity: SalesOrderLine::class)]
    #[ORM\JoinColumn(name: 'sales_order_line_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?SalesOrderLine $salesOrderLine = null;

    #[ORM\ManyToOne(targetEntity: ProductCore::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: true)]
    private ?ProductCore $product = null;

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $sku = null;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 4)]
    private string $quantity = '1.00';

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $weight = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $unit = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $taxCode = null;

    #[ORM\Column(type: 'decimal', precision: 18, scale: 6)]
    private string $cost = '0.00';

    /**
     * Editable at invoice level, deliberately (#539). It is rare, but an invoice may bill a price
     * the order did not quote — which is why "fully invoiced" is decided by quantity and never by
     * amount, and why an invoice total need not match its share of the order.
     */
    #[ORM\Column(type: 'decimal', precision: 18, scale: 6)]
    private string $price = '0.00';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $subtotal = '0.00';

    /** The lot actually sent. Real here for the reason it is real on SalesOrderLine and absent on EstimateLine (#250). */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $batch = null;

    /**
     * Which InventoryLot this line actually bills, when the product's TrackingPolicy tracks lots
     * outbound. See SalesOrderLine::$lotId for why this is a plain integer rather than a Doctrine
     * association — the same reasoning applies unchanged: core may not hold a compile-time reference
     * to modules/InventoryDepthBundle's InventoryLot.
     */
    #[ORM\Column(name: 'lot_id', nullable: true)]
    private ?int $lotId = null;

    /** The unit's serial, when the product's TrackingPolicy tracks serials outbound instead of lots. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $serial = null;

    /** Where this row sits on the document — see EstimateLine::$sortOrder, same reason. */
    #[ORM\Column(name: 'sort_order', options: ['default' => 0])]
    private int $sortOrder = 0;

    /**
     * The credit-note rows that credit this one back (#586).
     *
     * The inverse side is mapped because it is READ, not for symmetry: getCreditedUnits() below is
     * what makes an invoice's inventory hold net out against credits, and it has to answer from
     * whatever is in memory during the same flush that created the credit line. A one-directional
     * association would leave it correct only after an EntityManager clear.
     *
     * @var Collection<int, CreditMemoLine>
     */
    #[ORM\OneToMany(targetEntity: CreditMemoLine::class, mappedBy: 'invoiceLine')]
    private Collection $creditLines;

    public function __construct()
    {
        $this->creditLines = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    /** @return Collection<int, CreditMemoLine> */
    public function getCreditLines(): Collection { return $this->creditLines; }

    /**
     * How many units of this billed row have been credited back — the number the invoice's
     * inventory hold is reduced by (#586).
     *
     * ## Why it lives on the line and not in a second reservation ledger
     *
     * Because the alternative is a third implementation of InventoryReservationSubject over a new
     * table, and its own docblock says why that is the wrong shape: the reconciler is "a hundred and
     * eighty lines of diffing a computed target against a durable ledger", and two copies of it is
     * "how a bucket ends up disagreeing with its own ledger — silently, because both copies keep
     * looking right on their own". #548 hit the same fork with backorder and took the same turn: an
     * order's promise and its hold are two subjects over ONE ledger, and the order's sales-hold
     * target is computed net of what the line has backordered. A credit is the same arithmetic seen
     * from the invoice side —
     *
     *     invoice holds = its current quantity − quantity credited against that line
     *
     * — so it is a subtraction inside the existing target, and `invoice_inventory_reservation`
     * follows through the reconciler that already exists.
     *
     * ## Only notes that count
     *
     * A draft credits nothing (it is not a document yet) and a voided one has said the credit never
     * happened. CreditMemo::countsTowardCreditedQuantity() is the single statement of that rule, for
     * the same reason Invoice::countsTowardInvoicedQuantity() is a method rather than a condition
     * repeated at four call sites.
     *
     * Clamped at zero because a credit note is never negative — see CreditMemoLine's docblock on why
     * this is not a negative invoice.
     */
    public function getCreditedUnits(): string
    {
        // Summed as exact decimal strings. It used to round the total to whole physical units, so
        // crediting 0.4 of a line credited nothing and crediting 1.5 credited two — see
        // SalesOrderLine::getBackorderedUnits(), which lost the same cast.
        $units = QuantityScale::canonical(0);
        foreach ($this->creditLines as $creditLine) {
            if ($creditLine->getCreditMemo()->countsTowardCreditedQuantity()) {
                $units = QuantityScale::add($units, $creditLine->getQuantity());
            }
        }

        return QuantityScale::compare($units, 0) > 0 ? $units : QuantityScale::canonical(0);
    }
    /**
     * The decision to bill this line beyond what the shelf could cover, when one was taken (#326).
     *
     * Null on almost every line — the row existing at all is the finding. Owned by
     * {@see InvoiceLineStockOverride}; see {@see SalesOrderLine::$stockOverride} for why there is no
     * cascade and no orphan removal on this side.
     */
    #[ORM\OneToOne(mappedBy: 'invoiceLine', targetEntity: InvoiceLineStockOverride::class)]
    private ?InvoiceLineStockOverride $stockOverride = null;

    public function getStockOverride(): ?InvoiceLineStockOverride { return $this->stockOverride; }
    public function setStockOverride(?InvoiceLineStockOverride $stockOverride): self { $this->stockOverride = $stockOverride; return $this; }
    public function getInvoice(): Invoice { return $this->invoice; }
    public function setInvoice(Invoice $invoice): self { $this->invoice = $invoice; return $this; }
    public function getSalesOrderLine(): ?SalesOrderLine { return $this->salesOrderLine; }
    public function setSalesOrderLine(?SalesOrderLine $salesOrderLine): self { $this->salesOrderLine = $salesOrderLine; return $this; }
    public function getProduct(): ?ProductCore { return $this->product; }
    public function setProduct(?ProductCore $product): self { $this->product = $product; return $this; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getLocation(): ?string { return $this->location; }
    public function setLocation(?string $location): self { $this->location = $location; return $this; }
    public function getSku(): ?string { return $this->sku; }
    public function setSku(?string $sku): self { $this->sku = $sku; return $this; }
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
    public function getWeight(): ?string { return $this->weight; }
    public function setWeight(?string $weight): self { $this->weight = $weight; return $this; }
    public function getUnit(): ?string { return $this->unit; }
    public function setUnit(?string $unit): self { $this->unit = $unit; return $this; }
    public function getTaxCode(): ?string { return $this->taxCode; }
    public function setTaxCode(?string $taxCode): self { $this->taxCode = $taxCode; return $this; }
    public function getCost(): string { return $this->cost; }
    public function setCost(string $cost): self { $this->cost = $cost; return $this; }
    public function getPrice(): ?string { return $this->price; }
    public function setPrice(?string $price): self { $this->price = (string) ($price ?? '0.00'); return $this; }
    public function getSubtotal(): ?string { return $this->subtotal; }
    public function setSubtotal(?string $subtotal): self { $this->subtotal = (string) ($subtotal ?? '0.00'); return $this; }
    public function getBatch(): ?string { return $this->batch; }
    public function setBatch(?string $batch): self { $this->batch = $batch; return $this; }
    public function getLotId(): ?int { return $this->lotId; }
    public function setLotId(?int $lotId): self { $this->lotId = $lotId; return $this; }
    public function getSerial(): ?string { return $this->serial; }
    public function setSerial(?string $serial): self { $this->serial = $serial; return $this; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): self { $this->sortOrder = $sortOrder; return $this; }

    // ── How the line READS (#601, phase 4 #644) ──────────────────────────────────────────────
    //
    // Everything below is derived at render from the three figures the row already stores —
    // the base quantity, the rung, and the per-base rate — and none of it is ever written back.
    // On a line entered in base units every one of them answers what the screens printed before
    // this phase existed, character for character.

    /**
     * The code of the unit this line was entered in, or the base unit's when it names none (#659).
     *
     * No bracketed pack size any more. A term carries its own count — `BOX-12` is twelve and
     * `BOX-24` is twenty-four, and they are two rows of `unit_of_measure` — so printing the ratio
     * beside the code would state the same fact twice on the same document. The brackets used to be
     * the only thing distinguishing a packaging rung from a base unit in this column, which #659
     * names as a defect rather than a convention.
     */
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
     * The stored per-base rate expressed per {@see getDisplayUnitLabel()} — `$0.500000/EA` reads as
     * `$6.00` per Case. Derived at render, never stored.
     *
     * An unpackaged line returns the stored figure untouched rather than a formatted copy of it:
     * there is no conversion to perform, and reformatting would put a second rounding point between
     * the column and the box the next save reads (#259).
     */
    public function getDisplayUnitPrice(): ?string
    {
        if ($this->price === null || $this->getUnitOfMeasure() === null) {
            return $this->price;
        }

        return LineDenomination::toUnitPrice($this->price, $this->getUnitOfMeasure(), LineDenomination::baseUnitOf($this->product));
    }

    /**
     * The stored per-base rate at its full six places — `0.500000`, not `0.5`.
     *
     * SQLite's NUMERIC affinity hands `0.500000` back as `0.5`, so the raw column value is not a
     * stable way to SHOW a rate: the resolved-price hint beside the price box would read
     * `= $0.5 / EA` on one line and `= $0.833333 / EA` on the next. Formatting only — the same
     * normalisation UnitOfMeasure::getFactorToFamilyBase() performs on the way out, and for the same
     * reason.
     */
    public function getBaseUnitRate(): ?string
    {
        return $this->price === null
            ? null
            : number_format((float) $this->price, LineDenomination::RATE_SCALE, '.', '');
    }

    /**
     * Base quantity x base rate, rounded once, at the line — what the Amount column prints.
     *
     * Computed from the two STORED figures rather than from the two displayed ones: `40 x $10.00`
     * and `480 x $0.833333` are not the same number, and the stored pair is what the document total
     * is built from.
     */
    public function getLineTotal(): ?string
    {
        return LineDenomination::lineTotal($this->getQuantityBase(), $this->price);
    }

    /** The base figure #601 denominates everything in: `quantity`, unchanged by phase 3. */
    public function getQuantityBase(): string { return (string) $this->quantity; }

    protected function storeQuantityBase(string $base): void { $this->setQuantity($base); }
}
