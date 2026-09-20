<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Reorder;

use App\Entity\ProductInventory;
use App\Service\Inventory\InboundTransferResolver;
use InventoryDepthBundle\Entity\ProductReorderRule;

/**
 * The rule: is this (product, warehouse) below its reorder level, and is anything already on the way (#597).
 *
 * ## The projection, and the one term that is not in availability
 *
 * ```
 * available  = quantity + received + transfer_in - transfer_out - write_off - quarantine
 *                       - cart_hold - sales_hold - pending - approved - backordered
 * projected  = available + incoming + transferIncoming
 * ```
 *
 * `available` is not recomputed here. It is asked of the entity, once, through
 * `getAvailableQuantity()` — which is gate-aware, so a bucket whose writing bundle is Inactive is
 * already absent from it. Restating that formula in this bundle would be a second definition of
 * availability that drifts the first time core changes one term.
 *
 * `incoming` is NOT in `available`, deliberately and permanently: units on a purchase order do not
 * exist here yet, and adding them to availability would let a purchase order sell stock nobody has.
 * It IS in the projection, because the reorder decision is the one question those units answer —
 * "is more of this already on its way". A product below its level with a purchase order already
 * covering the gap must not be flagged again tomorrow, and again the day after, or the screen turns
 * into noise inside a week and everybody learns to scroll past it.
 *
 * ## `transferIncoming` — the term this class was missing (#706)
 *
 * `incoming` above only ever counted open PURCHASE orders. A warehouse waiting on an inbound
 * TRANSFER from another warehouse still read as short and got a purchase order suggested on top of
 * stock already rolling toward it — a real double-buy risk, sharpest on perishables. `transfer_in`
 * on `ProductInventory` does not answer this either: it only credits a transfer once it is
 * RECEIVED (see that entity's own docblock), so nothing on the cached row says "dispatched but not
 * here yet". `transferIncoming` reads that live, off the transfer documents themselves, through
 * {@see \App\Service\Inventory\InboundTransferResolver} — the same tagged-provider seam
 * `incoming`'s own docblock below describes for a bundle boundary, crossed here in the opposite
 * direction (InventoryDepthBundle asking WarehouseOpsBundle, not ProcurementBundle asking this
 * bundle).
 *
 * ## With ProcurementBundle off, `incoming` is not read at all
 *
 * `IncomingStockReconciler` is the only writer of `product_inventory.incoming_quantity`, and it
 * refuses to write while its own bundle is Inactive. Nothing is zeroed on the way out, so the column
 * keeps whatever it last held — a forecast that was true on the day the bundle was switched off and
 * has not been maintained since.
 *
 * So this reads it only when `positiveBucketsCount()` — the flag
 * `App\EventSubscriber\BundleBucketAvailabilityGate` stamps on every load, for that same bundle —
 * says it is still being maintained. Otherwise `incoming` is 0 and `projected` collapses to
 * `available`.
 *
 * The direction of that choice is the point. Treating a stale forecast as real would HIDE
 * shortages: a row would sit on the screen marked "covered by 50 incoming" against a purchase order
 * that can no longer be received, because with the bundle off there is no receiving screen to
 * receive it on. Treating it as 0 can only ever show MORE rows as short, which is a screen that
 * over-asks rather than one that quietly under-reports. It also matches what the buyer can actually
 * do about it: with procurement off, no purchase order exists to be relied on.
 *
 * The flag is stamped on LOAD, so an entity built with `new` — a unit test, a row created in the
 * same request — carries the default `true`. That is harmless here for the same reason it is
 * harmless in the gate: a row that has never been loaded has `incoming = 0`, and 0 is 0 either way.
 *
 * ## No forecasting, no history, no dates
 *
 * Two numbers and a comparison. #597 rules out lead times, order multiples, policy enums and
 * planning against dated supply and demand — that layer wants a bundle of its own and data this app
 * does not model. What is here is the foundation such a bundle would sit on, not a half version of
 * it.
 */
final class ReorderPointRule
{
    public function __construct(
        private readonly InboundTransferResolver $transfers,
    ) {
    }

    /**
     * Measures one managed row.
     *
     * @param ProductInventory|null $inventory the `product_inventory` row for the same
     *                                         (product, warehouse), or null when there is none.
     *                                         Null is treated as zero of everything — a level set
     *                                         for a pair that holds no stock row is as short as it
     *                                         is possible to be, which is exactly right and is why
     *                                         it is not silently skipped.
     */
    public function assess(ProductReorderRule $rule, ?ProductInventory $inventory): ReorderAssessment
    {
        $maintained = $inventory?->positiveBucketsCount() ?? true;

        return new ReorderAssessment(
            $rule,
            $inventory?->getAvailableQuantity() ?? 0,
            $maintained ? ($inventory?->getIncomingQuantity() ?? 0) : 0,
            $maintained,
            // Live, not cached — see this class's own docblock on why `transfer_in` can't answer
            // this. `InboundTransferResolver` already reads 0 when WarehouseOpsBundle is absent or
            // Inactive, so there is no separate "maintained" flag to thread through here the way
            // `incoming` needs one for its cached, potentially-stale column.
            $this->transfers->inTransitQuantity($rule->getProduct(), $rule->getWarehouse()),
        );
    }
}
