<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\InvoiceLineStockOverride;
use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderLine;
use App\Entity\SalesOrderLineStockOverride;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The two halves of an override: the one thing still withheld, and the record that replaces it.
 *
 * Copied deliberately from what landed at receiving — `ReceivingService::assertLineIsBookable()`
 * refuses a short-dated line only until somebody says why, and `recordShortDated()` writes the row
 * once they have — rather than inventing a second mechanism for the sell side. Same two steps, same
 * order, same rule about a blank.
 *
 * ## Why anything is withheld at all
 *
 * Nothing here doubts the operator. Overselling is theirs to decide and the application's job is to
 * state the figures and get out of the way. What a save with no reason on it produces, though, is
 * an oversell with nobody's name and nothing to read a fortnight later when somebody asks why the
 * SKU went to −490 — which is indistinguishable from the hole #326 closed. The record IS the
 * feature; the gate exists only because the record cannot be written without its one required
 * field.
 *
 * ## A blank is not a reason, in both layers
 *
 * {@see StockOverrideReasons} trims and drops empties on the way IN, and {@see self::explained()}
 * re-checks it at the one place a row is built and the one place the notice is chosen. That is two
 * gates on one fact and it is deliberate: a blank nullified in the parser but passed through by the
 * writer, or the reverse, saves a row with `reason = ''` — an override record that explains nothing
 * while looking exactly like one, which is the same as no record at all.
 */
final class StockOverrideRecorder
{
    public function __construct(
        private readonly WarehouseFulfillmentRegionService $warehouses,
    ) {
    }

    /**
     * The sentence to show, or null when every shortfall has been answered.
     *
     * ONE sentence for one banner, matching how both save screens already report a save they cannot
     * take: one flash, one banner, the form re-rendered. An operator answers one line, re-saves and
     * meets the next — which is the same loop they were already in — and a list would need a second
     * presentation path on two screens for no gain.
     *
     * @param list<StockShortfall> $shortfalls
     */
    public function refusalFor(array $shortfalls, StockOverrideReasons $reasons): ?string
    {
        foreach ($shortfalls as $shortfall) {
            if (self::explained($reasons, $shortfall) === null) {
                return $shortfall->describe();
            }
        }

        return null;
    }

    /**
     * Writes an override row for every shortfall on this order that somebody explained.
     *
     * Called AFTER the lines are on the order and BEFORE the save flushes, which is the same
     * position `recordShortDated()` holds inside `ReceivingService::receive()`: the document and its
     * record are written by one transaction, so an override can never outlive a save that rolled
     * back, nor a save outlive its record.
     *
     * `refusalFor()` has already turned away the case of a shortfall with NO reason, so by the time
     * this runs the two can only be both present or the shortfall absent.
     *
     * @param list<StockShortfall> $shortfalls
     * @param string|null $actor who decided, as a display name
     */
    public function recordForOrder(
        SalesOrder $order,
        array $shortfalls,
        StockOverrideReasons $reasons,
        ?string $actor,
        EntityManagerInterface $entityManager,
    ): void {
        if ($shortfalls === []) {
            return;
        }

        $linesByKey = $this->orderLinesByProductAndWarehouse($order);

        foreach ($shortfalls as $shortfall) {
            $line = $linesByKey[$shortfall->key()] ?? null;
            if (!$line instanceof SalesOrderLine) {
                // The demand was measured off the POST and this reads the SAVED lines, so a row the
                // save dropped on its way through — a blank line, a product that vanished between
                // the two reads — has nothing to hang a record on. Skipped rather than invented:
                // a record pointing at no line answers nobody.
                continue;
            }

            $override = $line->getStockOverride() ?? new SalesOrderLineStockOverride();
            $override->setOrderLine($line);
            $line->setStockOverride($override);

            $this->record($override, $shortfall, $reasons, $actor, $entityManager);
        }
    }

    /**
     * The same, for a standalone invoice. See {@see self::recordForOrder()}.
     *
     * @param list<StockShortfall> $shortfalls
     */
    public function recordForInvoice(
        Invoice $invoice,
        array $shortfalls,
        StockOverrideReasons $reasons,
        ?string $actor,
        EntityManagerInterface $entityManager,
    ): void {
        if ($shortfalls === []) {
            return;
        }

        $linesByKey = $this->invoiceLinesByProductAndWarehouse($invoice);

        foreach ($shortfalls as $shortfall) {
            $line = $linesByKey[$shortfall->key()] ?? null;
            if (!$line instanceof InvoiceLine) {
                continue;
            }

            $override = $line->getStockOverride() ?? new InvoiceLineStockOverride();
            $override->setInvoiceLine($line);
            $line->setStockOverride($override);

            $this->record($override, $shortfall, $reasons, $actor, $entityManager);
        }
    }

    /**
     * Fills one row from one measurement, whichever document it belongs to.
     *
     * The figures come off the {@see StockShortfall} the notice was built from, never re-measured
     * here. Re-measuring would be a second read of a moving number: between the warning and this
     * line another save can have taken stock, and a record whose figures disagree with the sentence
     * the operator answered is a record of a decision nobody took.
     *
     * The reason is read back out of {@see StockOverrideReasons} rather than passed in, so the
     * parsing is done once, by the object that owns it — and it goes through
     * {@see self::explained()}, which is the second gate on a blank. See this class's docblock.
     *
     * Typed to the two concrete classes rather than to a shared interface. There was one, briefly,
     * and it earned nothing: the shape the two rows share is real but nothing ever needed to hold
     * one without knowing which it was, so the interface was a second place to keep the same list of
     * getters in step. A union of two says the same thing and cannot drift.
     */
    private function record(
        SalesOrderLineStockOverride|InvoiceLineStockOverride $override,
        StockShortfall $shortfall,
        StockOverrideReasons $reasons,
        ?string $actor,
        EntityManagerInterface $entityManager,
    ): void {
        $reason = self::explained($reasons, $shortfall);
        if ($reason === null) {
            return;
        }

        $override
            ->setRegionName($shortfall->regionName)
            // Handed over as measured, with no round() and no (int) anywhere between the form post
            // and the column: both are `decimal(14, 4)` and the entity applies that scale itself.
            // Availability is still an int on the way in — the inventory layer counts in whole
            // units until #646 — and 12 lands in the column as 12.0000, which loses nothing and
            // needs no second change here on the day that layer widens.
            ->setRequestedQuantity($shortfall->requested)
            ->setAvailableQuantity((string) $shortfall->available)
            ->setBackorderCapacity($shortfall->backorderCapacity ?? 0)
            ->setReason($reason)
            ->setOverriddenBy($actor)
            ->setOverriddenAt(new \DateTimeImmutable());

        $entityManager->persist($override);
    }

    /**
     * The reason answering this shortfall, or null when there is not one worth writing down.
     *
     * **The second gate on a blank, and deliberately not the same gate.**
     * {@see StockOverrideReasons::nullable()} drops an empty or whitespace box on the way IN, which
     * is where the rule belongs; this re-checks it at the one place a row is actually built. Two
     * checks on one fact is normally the thing this codebase refuses — but the failure mode here is
     * specific and was found twice tonight in other work: a blank nullified by the parser and passed
     * through by the writer (or the reverse) saves a row with `reason = ''`, an override record that
     * explains nothing while looking exactly like one. The cost of the duplication is one `trim()`;
     * the cost of the hole is the whole point of the feature.
     *
     * Both call sites go through here, so the sentence the operator is shown and the row that is
     * written can never disagree about whether the shortfall was answered.
     */
    private static function explained(StockOverrideReasons $reasons, StockShortfall $shortfall): ?string
    {
        $reason = trim((string) $reasons->forShortfall($shortfall));

        return $reason === '' ? null : $reason;
    }

    /**
     * The order's saved lines keyed the way a shortfall is keyed, first row of each group winning.
     *
     * First rather than all, for the reason {@see StockShortfall} gives: the demand is a group and
     * the decision about it is one decision. Attaching a copy of it to every row of the group would
     * put the same figures and the same sentence on the document twice and make a reader count two
     * oversells where one happened.
     *
     * @return array<string, SalesOrderLine>
     */
    private function orderLinesByProductAndWarehouse(SalesOrder $order): array
    {
        $warehousesByLowerRegionName = $this->warehouses->warehousesByLowerRegionName();
        $byKey = [];

        foreach ($order->getLines() as $line) {
            $key = $this->keyFor($line->getProduct(), $line->getLocation(), $order->getFulfillmentRegion(), $warehousesByLowerRegionName);
            if ($key !== null && !isset($byKey[$key])) {
                $byKey[$key] = $line;
            }
        }

        return $byKey;
    }

    /** @return array<string, InvoiceLine> */
    private function invoiceLinesByProductAndWarehouse(Invoice $invoice): array
    {
        $warehousesByLowerRegionName = $this->warehouses->warehousesByLowerRegionName();
        $byKey = [];

        foreach ($invoice->getLines() as $line) {
            $key = $this->keyFor($line->getProduct(), $line->getLocation(), $invoice->getFulfillmentRegion(), $warehousesByLowerRegionName);
            if ($key !== null && !isset($byKey[$key])) {
                $byKey[$key] = $line;
            }
        }

        return $byKey;
    }

    /**
     * "productId|warehouseId" for one saved line, or null when it names no product or no region.
     *
     * Resolved through `OrderInventoryBucketResolver::resolveLineWarehouse()`, which is the same
     * call the measurement made off the post and the same one the reconciler makes afterwards — so
     * a line that was measured, a line that is recorded and a line that reserves are the same line
     * by construction rather than by three agreeing implementations.
     *
     * @param array<string, Warehouse> $warehousesByLowerRegionName
     */
    private function keyFor(
        ?ProductCore $product,
        ?string $lineLocation,
        ?string $documentRegionName,
        array $warehousesByLowerRegionName,
    ): ?string {
        if (!$product instanceof ProductCore) {
            return null;
        }

        $warehouse = OrderInventoryBucketResolver::resolveLineWarehouse(
            $lineLocation,
            $documentRegionName,
            $warehousesByLowerRegionName,
        );

        return $warehouse instanceof Warehouse ? $product->getId() . '|' . $warehouse->getId() : null;
    }
}
