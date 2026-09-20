<?php

declare(strict_types=1);

namespace App\Entity;

use App\Service\DisplayNumber;
use App\Service\Uom\LineDenomination;
use Doctrine\ORM\Mapping as ORM;

/**
 * An invoice line billed beyond the shelf, on purpose, with a name and a reason against it (#326).
 *
 * The invoice half of {@see SalesOrderLineStockOverride} — read that class for why the decision is
 * recorded rather than refused, and for why these are two entities and not one table with a
 * nullable pair of foreign keys.
 *
 * ## An invoice holds stock, which is why there is an override to record at all
 *
 * Invoicing moves no stock: there is no StockMovement anywhere in OrderInvoicingService or
 * InvoiceController, and a sell-side invoice is a money document. But it HOLDS stock —
 * `InvoiceReservationSubject` puts what each line bills into `pending_quantity` or
 * `approved_quantity` exactly as an approved order puts its remainder into `sales_hold_quantity` —
 * so a standalone invoice for 500 units of a 10-unit product drives availability to −490 just as
 * surely as an order would.
 *
 * ## Backorder capacity is never cover here, so it is never named
 *
 * The one deliberate difference from the order side. An order line that cannot be covered is SPLIT:
 * `BackorderSplitResolver` records the uncovered part as backordered units and the order holds only
 * the stocked portion. An invoice line has no such split — `sales_order_line.backordered_quantity`
 * is an ORDER column, and `InvoiceReservationSubject::stockedQuantityFor()` returns the full billed
 * quantity for a line with no order line behind it, which is every line on a standalone invoice. So
 * `backorder_capacity` on these rows is always 0, and that 0 is true rather than merely unset.
 *
 * ## One per line
 *
 * `invoice_line_id` is unique, for the reason the order side states. A standalone invoice's lines
 * are written once, at creation, so unlike an order line this row is never re-taken — but the index
 * is what makes a second row unrepresentable rather than merely unwritten.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoice_line_stock_override')]
#[ORM\UniqueConstraint(name: 'uniq_stock_override_invoice_line', fields: ['invoiceLine'])]
#[ORM\Index(name: 'idx_stock_override_invoice_line_at', fields: ['overriddenAt'])]
class InvoiceLineStockOverride
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: InvoiceLine::class, inversedBy: 'stockOverride')]
    #[ORM\JoinColumn(name: 'invoice_line_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private InvoiceLine $invoiceLine;

    /** The region the demand was measured in, in the region's own canonical spelling. */
    #[ORM\Column(name: 'region_name', length: 120)]
    private string $regionName = '';

    /**
     * BASE units this invoice bills, summed across every row for this product and region.
     *
     * `decimal(14, 4)` for the reason the order side's own `$requestedQuantity` gives: it was
     * `integer`, which truncated a billed 2.5 to 2 with no error and then read it back as though 2
     * was what somebody decided.
     *
     * Base, never the entered figure: a row saying 40 BOX-12 bills 480 off the shelf, and a record
     * saying 40 would describe a shortfall twelve times smaller than the one taken.
     */
    #[ORM\Column(name: 'requested_quantity', type: 'decimal', precision: 14, scale: 4)]
    private string $requestedQuantity = '0.0000';

    /** Availability at the moment of the decision. Signed — see the order side. */
    #[ORM\Column(name: 'available_quantity', type: 'decimal', precision: 14, scale: 4)]
    private string $availableQuantity = '0.0000';

    /**
     * Always 0 on an invoice, and stored rather than omitted.
     *
     * The column is here so the two override tables have ONE shape and one recorder can write
     * both — see {@see SalesOrderLineStockOverride}. Its always being 0 is a statement, not an
     * omission: capacity
     * is not cover on this document, so nothing but stock ever stood behind the line.
     */
    #[ORM\Column(name: 'backorder_capacity', type: 'integer')]
    private int $backorderCapacity = 0;

    /** Why these units were billed anyway. Required — see the order side's class docblock. */
    #[ORM\Column(name: 'reason', length: 255)]
    private string $reason = '';

    #[ORM\Column(name: 'overridden_by', length: 180, nullable: true)]
    private ?string $overriddenBy = null;

    #[ORM\Column(name: 'overridden_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $overriddenAt;

    public function __construct()
    {
        $this->overriddenAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getInvoiceLine(): InvoiceLine { return $this->invoiceLine; }
    public function setInvoiceLine(InvoiceLine $invoiceLine): self { $this->invoiceLine = $invoiceLine; return $this; }
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

    /** How many billed units nothing could cover, fractions included. Derived — see the order side. */
    public function getShortfallQuantity(): string
    {
        $shortfall = (float) $this->requestedQuantity
            - max(0.0, (float) $this->availableQuantity)
            - max(0, $this->backorderCapacity);

        return self::atColumnScale((string) max(0.0, $shortfall));
    }

    /** A quantity at the scale its column holds. See the order side. */
    private static function atColumnScale(string $quantity): string
    {
        return number_format((float) $quantity, LineDenomination::QUANTITY_SCALE, '.', '');
    }

    /** Feeds AuditLogSubscriber's automatic label resolution. */
    public function getLabel(): string
    {
        // Through DisplayNumber rather than printed raw. See the order side.
        $display = new DisplayNumber();

        return sprintf(
            'Billed beyond stock: %s, %s of %s billed had nothing behind them in %s',
            $this->invoiceLine->getSku() ?? $this->invoiceLine->getName(),
            $display->qty($this->getShortfallQuantity()),
            $display->qty($this->requestedQuantity),
            $this->regionName,
        );
    }
}
