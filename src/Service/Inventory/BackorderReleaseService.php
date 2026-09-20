<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Service\QuantityScale;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\SalesOrderLine;
use App\Entity\Warehouse;
use App\Repository\BackorderFulfillmentEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Assigning arrived stock to the orders waiting for it (#548).
 *
 * Two modes exist from the start — an admin working the queue at /admin/backorders, and
 * `auto_release_on_restock` doing it the moment stock lands — and both go through applyRelease().
 * One primitive rather than two, because the bucket arithmetic, the audit trail and the queue
 * entry's lifecycle have to be identical whichever triggered it: the requirement is a queue where
 * an automatically-resolved episode appears next to a manually-resolved one and reads the same.
 * The only difference between the two routes is whose name ends up in the log.
 *
 * ## What this does NOT do
 *
 * It never touches ProductInventory's buckets and never opens or closes a queue entry. It changes
 * one number on one line and lets the flush do the rest: InventoryReconciliationSubscriber already
 * watches every SalesOrderLine write, and the same flush re-runs both reservation passes (growing
 * `sales_hold` as `backordered` shrinks) and re-derives the queue entries. Reaching into the
 * buckets from here would be a second writer of numbers that already have exactly one.
 */
final class BackorderReleaseService
{
    /** The resolver recorded when nothing human did it. */
    public const SYSTEM_ACTOR = 'System';

    public function __construct(
        private readonly BackorderFulfillmentEntryRepository $entries,
        private readonly BackorderSplitResolver $splits,
    ) {
    }

    /**
     * What a restock does to the backorder settings and the queue, if anything (#548).
     *
     * The one place both restock call sites — the admin inventory grid and the product import —
     * route through, so an arriving unit is treated identically however it arrived. Call AFTER the
     * new quantity is on the row and BEFORE the caller's flush, with the quantity the row held
     * before.
     *
     * Both toggles are independent and both default to off, which is where every row starts: a SKU
     * may shrink its cap without auto-releasing, auto-release without shrinking, both, or — until
     * an admin says otherwise — neither, which is exactly today's behaviour.
     *
     * Order matters. The cap shrinks first, because it governs what may be promised NEXT and must
     * not be reduced by units the release below is about to consume; the release then runs against
     * the stock that actually arrived.
     */
    /** $previousQuantity is the figure held before the restock; the figure returned is a decimal quantity. */
    public function applyRestock(ProductInventory $inventory, string|int|float $previousQuantity, EntityManagerInterface $entityManager): string
    {
        $arrived = QuantityScale::sub($inventory->getQuantity(), $previousQuantity);
        if ($arrived <= 0) {
            // A correction downward is not a restock. Nothing arrived, so nothing is owed
            // differently and nothing can be released.
            return QuantityScale::canonical(0);
        }

        if ($inventory->isBackorderCapShrinksOnRestock() && $inventory->getMaxBackorderQuantity() !== null) {
            // Preorder mode: the cap was a total, not a rate, so stock arriving spends it. Clamped
            // at zero, and deliberately allowed to fall below what is currently outstanding — it
            // governs new promises only and never retracts one already made.
            $capped = QuantityScale::sub($inventory->getMaxBackorderQuantity(), $arrived);
            $inventory->setMaxBackorderQuantity(
                QuantityScale::compare($capped, 0) < 0 ? QuantityScale::canonical(0) : $capped,
            );
            $entityManager->persist($inventory);
        }

        if (!$inventory->isAutoReleaseOnRestock()) {
            return QuantityScale::canonical(0);
        }

        $warehouse = $inventory->getWarehouse();

        return $warehouse instanceof Warehouse
            ? $this->releaseAutomatically($inventory->getProduct(), $warehouse, $entityManager)
            : QuantityScale::canonical(0);
    }

    /**
     * Release $amount units of one line's promise into real stock.
     *
     * Returns how much was actually released, which may be less than asked for and may be zero —
     * the caller states an intent, this states the outcome. Clamped rather than refused because
     * both callers re-read live numbers immediately before calling and the clamp is the last word
     * on a value that can move in between.
     */
    /** Decimal quantity in, decimal quantity out; clamped to what the line still owes. */
    public function applyRelease(SalesOrderLine $line, string|int|float $amount, string $resolvedBy, EntityManagerInterface $entityManager): string
    {
        $outstanding = $line->getBackorderedQuantity();
        $released = QuantityScale::compare($amount, $outstanding) > 0 ? $outstanding : QuantityScale::canonical($amount);
        if (QuantityScale::compare($released, 0) <= 0) {
            return QuantityScale::canonical(0);
        }

        $remaining = QuantityScale::sub($outstanding, $released);
        $line->setBackorderedQuantity($remaining);
        $entityManager->persist($line);

        // The order's own timeline is where "who resolved this, and when" lives — the queue entry
        // deliberately does not carry a second copy of it. See BackorderFulfillmentEntry.
        $line->getOrder()->queueActivityLogEntry()
            ->setUserName($resolvedBy)
            ->setType('System')
            ->setComment(sprintf(
                'Backorder release: %s unit(s) of %s assigned by %s (%s still backordered).',
                QuantityScale::trim($released),
                $line->getSku() ?: $line->getName(),
                $resolvedBy,
                QuantityScale::trim($remaining),
            ));

        return $released;
    }

    /**
     * Give whatever just became available to whoever has been waiting longest.
     *
     * FIFO by order then line position, because nothing in this schema ranks one waiting customer
     * above another — there is no tier, no SLA, no promised date but the one an admin typed. See
     * BackorderFulfillmentEntryRepository::openFifoFor().
     *
     * What it draws on is getStockAvailableToRelease(), NOT getAvailableQuantity(). A promised unit
     * is subtracted from availability so that nobody else can take it, which means a restock that
     * exactly covers the queue reads as zero available — and releasing against that figure would
     * leave the stock sitting next to the orders it arrived for. See the entity for the full
     * reasoning. Nothing to hand out is the ordinary case and a no-op.
     *
     * Callers must flush; this persists only, so a restock and the releases it triggered commit
     * together or not at all.
     */
    public function releaseAutomatically(ProductCore $product, Warehouse $warehouse, EntityManagerInterface $entityManager): string
    {
        $inventory = $this->splits->inventoryFor($product, $warehouse, $entityManager);
        if (!$inventory instanceof ProductInventory) {
            return 0;
        }

        $spare = QuantityScale::canonical($inventory->getStockAvailableToRelease());
        if (QuantityScale::compare($spare, 0) <= 0) {
            return QuantityScale::canonical(0);
        }

        $releasedTotal = QuantityScale::canonical(0);

        foreach ($this->entries->openFifoFor($product, $warehouse) as $entry) {
            if (QuantityScale::compare($spare, 0) <= 0) {
                break;
            }

            $line = $entry->getLine();
            $owed = $line->getBackorderedQuantity();
            $take = QuantityScale::compare($spare, $owed) < 0 ? $spare : $owed;
            $released = $this->applyRelease($line, $take, self::SYSTEM_ACTOR, $entityManager);
            $spare = QuantityScale::sub($spare, $released);
            $releasedTotal = QuantityScale::add($releasedTotal, $released);
        }

        return $releasedTotal;
    }
}
