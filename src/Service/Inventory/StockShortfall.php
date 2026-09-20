<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Service\DisplayNumber;

/**
 * What one product in one region is short by on a document being saved (#326, warn-and-override).
 *
 * Only ever built when a demand really does exceed what can cover it — {@see
 * AdminOrderStockValidator::shortfallsFor()} produces none when every line fits — so the existence
 * of one of these IS the finding. A plain value object with no entity manager, the same shape as
 * ProcurementBundle's own `ShelfLifeFinding` and for the same reason: it is built by whoever
 * measured, and read BOTH by the warning the operator sees and by the row written when they decide
 * to go ahead. One measurement, so the sentence on screen and the figures in the record cannot
 * disagree.
 *
 * ## It is a NOTICE, not a refusal
 *
 * Selling beyond the shelf is the operator's to decide. They know things this database does not —
 * a delivery landing on Friday, a drop-ship, a substitution, a customer who has agreed to wait —
 * and the application's job is to state the number plainly and record that a person chose, not to
 * argue. So {@see self::describe()} names the three figures and then says how to proceed; the only
 * thing actually withheld is a save with NOBODY'S NAME AND NO REASON on it, because a record
 * nobody can explain a fortnight later is the same as no record at all.
 *
 * ## Why it is a GROUP and not a line
 *
 * Two rows of the same product in the same region draw on one pool: five plus five against six has
 * to be short by four, and measuring each row alone would find neither short. So the demand is
 * summed per (product, warehouse) exactly as
 * {@see AdminOrderStockValidator::requestedByProductAndWarehouse()} has always summed it, and this
 * object is one such group. {@see self::$lineIndexes} carries the posted rows that make it up, so
 * the reason can be read off any one of them and the record can name the document line it was
 * typed on.
 */
final class StockShortfall
{
    public function __construct(
        public readonly ProductCore $product,
        public readonly Warehouse $warehouse,
        /** The region's canonical spelling — what the operator typed on the line, not "Warehouse". */
        public readonly string $regionName,
        /**
         * BASE units this save asks for, summed across every posted row for this product+region,
         * EXACTLY as they were typed.
         *
         * A decimal string and not an int, because fractional quantities are supported and this is
         * the figure that ends up on the override record: rounding 2.5 to 3 here would put a number
         * nobody asked for into the sentence the operator answers AND into the row that records
         * what they decided — see `sales_order_line_stock_override.requested_quantity`, which was
         * `INTEGER` and silently truncated exactly this.
         *
         * It carries whatever precision was posted; the column's own scale is applied once, by the
         * entity that stores it, and the display scale once, by {@see DisplayNumber}.
         */
        public readonly string $requested,
        /**
         * Availability at this moment, with the asking document's own hold added back.
         *
         * Signed, and stored signed: a second override against a SKU that is already oversold
         * measures a NEGATIVE figure, and clamping it here would tell the next reader the shelf was
         * empty when it was in fact 40 units in the hole. It is clamped for DISPLAY only, in
         * {@see self::describe()}, exactly as the pre-override refusal clamped it.
         *
         * A decimal string since availability became decimal. It was an `int`, which is what made a
         * shelf holding 0.4 report itself empty in the very sentence an operator was being asked to
         * override.
         */
        public readonly string $available,
        /**
         * Remaining backorder capacity, or null when capacity must not be named.
         *
         * Null on every SKU nobody has opted in — which is the whole catalogue by default — and
         * null on an INVOICE whatever the SKU says, because an invoice line has no split to fall
         * back on and naming capacity there would describe a way out that does not exist. See
         * {@see AdminOrderStockValidator::shortfallsForInvoice()}.
         *
         * A decimal string for the same reason `$available` is.
         */
        public readonly ?string $backorderCapacity,
        /**
         * Units nothing can cover: what was asked for, less what the split actually accepted.
         *
         * The COVER is measured and not re-derived — it is {@see BackorderSplit::accepted()}, off
         * the one object that decided how much ships now and how much waits. What changed when
         * `$requested` stopped being rounded is which side of the subtraction the exact figure is
         * on: 50.5 asked for against 12 on the shelf is 38.5 short, and quoting the split's own
         * `$uncovered` (39, measured from a demand rounded to 51) would put a sentence on screen
         * whose three figures do not subtract.
         */
        public readonly string $missing,
        /** 'order' or 'invoice' — what the sentence calls the document the record lands on. */
        public readonly string $documentNoun,
        /**
         * The posted row indexes that make up this demand, in posted order.
         *
         * @var list<int>
         */
        public readonly array $lineIndexes,
    ) {
    }

    /** "productId|warehouseId" — the key the requested-demand map and the own-hold maps share. */
    public function key(): string
    {
        return $this->product->getId() . '|' . $this->warehouse->getId();
    }

    /**
     * The finding in ONE sentence, used by both save screens.
     *
     * ## Tone is load-bearing here
     *
     * It states three figures and then says what happens next. It does not say "insufficient
     * stock", which reads as though the operator made a mistake — they did not; they asked for
     * something the shelf cannot cover today and they are allowed to. The figures come first
     * because the figures are the whole message: what was asked for, what is there, and the
     * difference, spelled out rather than left as arithmetic for the reader.
     *
     * ## The last two sentences are deliberate, in this order
     *
     * The override is offered BEFORE the retreats. An operator who already knows the delivery lands
     * Friday should not have to read past two ways of backing down to find the one that matches
     * what they are doing. The retreats stay, because reducing the quantity really is the right
     * answer when the number was a typo, and a draft really does hold anything.
     */
    public function describe(): string
    {
        // The quantities go through DisplayNumber rather than through %d, so 2.5 reads "2.5" and 50
        // still reads "50" — the one place that owns what a quantity looks like in this
        // application. It has no constructor and no state, so a value object with no container can
        // still ask it, and the alternative is a fourth hand-rolled copy of the rule.
        $display = new DisplayNumber();

        return sprintf(
            '%s: %s requested in %s but only %s available%s — %s short. This is yours to decide: say why in the reason box on the line and the save goes through, with your name, your reason and these figures recorded on the %s. Save as a draft, or reduce the quantity.',
            $this->product->getName(),
            $display->qty($this->requested),
            $this->regionName,
            $display->qty(max(0.0, (float) $this->available)),
            $this->backorderCapacity === null
                ? ''
                : sprintf(' and %s backorder capacity remaining', $display->qty(max(0.0, (float) $this->backorderCapacity))),
            $display->qty($this->missing),
            $this->documentNoun,
        );
    }
}
