<?php

declare(strict_types=1);

namespace App\Entity;

use App\Service\DisplayNumber;
use App\Service\Uom\LineDenomination;
use Doctrine\ORM\Mapping as ORM;

/**
 * A sales order line sold beyond the shelf, on purpose, with a name and a reason against it (#326).
 *
 * ## Why this row exists instead of a refusal
 *
 * #326 closed a real hole: an admin could save 500 units of a product with 2 in stock, and nothing
 * consulted `ProductInventory` at all, so reconciliation wrote the 500 into a hold bucket
 * afterwards — it records what a document says, it does not judge it — and availability went to
 * −498, which made the SKU unbuyable for every other customer. The gate that fixed it refused the
 * save outright.
 *
 * Refusing outright turned out to be the wrong half of the answer for an ADMIN. An operator knows
 * things this database does not: a delivery landing on Friday, a drop-ship straight from the
 * vendor, a substitution agreed on the phone, a customer content to wait. Overselling is theirs to
 * decide. What was actually missing was not a ban but a RECORD — so the save now goes through the
 * moment somebody says why, and this is what it writes.
 *
 * That is the same trade ProcurementBundle's own `ShortDatedReceipt` makes at the dock, and this
 * row deliberately copies its shape rather than inventing a second mechanism: warn with the
 * figures, take a typed reason and not a tick, snapshot what the decision was measured against,
 * name the person, and put it on the document where somebody will actually see it.
 *
 * **It is a tick's opposite.** "Overridden by X on Y" answers who and when and leaves the only
 * question anybody asks a fortnight later — on what grounds — unanswered. The reason box is the
 * whole point of the mechanism; the gate on a blank one is not suspicion of the operator, it is
 * what stops the record being worthless.
 *
 * ## What is NOT changed by taking one
 *
 * Everything downstream. The order holds what it says it holds, reconciliation writes it into
 * `sales_hold_quantity` exactly as it would for any other approved order, and
 * `ProductInventory::getAvailableQuantity()` goes negative by the shortfall. That is the accepted
 * consequence of the decision and is deliberately not softened, clamped or specially flagged — the
 * figure is true, and a true negative is what tells everybody else the shelf is spoken for.
 *
 * ## Two tables, not one with a nullable pair of keys
 *
 * There is a second, near-identical {@see InvoiceLineStockOverride}, and that is deliberate. It is
 * the shape {@see InventoryReservation} states and this codebase applies everywhere: lines,
 * addresses and logs all take a concrete class per document subtype. One table with a nullable
 * `order_line_id` and a nullable `invoice_line_id` would need a CHECK constraint to express "exactly
 * one of these is set" that two tables get from `NOT NULL` for free — and under SQLite 3.26 such a
 * constraint lives in the table definition, so changing it later would mean the table rebuild this
 * project avoids on principle. The two share their SHAPE, which is what lets one
 * {@see \App\Service\Inventory\StockOverrideRecorder} fill either from one measurement.
 *
 * ## One per line
 *
 * `order_line_id` is unique. A line is one product in one region and can therefore be short by one
 * amount; two rows about one line would be two answers to one question. A line saved a second time
 * and still short UPDATES its row rather than adding one — the decision is re-taken against the new
 * figures, and the row goes on being the current answer to "why is this line beyond stock".
 *
 * ## Not a worklist row
 *
 * Same reasoning `ShortDatedReceipt` gives for staying off the Exceptions screen: that screen is a
 * MONEY worklist, every row on it carries what it puts at risk in the document's currency and the
 * whole thing is sorted by that. An oversell has no amount at risk — the goods are sold, at the
 * price on the line — and what it is worth is measured in units and days. It belongs on the order,
 * which is where the person chasing it is already standing.
 */
#[ORM\Entity]
#[ORM\Table(name: 'sales_order_line_stock_override')]
#[ORM\UniqueConstraint(name: 'uniq_stock_override_order_line', fields: ['orderLine'])]
#[ORM\Index(name: 'idx_stock_override_order_line_at', fields: ['overriddenAt'])]
class SalesOrderLineStockOverride
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: SalesOrderLine::class, inversedBy: 'stockOverride')]
    #[ORM\JoinColumn(name: 'order_line_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SalesOrderLine $orderLine;

    /** The region the demand was measured in, in the region's own canonical spelling. */
    #[ORM\Column(name: 'region_name', length: 120)]
    private string $regionName = '';

    /**
     * What this save asked for, in the product's BASE units, summed across every posted row for
     * this product and region.
     *
     * `decimal(14, 4)`, which is what every other mapped quantity column in this application is
     * (#601, #645). It was `integer` when this table was written, and that was a silent truncation:
     * fractional quantities are supported — a third of a case is 0.3333 — so an override of 2.5
     * units was stored as 2 with no error and read back afterwards as though 2 was what somebody
     * decided. The figure this row exists to preserve is the one the operator was looking at, and a
     * column that cannot hold it is the record lying about the decision.
     *
     * Base, never the entered figure: a row saying 40 BOX-12 sells 480 off the shelf, and a record
     * saying 40 would describe a shortfall twelve times smaller than the one taken.
     */
    #[ORM\Column(name: 'requested_quantity', type: 'decimal', precision: 14, scale: 4)]
    private string $requestedQuantity = '0.0000';

    /**
     * Availability at the moment of the decision, the order's own hold added back.
     *
     * Signed on purpose, exactly as `procurement_short_dated_receipt.remaining_days` is: a second
     * override on a SKU already 40 units in the hole reads −40 here rather than 0, and losing that
     * would tell the next reader the shelf was merely empty.
     */
    #[ORM\Column(name: 'available_quantity', type: 'decimal', precision: 14, scale: 4)]
    private string $availableQuantity = '0.0000';

    /**
     * Remaining backorder capacity that counted as cover, or 0 when none did.
     *
     * Zero is meaningful and is written on purpose, the same way `minimum_days` of 0 is on a
     * short-dated receipt: it says nothing but stock was standing behind this line. A SKU nobody
     * opted in and a SKU whose cap was already full are both 0 here, and both are true — the cap
     * itself is on `product_inventory` and has moved since anyway.
     */
    #[ORM\Column(name: 'backorder_capacity', type: 'integer')]
    private int $backorderCapacity = 0;

    /** Why these units were sold anyway. Required — see the class docblock. */
    #[ORM\Column(name: 'reason', length: 255)]
    private string $reason = '';

    /** Who decided. Nullable for the same reason `goods_receipt.received_by` is: a script has no user. */
    #[ORM\Column(name: 'overridden_by', length: 180, nullable: true)]
    private ?string $overriddenBy = null;

    #[ORM\Column(name: 'overridden_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $overriddenAt;

    public function __construct()
    {
        $this->overriddenAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getOrderLine(): SalesOrderLine { return $this->orderLine; }
    public function setOrderLine(SalesOrderLine $orderLine): self { $this->orderLine = $orderLine; return $this; }
    public function getRegionName(): string { return $this->regionName; }
    public function setRegionName(string $regionName): self { $this->regionName = $regionName; return $this; }
    public function getRequestedQuantity(): string { return $this->requestedQuantity; }
    public function setRequestedQuantity(string $requestedQuantity): self { $this->requestedQuantity = self::atColumnScale($requestedQuantity); return $this; }
    public function getAvailableQuantity(): string { return $this->availableQuantity; }
    public function setAvailableQuantity(string $availableQuantity): self { $this->availableQuantity = self::atColumnScale($availableQuantity); return $this; }
    public function getBackorderCapacity(): int { return $this->backorderCapacity; }
    public function setBackorderCapacity(int $backorderCapacity): self { $this->backorderCapacity = max(0, $backorderCapacity); return $this; }
    public function getReason(): string { return $this->reason; }
    public function setReason(string $reason): self { $this->reason = trim($reason); return $this; }
    public function getOverriddenBy(): ?string { return $this->overriddenBy; }
    public function setOverriddenBy(?string $overriddenBy): self { $this->overriddenBy = $overriddenBy; return $this; }
    public function getOverriddenAt(): \DateTimeImmutable { return $this->overriddenAt; }
    public function setOverriddenAt(\DateTimeImmutable $overriddenAt): self { $this->overriddenAt = $overriddenAt; return $this; }

    /**
     * How many units nothing could cover when this was taken.
     *
     * Derived rather than stored: it is `requested − available − capacity` and all three of those
     * ARE stored, so a fourth column would be one fact in two places — the shape behind #589, #590,
     * #591. The clamps make it agree exactly with `StockShortfall::$missing`, which is the figure
     * the warning quoted — that one is measured as `requested − BackorderSplit::accepted()` off the
     * object that decided the cover, and this subtracts the two halves of the same cover back out
     * of the same demand. Negative availability contributes no cover rather than negative cover,
     * and a line comfortably within stock derives 0 rather than a negative surplus.
     *
     * A decimal string since #601: the demand is `decimal(14, 4)` now, so half a case short is
     * `0.5000` rather than a 0 that reads as "nothing was short".
     */
    public function getShortfallQuantity(): string
    {
        $shortfall = (float) $this->requestedQuantity
            - max(0.0, (float) $this->availableQuantity)
            - max(0, $this->backorderCapacity);

        return self::atColumnScale((string) max(0.0, $shortfall));
    }

    /**
     * A quantity at the scale its column actually holds, so what is stored and what is compared are
     * the same string.
     *
     * `LineDenomination::QUANTITY_SCALE` rather than a literal 4, for the reason
     * {@see \App\Service\DisplayNumber} gives: a column widened in one place must not leave a
     * rounding behind at the old scale somewhere else. The same constant is what `EstimateLine` and
     * `SalesOrderLine` normalise their own figures with.
     */
    private static function atColumnScale(string $quantity): string
    {
        return number_format((float) $quantity, LineDenomination::QUANTITY_SCALE, '.', '');
    }

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        // Through DisplayNumber rather than printed raw, so the audit line reads "2.5 of 12.5" and
        // never "2.5000 of 12.5000". It has no constructor and no state — the three rules are
        // constants on it — so a value with no container can still ask the one place that owns them.
        $display = new DisplayNumber();

        return sprintf(
            'Sold beyond stock: %s, %s of %s requested had nothing behind them in %s',
            $this->orderLine->getSku() ?? $this->orderLine->getName(),
            $display->qty($this->getShortfallQuantity()),
            $display->qty($this->requestedQuantity),
            $this->regionName,
        );
    }
}
