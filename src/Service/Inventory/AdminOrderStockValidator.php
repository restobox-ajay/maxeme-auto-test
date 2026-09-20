<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\SalesOrder;
use App\Entity\Warehouse;
use App\Service\QuantityScale;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Whether an admin-entered set of order lines can actually be covered by stock (#326).
 *
 * The customer side has always had this guarantee — CartService caps or removes a line that exceeds
 * availability — while the admin side had nothing at all. `ProductInventory` was never consulted
 * anywhere in order create or edit, so an admin could save 500 units of a product with 2 in stock
 * and the system took it. Reconciliation then wrote those 500 into a hold bucket after the fact,
 * because reconciliation is bookkeeping: it records what the order says, it does not judge it. The
 * result is a negative getAvailableQuantity(), which then makes the SKU unbuyable for every
 * customer.
 *
 * Two rules, both from the issue:
 *
 * A DRAFT MAY HOLD ANYTHING. A draft is exploratory and reserves no stock — bucketForStatus()
 * returns null for it, so nothing it contains is subtracted from availability and there is nothing
 * to protect. Validating drafts would block the ordinary workflow of building an order before
 * deciding what is really available.
 *
 * ANY STATUS THAT HOLDS STOCK IS MEASURED. The moment a save moves the order into a status
 * OrderInventoryBucketResolver counts — Approved and everything after it — every line is weighed
 * against what can cover it. Note that this is asked of the STATUS, not of the remaining quantity: a
 * fully invoiced order holds nothing right now, but raising a line on it makes it owe again, and
 * that is exactly the save this has to be able to measure.
 *
 * ## Measuring is not refusing — this class stopped being a gate
 *
 * It used to answer `?string`: the first refusal, or null. An ADMIN save that exceeded the shelf was
 * turned away outright.
 *
 * That is no longer the ruling, and the change is smaller than it looks. **Selling beyond the shelf
 * is the operator's to decide.** They know things this database does not — a delivery landing on
 * Friday, a drop-ship, a substitution, a customer content to wait — and an admin deliberately
 * overselling with their name on it is a different act from a customer doing it silently. So this
 * class now answers with the FIGURES ({@see StockShortfall}), the save screens state them plainly,
 * and the only thing actually withheld is a save with no reason on it: see
 * {@see StockOverrideRecorder}, which writes what was decided, by whom, and against what numbers.
 *
 * **The customer path is untouched by all of it.** A customer accepting a quote for 500 units
 * against 5 in stock still produces a held Draft, because `EstimateConversionService` asks
 * {@see self::shortfallsForOrder()} — a different method, reading an order's own lines rather than a
 * form post — and that method's behaviour and every caller of it are exactly as they were.
 *
 * ## The order's own reservation is added back
 *
 * The subtlety that makes or breaks this. An order already holding 5 units has those 5 inside
 * ProductInventory, so getAvailableQuantity() already excludes them. Re-saving that order unchanged
 * would compare 5 requested against an availability that has 5 of its own units subtracted, and
 * refuse a save that changes nothing. The same mistake on the customer side was issue #214: a cart
 * blocked itself by counting its own hold twice.
 *
 * So this adds back whatever this order currently contributes — and from #539 stage 3 that is TWO
 * ledgers, not one. The order's own OrderInventoryReservation rows hold its uninvoiced remainder in
 * `sales_hold`; everything it has already billed is held by its invoices in `pending`/`approved`,
 * through InvoiceInventoryReservation. Both are the order's own units and both are already
 * subtracted from availability, so reading only the first would make every invoiced order look
 * short by exactly the quantity it had invoiced.
 *
 * It reads getEffectiveQuantity() rather than getQuantity(), because that is what actually reached
 * the bucket — an approved reservation partly absorbed by a product-import recount contributes only
 * the unabsorbed remainder.
 */
final class AdminOrderStockValidator
{
    public function __construct(
        private readonly WarehouseFulfillmentRegionService $warehouses,
        private readonly BackorderSplitResolver $backorderSplits,
        private readonly LotAvailabilityResolver $lotAvailability,
    ) {
    }

    /**
     * Every (product, region) on this save that nothing can cover, with the figures behind each.
     *
     * Empty when every line fits, which is the ordinary case and the one that costs nothing. What
     * the caller does with a non-empty answer is the caller's: the save screens state the first
     * one and take a reason for it — see {@see StockOverrideRecorder} — and nothing here refuses
     * anything.
     *
     * ALL of them rather than the first, which is the change from the gate this replaced. That one
     * could stop at the first because a refused save wrote nothing; an accepted one has to write a
     * record for every line that went beyond the shelf, and a list that stopped early would leave
     * the second one overridden and unrecorded. The single-message presentation is unchanged — see
     * {@see StockOverrideRecorder::refusalFor()}, which still picks one sentence for one banner.
     *
     * @param list<array<string, mixed>> $postedLines raw `lines[i]` rows, exactly as posted
     * @param string $targetStatus the status this save will land the order in, not its current one
     * @param SalesOrder|null $existingOrder null on create; on edit, the order whose own
     *                                       reservations must not count against it
     * @return list<StockShortfall>
     */
    public function shortfallsFor(
        array $postedLines,
        string $targetStatus,
        ?string $orderRegionName,
        ?SalesOrder $existingOrder,
        EntityManagerInterface $entityManager,
    ): array {
        // Draft, Void, a legacy string the enum no longer knows: nothing that reserves, so there is
        // no stock commitment to measure and nothing to say about it.
        if (OrderInventoryBucketResolver::bucketForStatus($targetStatus) === null) {
            return [];
        }

        return $this->shortfallsForHeldLines($postedLines, $orderRegionName, $existingOrder, $entityManager, true, 'order');
    }

    /**
     * The same ceiling, asked of an INVOICE save.
     *
     * ## An invoice does hold stock, and that is why this exists
     *
     * Invoicing moves no stock — there is no StockMovement anywhere in OrderInvoicingService or
     * InvoiceController, and a sell-side invoice is a money document. But it HOLDS stock:
     * InvoiceReservationSubject puts what each line bills into `pending_quantity` or
     * `approved_quantity` through InventoryReservationReconciler, exactly as an approved order puts
     * its remainder into `sales_hold_quantity`. So a standalone invoice for 500 units of a product
     * with 2 in stock drives getAvailableQuantity() negative and makes the SKU unbuyable for every
     * customer — which is #326 verbatim, on the other document.
     *
     * Whether the save holds anything is the INVOICE's status question, so it is asked of
     * InvoiceInventoryBucketResolver rather than of the order's table: Draft and On Hold hold
     * nothing and are never measured, Pending / Processing / Completed hold and are.
     *
     * ## Backorder capacity does NOT excuse a shortfall here
     *
     * The one deliberate difference from the order path. An order line that cannot be covered is
     * SPLIT — BackorderSplitResolver records the uncovered part as backordered units and the order
     * holds only the stocked portion, so a SKU with capacity really can absorb the overage. An
     * invoice line has no such split: `sales_order_line.backordered_quantity` is an order column,
     * and InvoiceReservationSubject::stockedQuantityFor() returns the full billed quantity for a
     * line with no order line behind it — which is every line on a standalone invoice. Letting
     * capacity excuse the shortfall would therefore reserve the whole amount anyway, so naming it
     * would describe a way out that does not exist. The override is the way out on this document,
     * and it is the operator's, not the SKU's setting's.
     *
     * No $existingOrder is taken because this answers a CREATE: a document that does not exist yet
     * contributes nothing to any bucket, so there is no own-hold to add back.
     *
     * @param list<array<string, mixed>> $postedLines raw `lines[i]` rows, exactly as posted
     * @return list<StockShortfall>
     */
    public function shortfallsForInvoice(
        array $postedLines,
        string $targetStatus,
        ?string $invoiceRegionName,
        EntityManagerInterface $entityManager,
    ): array {
        if (InvoiceInventoryBucketResolver::bucketForStatus($targetStatus) === null) {
            return [];
        }

        return $this->shortfallsForHeldLines($postedLines, $invoiceRegionName, null, $entityManager, false, 'invoice');
    }

    /**
     * The measurement itself, once the caller's document type has answered "does this save hold stock".
     *
     * @param list<array<string, mixed>> $postedLines
     * @param bool $backorderMayExcuse whether an opted-in SKU's remaining capacity counts as cover.
     *                                 True for an order, which splits the line; false for an
     *                                 invoice, which does not. See shortfallsForInvoice().
     * @param string $documentNoun what the sentence calls the document the record will land on
     * @return list<StockShortfall>
     */
    private function shortfallsForHeldLines(
        array $postedLines,
        ?string $orderRegionName,
        ?SalesOrder $existingOrder,
        EntityManagerInterface $entityManager,
        bool $backorderMayExcuse,
        string $documentNoun,
    ): array {
        // The lines name a REGION and stock lives in a WAREHOUSE, so what is looked up per line is
        // the warehouse serving the named region (#546).
        $warehousesByLowerRegionName = $this->warehouses->warehousesByLowerRegionName();
        $regionNamesByWarehouseId = $this->warehouses->regionNamesByWarehouseId();

        $requested = $this->requestedByProductAndWarehouse($postedLines, $orderRegionName, $warehousesByLowerRegionName, $regionNamesByWarehouseId, $entityManager);
        if ($requested === []) {
            return [];
        }

        $ownHold = $existingOrder instanceof SalesOrder
            ? $this->currentHoldFor($existingOrder, $entityManager)
            : [];
        // Bucket-filtered and separate from the combined figure above, because it answers a
        // different question: how full is the backorder ceiling, ignoring this order's own share of
        // it (#548). Folding the two together would credit an order's stock hold against its cap.
        $ownBackorderHold = $existingOrder instanceof SalesOrder
            ? $this->backorderSplits->ownBackorderHoldFor($existingOrder, $entityManager)
            : [];

        $shortfalls = [];

        foreach ($requested as $key => $row) {
            $inventory = $entityManager->getRepository(ProductInventory::class)->findOneBy([
                'product' => $row['product'],
                'warehouse' => $row['warehouse'],
            ]);

            // No inventory row at all means nothing has ever been stocked there. Treated as zero
            // rather than as unlimited: the alternative lets a typo'd region quietly bypass the
            // whole measurement.
            $available = QuantityScale::add($inventory?->getAvailableQuantity() ?? 0, $ownHold[$key] ?? 0);

            // No rounding left at this boundary. It used to be `(int) round($row['quantity'])`,
            // with a comment explaining that the inventory layer "counts in whole units today" —
            // which was true of the PHP layer and never of the columns. Availability, the buckets
            // and the reservations are decimal now, so the split is asked the exact question and a
            // 2.5 demand is no longer answered as a 3.
            $demand = QuantityScale::round($row['quantity'], $row['product']);

            // "Nothing can cover this" rather than "stock cannot cover this" (#548). For a SKU
            // nobody has opted in, the resolver returns zero backordered units and the two are the
            // same arithmetic about the same pool.
            $split = $backorderMayExcuse
                ? $this->backorderSplits->splitFor($inventory, $demand, $available, $ownBackorderHold[$key] ?? QuantityScale::canonical(0))
                // No split to fall back on, so the only cover is stock. See shortfallsForInvoice().
                : $this->backorderSplits->split($demand, $available, false, QuantityScale::canonical(0));

            // Asked of the split rather than of the exact arithmetic, deliberately: what the save
            // will actually RESERVE is the rounded figure, so a line the split covers is a line
            // nothing is short of once it is written. Deciding it exactly here would report a
            // shortfall that the reconciler then never creates.
            if ($split->isFullyCovered()) {
                continue;
            }

            // An opted-in SKU carries its capacity into the sentence even when that capacity is
            // ZERO, because "0 backorder capacity remaining" is the explanation an operator who
            // turned backorder on needs. Null — not 0 — for everyone else, so the clause is absent
            // rather than saying a cap exists and is empty. $backorderMayExcuse is what tells the
            // invoice apart: capacity is never cover there, whatever the SKU says.
            $capacity = $backorderMayExcuse && $inventory instanceof ProductInventory && $inventory->isAllowBackorder()
                ? $inventory->remainingBackorderCapacity($ownBackorderHold[$key] ?? 0)
                : null;

            $missing = QuantityScale::sub($demand, $split->accepted());

            $shortfalls[] = new StockShortfall(
                $row['product'],
                $row['warehouse'],
                $row['regionName'],
                $demand,
                $available,
                $capacity,
                // The COVER is taken off the split rather than re-derived, so what the sentence
                // says was covered and what the save will hold come from the one place that decided
                // it. What is subtracted from is the exact demand, so the three figures in the
                // sentence subtract and the record's derived shortfall agrees with them.
                QuantityScale::compare($missing, 0) > 0 ? $missing : QuantityScale::canonical(0),
                $documentNoun,
                $row['lineIndexes'],
            );
        }

        return array_merge(
            $shortfalls,
            $this->lotShortfalls($postedLines, $orderRegionName, $existingOrder, $entityManager, $warehousesByLowerRegionName, $regionNamesByWarehouseId, $documentNoun),
        );
    }

    /**
     * The same measurement as shortfallsForHeldLines(), one dimension narrower: every posted line
     * that names a lot is ALSO checked against what that specific lot has left, alongside — never
     * instead of — the aggregate product+warehouse check above (2026-09-14 lot/serial/expiry plan).
     *
     * A line naming no lot contributes nothing here, which is every line today for a product nobody
     * has opted into lot tracking — the whole reason this is additive. Reuses StockShortfall/the
     * override machinery unchanged: a lot-scoped shortfall is stated and recorded exactly like an
     * aggregate one, keyed by lot rather than by product — the override reason box and record do not
     * need to know which kind of shelf they are naming.
     *
     * No split to fall back on, ever, regardless of the SKU's own `allow_backorder` setting: that
     * setting is answered by the aggregate check above, against the aggregate shelf. A lot's own
     * shelf is what it physically has, and backordering against ONE lot while others of the same
     * product sit untouched is not a promise this method makes.
     *
     * @param list<array<string, mixed>> $postedLines
     * @param array<string, Warehouse> $warehousesByLowerRegionName
     * @param array<int, string> $regionNamesByWarehouseId
     * @return list<StockShortfall>
     */
    private function lotShortfalls(
        array $postedLines,
        ?string $orderRegionName,
        ?SalesOrder $existingOrder,
        EntityManagerInterface $entityManager,
        array $warehousesByLowerRegionName,
        array $regionNamesByWarehouseId,
        string $documentNoun,
    ): array {
        /** @var array<int, array{product: ProductCore, warehouse: Warehouse, regionName: string, quantity: float, lineIndexes: list<int>}> $requestedByLot */
        $requestedByLot = [];

        foreach ($postedLines as $lineIndex => $line) {
            if (!is_array($line)) {
                continue;
            }

            $lotId = (int) ($line['lot_id'] ?? 0);
            if ($lotId <= 0) {
                continue;
            }

            $productId = (int) ($line['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $product = $entityManager->find(ProductCore::class, $productId);
            if (!$product instanceof ProductCore) {
                continue;
            }

            $warehouse = OrderInventoryBucketResolver::resolveLineWarehouse(
                isset($line['location']) ? (string) $line['location'] : null,
                $orderRegionName,
                $warehousesByLowerRegionName,
            );
            if (!$warehouse instanceof Warehouse) {
                continue;
            }

            $quantity = (float) ($line['qty'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }

            $regionName = $regionNamesByWarehouseId[$warehouse->getId()] ?? $warehouse->getName();

            if (!isset($requestedByLot[$lotId])) {
                $requestedByLot[$lotId] = ['product' => $product, 'warehouse' => $warehouse, 'regionName' => $regionName, 'quantity' => 0.0, 'lineIndexes' => []];
            }
            $requestedByLot[$lotId]['quantity'] += $quantity;
            $requestedByLot[$lotId]['lineIndexes'][] = (int) $lineIndex;
        }

        if ($requestedByLot === []) {
            return [];
        }

        $shortfalls = [];

        foreach ($requestedByLot as $lotId => $row) {
            $available = $this->lotAvailability->availableForLot($lotId, $row['product']);
            if ($available === null) {
                // Not a real lot, or not this line's product's — nothing to check a lot-scoped
                // figure against. The aggregate product+warehouse check already measured this
                // quantity regardless.
                continue;
            }

            $ownLotHold = $existingOrder instanceof SalesOrder
                ? $this->backorderSplits->ownHoldForLot($existingOrder, $lotId, $entityManager)
                : QuantityScale::canonical(0);
            $availableTotal = QuantityScale::add($available, $ownLotHold);

            $demand = QuantityScale::round($row['quantity'], $row['product']);

            $split = $this->backorderSplits->split($demand, $availableTotal, false, QuantityScale::canonical(0));
            if ($split->isFullyCovered()) {
                continue;
            }

            $missing = QuantityScale::sub($demand, $split->accepted());

            $shortfalls[] = new StockShortfall(
                $row['product'],
                $row['warehouse'],
                $row['regionName'],
                $demand,
                $availableTotal,
                null,
                QuantityScale::compare($missing, 0) > 0 ? $missing : QuantityScale::canonical(0),
                $documentNoun,
                $row['lineIndexes'],
            );
        }

        return $shortfalls;
    }

    /**
     * What the posted lines ask for, summed per product and warehouse.
     *
     * Summed rather than checked row by row, because two lines of the same product in the same
     * warehouse compete for one pool — five plus five against six in stock has to be four short, and
     * measuring each row alone would find neither short. It is also what makes the notice name the
     * whole demand rather than whichever row happened to run out first.
     *
     * Region resolution goes through the same helper InventoryReservationReconciler::reconcile()
     * uses, so what is measured here is the same line that gets reserved afterwards. A line whose
     * region cannot be resolved is skipped for the same reason reconcile() skips it: it will
     * reserve nothing either.
     *
     * ## The quantity is summed EXACTLY, and is no longer rounded at all
     *
     * It used to be `(int) round((float) $line['qty'])` per row. That is the cast that made this
     * whole measurement lie about a fractional save: 2.5 units became 3 before anything had looked
     * at it, and 3 is what the notice quoted and what
     * `sales_order_line_stock_override.requested_quantity` then stored — as a decision somebody
     * took, which they had not.
     *
     * The rounding then moved to one place — the boundary where the split was asked for — because
     * the inventory layer counted in whole units. It does not any more: `QuantityType` is a decimal
     * type, availability and the buckets are decimal, and the split takes whole units of the
     * quantity scale. So the demand is summed as posted and handed over as posted, and the only
     * scale applied anywhere on this path is the store's own.
     *
     * ## The posted row indexes travel with the demand
     *
     * `lineIndexes` is what makes the summing survivable now that a shortfall can be ANSWERED. The
     * group is a product in a region, but the reason box is on a ROW, so the answer has to be
     * findable from the group and the record has to be able to name the row it was typed on. Keys,
     * not positions: the order form posts `lines[7][…]` and a deleted row leaves a gap, so the index
     * is read off the array rather than counted.
     *
     * @param array<int|string, mixed> $postedLines
     * @param array<string, Warehouse> $warehousesByLowerRegionName
     * @param array<int, string> $regionNamesByWarehouseId
     * @return array<string, array{product: ProductCore, warehouse: Warehouse, regionName: string, quantity: float, lineIndexes: list<int>}>
     */
    private function requestedByProductAndWarehouse(
        array $postedLines,
        ?string $orderRegionName,
        array $warehousesByLowerRegionName,
        array $regionNamesByWarehouseId,
        EntityManagerInterface $entityManager,
    ): array {
        $requested = [];

        foreach ($postedLines as $lineIndex => $line) {
            if (!is_array($line)) {
                continue;
            }

            $productId = (int) ($line['product_id'] ?? 0);
            if ($productId <= 0) {
                // A blank or custom-named line has no product and therefore no stock to consume.
                continue;
            }

            $product = $entityManager->find(ProductCore::class, $productId);
            if (!$product instanceof ProductCore) {
                continue;
            }

            $warehouse = OrderInventoryBucketResolver::resolveLineWarehouse(
                isset($line['location']) ? (string) $line['location'] : null,
                $orderRegionName,
                $warehousesByLowerRegionName,
            );
            if (!$warehouse instanceof Warehouse) {
                continue;
            }

            // Carried alongside the warehouse because the notice names the REGION — "Warehouse" is
            // internal vocabulary — and it is the region's canonical spelling, not whatever casing
            // the line happened to use, because matching is case-insensitive.
            $regionName = $regionNamesByWarehouseId[$warehouse->getId()] ?? $warehouse->getName();

            $quantity = (float) ($line['qty'] ?? 0);
            if ($quantity <= 0) {
                // Zero is a legal quantity on a line (placeholder and soft-note rows) and consumes
                // nothing; a negative one is coerced to zero elsewhere in the save.
                continue;
            }

            $key = $product->getId() . '|' . $warehouse->getId();
            if (!isset($requested[$key])) {
                $requested[$key] = ['product' => $product, 'warehouse' => $warehouse, 'regionName' => $regionName, 'quantity' => 0.0, 'lineIndexes' => []];
            }
            $requested[$key]['quantity'] += $quantity;
            $requested[$key]['lineIndexes'][] = (int) $lineIndex;
        }

        return $requested;
    }

    /**
     * What this order currently contributes to every bucket, across both of its ledgers, keyed the
     * same way — so it can be added back to availability rather than counted against the order that
     * owns it. See the note above on why the invoices are read as well as the order.
     *
     * @return array<string, string>
     */
    private function currentHoldFor(SalesOrder $order, EntityManagerInterface $entityManager): array
    {
        // Delegated since #548, when the line splitter came to need the identical figure. The
        // reasoning above is why this add-back exists; BackorderSplitResolver::ownHoldFor() is the
        // one implementation of it, because two would eventually add back different things.
        return $this->backorderSplits->ownHoldFor($order, $entityManager);
    }

    /**
     * Every line on an existing order that stock cannot cover, with the numbers behind it.
     *
     * The conversion counterpart to shortfallsFor(), and NOT the same question.
     *
     * That one answers a form POST, where an operator is standing at a screen, is shown the figures
     * and may decide to go past them with their name on it. This one answers a CUSTOMER'S
     * ACCEPTANCE, where nobody is standing at a form at all: the whole shortfall has to travel to an
     * admin in one message, so it reports all of them with the arithmetic. There is no override on
     * this path and there must not be — a customer overselling silently is the #326 defect itself,
     * and `EstimateConversionService` answers it by holding the order as a Draft rather than by
     * refusing an acceptance the customer cannot resolve. Nothing about this method changed when the
     * admin screens gained the override, deliberately.
     *
     * Reads the order's lines rather than a post because by this point the order exists — conversion
     * has already copied the quote onto it verbatim.
     *
     * The order's own hold is added back for the same reason it is everywhere else, and it matters
     * here in a specific way: an order that conversion has just made a Draft holds nothing, so this
     * reports the true shortfall. Were it already approved, its own units would already be
     * subtracted and every line would look short by its own size.
     *
     * @return list<array{product: ProductCore, warehouse: Warehouse, regionName: string, requested: string, available: string, missing: string}>
     */
    public function shortfallsForOrder(SalesOrder $order, EntityManagerInterface $entityManager): array
    {
        $warehousesByLowerRegionName = $this->warehouses->warehousesByLowerRegionName();
        $regionNamesByWarehouseId = $this->warehouses->regionNamesByWarehouseId();

        $requested = [];
        foreach ($order->getLines() as $line) {
            $product = $line->getProduct();
            if (!$product instanceof ProductCore) {
                continue;
            }

            $warehouse = OrderInventoryBucketResolver::resolveLineWarehouse(
                $line->getLocation(),
                $order->getFulfillmentRegion(),
                $warehousesByLowerRegionName,
            );
            if (!$warehouse instanceof Warehouse) {
                continue;
            }

            $regionName = $regionNamesByWarehouseId[$warehouse->getId()] ?? $warehouse->getName();

            // The exact quantity, not `(int) round((float) …)`: a held line of 0.4 used to read as
            // a line for nothing and was skipped entirely, so nothing was ever measured for it.
            $quantity = $line->getQuantity();
            if ((float) $quantity <= 0.0) {
                continue;
            }

            // Summed per product and warehouse, for the same reason shortfallsFor() sums: two lines of
            // the same product in one warehouse draw on one pool.
            $key = $product->getId() . '|' . $warehouse->getId();
            if (!isset($requested[$key])) {
                $requested[$key] = ['product' => $product, 'warehouse' => $warehouse, 'regionName' => $regionName, 'quantity' => QuantityScale::canonical(0)];
            }
            $requested[$key]['quantity'] = QuantityScale::add($requested[$key]['quantity'], $quantity);
        }

        $ownBackorderHold = $this->backorderSplits->ownBackorderHoldFor($order, $entityManager);

        $shortfalls = [];
        foreach ($requested as $key => $row) {
            $available = $this->availableFor($row['product'], $row['warehouse'], $order, $entityManager);
            if (QuantityScale::compare($row['quantity'], $available) <= 0) {
                continue;
            }

            $inventory = $entityManager->getRepository(ProductInventory::class)->findOneBy([
                'product' => $row['product'],
                'warehouse' => $row['warehouse'],
            ]);
            $split = $this->backorderSplits->splitFor($inventory, $row['quantity'], $available, $ownBackorderHold[$key] ?? QuantityScale::canonical(0));
            if ($split->isFullyCovered()) {
                continue;
            }

            $shortfalls[] = [
                'product' => $row['product'],
                'warehouse' => $row['warehouse'],
                'regionName' => $row['regionName'],
                'requested' => $row['quantity'],
                'available' => $available,
                'missing' => QuantityScale::sub($row['quantity'], $available),
            ];
        }

        return $shortfalls;
    }

    /**
     * Availability for one product in one warehouse, with $order's own hold added back — what the
     * form shows beside a line's quantity box so an admin can see the ceiling while typing.
     *
     * Same arithmetic the refusal above uses, deliberately: a number displayed that disagreed with
     * the number enforced would be worse than showing nothing.
     */
    public function availableFor(
        ProductCore $product,
        Warehouse $warehouse,
        ?SalesOrder $order,
        EntityManagerInterface $entityManager,
    ): string {
        $inventory = $entityManager->getRepository(ProductInventory::class)->findOneBy([
            'product' => $product,
            'warehouse' => $warehouse,
        ]);

        $ownHold = $order instanceof SalesOrder ? $this->currentHoldFor($order, $entityManager) : [];
        $key = $product->getId() . '|' . $warehouse->getId();

        $available = QuantityScale::add($inventory?->getAvailableQuantity() ?? 0, $ownHold[$key] ?? 0);

        return QuantityScale::compare($available, 0) > 0 ? $available : QuantityScale::canonical(0);
    }
}
