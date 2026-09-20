<?php

namespace App\Entity;

use App\Enum\SalesOrderLineFulfillmentStatus;
use App\Service\QuantityScale;
use App\Service\Uom\LineDenomination;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'sales_order_line')]
#[ORM\Index(name: 'idx_sales_order_line_unit', fields: ['unitOfMeasure'])]
#[ORM\Index(name: 'idx_sales_order_line_lot', fields: ['lotId'])]
class SalesOrderLine implements CostedDocumentLine, DenominatedLine
{
    use DenominatedQuantity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SalesOrder::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SalesOrder $order;

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

    #[ORM\Column(type: 'decimal', precision: 18, scale: 6)]
    private string $price = '0.00';

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private string $subtotal = '0.00';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $batch = null;

    /**
     * Which InventoryLot this line's units are picked from, when the product's TrackingPolicy
     * tracks lots outbound (#664's line-level identity capture, layered on the existing $batch box
     * rather than replacing it).
     *
     * A plain nullable integer, not a Doctrine association — the same reason
     * InventoryBucketChangeLog::$groupId is one: `inventory_lot` belongs to
     * modules/InventoryDepthBundle, which is deletable, and a core entity with an ORM association to
     * a class that may not exist is a metadata load failure, not a missing feature. The real foreign
     * key lives in the migration that adds this column (`REFERENCES inventory_lot (id) ON DELETE SET
     * NULL`), not in the mapping.
     *
     * Read by SalesOrderReservationSubject/SalesOrderBackorderReservationSubject::heldLines() and
     * carried onto OrderInventoryReservation by the reconciler, so a lot picked here narrows what
     * gets held exactly the way product+warehouse already does — one dimension further, nothing new
     * invented.
     */
    #[ORM\Column(name: 'lot_id', nullable: true)]
    private ?int $lotId = null;

    /**
     * The unit's serial, when the product's TrackingPolicy tracks serials outbound instead of lots.
     * Mutually exclusive with $lotId in practice (TrackingPolicy::$mode is one value), never in the
     * schema — same convention InventoryDetail already uses.
     */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $serial = null;

    /**
     * Where this row sits on the document — see EstimateLine::$sortOrder, which exists for the same
     * reason. SalesOrder::$lines was missing an ORDER BY too; the order form only never showed it
     * because every save deletes and rebuilds the whole collection in submitted order, so the
     * insertion order and the id order happened to agree.
     */
    #[ORM\Column(name: 'sort_order', options: ['default' => 0])]
    private int $sortOrder = 0;

    /**
     * How much of $quantity is promised but not yet stocked (#548).
     *
     * Same type and precision as $quantity because it is a partial split of it, never a figure of
     * its own. Zero on every line unless the product's ProductInventory row for the warehouse
     * serving this line opted in — see ProductInventory::$allowBackorder — which is what makes a
     * build with the flag off behave exactly as it did before this column existed.
     *
     * Written by BackorderSplitResolver at save time and by BackorderReleaseService when stock
     * arrives. Read by SalesOrderBackorderReservationSubject, which is what turns it into a
     * `backordered` hold on ProductInventory.
     */
    #[ORM\Column(name: 'backordered_quantity', type: 'decimal', precision: 14, scale: 4, options: ['default' => '0.00'])]
    private string $backorderedQuantity = '0.00';

    /**
     * A cache of what the two quantities above say, kept as a plain string column the way
     * SalesOrder::$status is. Never assignable on its own: setBackorderedQuantity() and
     * setQuantity() are the only writers, so it cannot drift from the numbers it summarises.
     */
    #[ORM\Column(name: 'fulfillment_status', length: 32, options: ['default' => 'Fulfilled'])]
    private string $fulfillmentStatus = SalesOrderLineFulfillmentStatus::Fulfilled->value;

    /**
     * When the admin expects the backordered units to arrive. Hand-entered — this app has no
     * supplier or purchase-order integration to derive it from — and meaningful only while
     * something is still backordered.
     */
    #[ORM\Column(name: 'restock_eta', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $restockEta = null;

    /**
     * The decision to sell this line beyond what the shelf could cover, when one was taken (#326).
     *
     * Null on almost every line, which is the point: the row existing at all is the finding. Owned
     * by {@see SalesOrderLineStockOverride}; this is the inverse side, carried so the order screen
     * can show the decision beside the line it was taken on without a repository lookup per row.
     *
     * `cascade` and `orphanRemoval` are deliberately ABSENT. The recorder persists the row itself,
     * and a line that stops being short does NOT lose its record: what somebody decided on the day
     * they decided it is a fact about the document, and dropping it once stock arrived would erase
     * the only trace that anybody went beyond the shelf. The row goes when the LINE goes, which the
     * database's ON DELETE CASCADE settles.
     */
    #[ORM\OneToOne(mappedBy: 'orderLine', targetEntity: SalesOrderLineStockOverride::class)]
    private ?SalesOrderLineStockOverride $stockOverride = null;

    public function getId(): ?int { return $this->id; }
    public function getStockOverride(): ?SalesOrderLineStockOverride { return $this->stockOverride; }
    public function setStockOverride(?SalesOrderLineStockOverride $stockOverride): self { $this->stockOverride = $stockOverride; return $this; }
    public function getOrder(): SalesOrder { return $this->order; }
    public function setOrder(SalesOrder $order): self { $this->order = $order; return $this; }
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
     * Re-clamps the backordered quantity as well (#548): a line cannot promise more than it
     * ordered, so cutting the quantity to two cannot leave four units outstanding. Without this,
     * an edit that shrank a line would leave a status of Backordered on a line nothing is short of.
     */
    public function setQuantity(string $quantity): self
    {
        $this->quantity = $quantity;
        $this->setBackorderedQuantity($this->backorderedQuantity);
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
    public function getPrice(): string { return $this->price; }
    public function setPrice(string $price): self { $this->price = $price; return $this; }
    public function getSubtotal(): string { return $this->subtotal; }
    public function setSubtotal(string $subtotal): self { $this->subtotal = $subtotal; return $this; }
    public function getBatch(): ?string { return $this->batch; }
    public function setBatch(?string $batch): self { $this->batch = $batch; return $this; }
    public function getLotId(): ?int { return $this->lotId; }
    public function setLotId(?int $lotId): self { $this->lotId = $lotId; return $this; }
    public function getSerial(): ?string { return $this->serial; }
    public function setSerial(?string $serial): self { $this->serial = $serial; return $this; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): self { $this->sortOrder = $sortOrder; return $this; }

    public function getBackorderedQuantity(): string { return $this->backorderedQuantity; }

    /**
     * The only writer of $fulfillmentStatus, so the cache and the numbers cannot disagree.
     *
     * Clamped at zero and at the line's own quantity: a line cannot backorder less than nothing,
     * and cannot backorder more than it ordered.
     */
    public function setBackorderedQuantity(string $backorderedQuantity): self
    {
        $capped = min(max(0.0, (float) $backorderedQuantity), max(0.0, (float) $this->quantity));
        $this->backorderedQuantity = QuantityScale::canonical($capped);
        $this->recomputeFulfillmentStatus();

        return $this;
    }

    /**
     * The backordered quantity, canonicalised — exact, and no longer rounded.
     *
     * It was `(int) round((float) $this->backorderedQuantity)`, described as "the same rounding
     * every consumer of a line quantity performs", which is precisely the agreement between wrong
     * answers that `App\Service\QuantityScale` exists to break: 0.4 backordered read as nothing
     * backordered and 1.5 read as two units promised.
     */
    public function getBackorderedUnits(): string { return QuantityScale::canonical($this->backorderedQuantity); }

    public function getFulfillmentStatus(): string { return $this->fulfillmentStatus; }

    /**
     * The enum behind the string, or null for a value no release of this app ever wrote. Callers
     * treat null as Fulfilled — see SalesOrderLineFulfillmentStatus.
     */
    public function getFulfillmentStatusEnum(): ?SalesOrderLineFulfillmentStatus
    {
        return SalesOrderLineFulfillmentStatus::tryFrom($this->fulfillmentStatus);
    }

    public function isBackordered(): bool { return QuantityScale::compare($this->getBackorderedUnits(), 0) > 0; }

    public function getRestockEta(): ?\DateTimeImmutable { return $this->restockEta; }
    public function setRestockEta(?\DateTimeImmutable $restockEta): self { $this->restockEta = $restockEta; return $this; }

    private function recomputeFulfillmentStatus(): void
    {
        $this->fulfillmentStatus = SalesOrderLineFulfillmentStatus::forQuantities(
            $this->quantity,
            $this->backorderedQuantity,
        )->value;
    }

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
