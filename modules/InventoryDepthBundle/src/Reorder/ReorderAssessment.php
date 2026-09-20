<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Reorder;

use App\Service\QuantityScale;

use InventoryDepthBundle\Entity\ProductReorderRule;

/**
 * One (product, warehouse) row measured against its reorder level, at one instant (#597).
 *
 * Computed, never stored — the arithmetic below is redone on every render. #597 says why in one
 * line: "a cached reorder flag would drift the first time a PO changed", and a drifted flag is
 * invisible. Every other derived figure in this app is recomputed for the same reason.
 *
 * Built only by {@see ReorderPointRule}. It is a reading, not a service, which is why it is excluded
 * from the container alongside the movement value objects.
 */
final class ReorderAssessment
{
    public const STATUS_SHORT = 'Short';
    public const STATUS_COVERED = 'Covered by incoming';
    public const STATUS_ABOVE = 'Above its point';

    /**
     * @param int  $available          ProductInventory::getAvailableQuantity() — sellable now, and
     *                                 gate-aware, so it already excludes the buckets whose writing
     *                                 bundle is Inactive. 0 when there is no inventory row at all.
     * @param int  $incoming           `product_inventory.incoming_quantity`, or 0 when nothing is
     *                                 maintaining it. See $incomingMaintained. Purchase orders only
     *                                 — see $transferIncoming for the other source of "more is
     *                                 coming".
     * @param bool $incomingMaintained ProductInventory::positiveBucketsCount() — whether
     *                                 ProcurementBundle, the only writer of `incoming_quantity`, is
     *                                 Active. False makes $incoming 0 rather than reading a figure
     *                                 nothing is keeping true.
     * @param int  $transferIncoming   Dispatched, not yet received, toward this row's warehouse on
     *                                 an internal transfer (#706) — read live through
     *                                 {@see \App\Service\Inventory\InboundTransferResolver}, which
     *                                 already answers 0 when WarehouseOpsBundle is absent or
     *                                 Inactive, so there is no separate "maintained" flag for this
     *                                 term the way $incoming needs one.
     */
    public readonly string $available;
    public readonly string $incoming;
    public readonly string $transferIncoming;

    public function __construct(
        public readonly ProductReorderRule $rule,
        string|int|float $available,
        string|int|float $incoming,
        public readonly bool $incomingMaintained,
        string|int|float $transferIncoming = 0,
    ) {
        // Held as decimals for the screen; every comparison below goes through *Units(), which is
        // whole units of the column's scale, so nothing here compares quantities as text or floats.
        $this->available = QuantityScale::canonical($available);
        $this->incoming = QuantityScale::canonical($incoming);
        $this->transferIncoming = QuantityScale::canonical($transferIncoming);
    }

    public function point(): string
    {
        return QuantityScale::canonical($this->rule->getReorderPoint());
    }

    /**
     * What this row is expected to have once everything already ordered — or already on a
     * transfer — has landed.
     *
     * `available` + `incoming` + `transferIncoming`, and nothing else. `backordered` is NOT added
     * back: it is already subtracted inside `getAvailableQuantity()`, and units owed to a customer
     * are not units available to the next one.
     */
    public function projected(): string
    {
        return QuantityScale::add(QuantityScale::add($this->available, $this->incoming), $this->transferIncoming);
    }

    /** Sellable stock has reached the level. True for every row on the screen, covered or not. */
    public function belowPoint(): bool
    {
        return QuantityScale::compare($this->available, $this->point()) <= 0;
    }

    /**
     * Genuinely short: even counting what is already on order, this row does not clear its level.
     *
     * `<=`, not `<`. #597 writes the comparison as `projected < reorder_point` but also states that
     * 0 is a real reorder point meaning "order when you hit nothing left" — and under `<` a point of
     * 0 fires only once the row is already OVERSOLD, which is not what that sentence describes. `<=`
     * is the reading that makes both halves of the issue true, and it is what "at or below its
     * level" means everywhere else on the screen.
     */
    public function short(): bool
    {
        return QuantityScale::compare($this->projected(), $this->point()) <= 0;
    }

    /**
     * Below its level on sellable stock, but a purchase order already closes the gap.
     *
     * This is the whole reason #597 waited for #583. Without `incoming_quantity` every one of these
     * rows would be flagged again tomorrow, and the day after, for stock somebody already ordered —
     * which is how a low-stock screen becomes noise and then becomes ignored.
     */
    public function covered(): bool
    {
        return $this->belowPoint() && !$this->short();
    }

    /** How many units short of the level, after counting what is coming. 0 when not short. */
    public function shortfall(): string
    {
        return $this->short() ? QuantityScale::sub($this->point(), $this->projected()) : QuantityScale::canonical(0);
    }

    /**
     * What to suggest ordering. The standing answer if one was set, otherwise the shortfall — the
     * smallest order that clears the flag.
     *
     * A number on a screen. Nothing acts on it.
     */
    public function suggestedOrder(): string
    {
        $configured = $this->rule->getReorderQuantity();

        return $configured === null ? $this->shortfall() : QuantityScale::canonical($configured);
    }

    /**
     * Into the buffer that was never supposed to be touched. A second, more urgent band — it does
     * not decide whether the row is short, only how loudly it is shown.
     */
    public function belowSafetyStock(): bool
    {
        $safety = $this->rule->getSafetyStockQuantity();

        return $safety !== null && QuantityScale::compare($this->projected(), $safety) <= 0;
    }

    public function status(): string
    {
        if ($this->short()) {
            return self::STATUS_SHORT;
        }

        return $this->belowPoint() ? self::STATUS_COVERED : self::STATUS_ABOVE;
    }
}
