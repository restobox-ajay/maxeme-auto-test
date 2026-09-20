<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Pick;

use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Service\Inventory\InventoryModeResolver;
use App\Service\QuantityScale;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use InventoryDepthBundle\Repository\InventoryDetailRepository;
use WarehouseOpsBundle\Entity\PickTask;

/**
 * Confirming what was actually picked (#552).
 *
 * ## A short pick is three facts, and this writes all three
 *
 * The list says 20, the picker finds 16:
 *
 *  1. **The pick moved 16** — a `pick` movement group of what physically happened.
 *  2. **Four units the system expected are not there** — a SEPARATE `adjustment` group with a count
 *     reason. Writing one movement of 20 loses the discrepancy entirely and leaves stock overstated;
 *     writing one of 16 and stopping leaves the four sitting in the system forever. Not there **at
 *     the bin the confirmation names**, and nowhere else: see countedFaces() for why the scope of a
 *     write-off is one bin and why a confirmation that can name none writes off nothing at all
 *     (#589, #591).
 *  3. **The order still needs 4** — recorded on the task as its outstanding quantity, for a top-up
 *     from another lot or a backorder (#548). Not a movement, because nothing moved.
 *
 * ## Why a confirmed pick does not change the product's total
 *
 * This is the ordering constraint the plan calls the most consequential one in the warehouse stack,
 * and it is resolved by WHERE the picked stock goes rather than by a release written into core's
 * ledger.
 *
 * Picked stock has left the shelf and not the building. It moves from its pick face to the list's
 * staging bin **with its status still `available`**, so `SUM(available)` — and therefore
 * `product_inventory.quantity` — recomputes to the identical number. The order's `sales_hold` goes
 * on holding exactly those units, unchanged, and it is still the only claim standing on them.
 *
 * The alternative — moving picked stock to a non-available status here and releasing the document's
 * hold to compensate — is not available to a bundle, and it is worth writing down why, because it is
 * the same reason this bundle ships no fulfilment deduction at all:
 *
 *  - InventoryReservationReconciler does not decrement a ledger. It recomputes each document's
 *    target from its own lines and diffs, so a release written from outside is restored on the very
 *    next flush that touches the document.
 *  - A genuine release would mean core netting a picked quantity out of
 *    SalesOrderReservationSubject::heldLines(), or dropping the invoice's `approved` hold on
 *    fulfilment. Both are changes to core's status-to-bucket table — the one documented on
 *    InvoiceInventoryBucketResolver, which says in as many words that `approved` is released by an
 *    import or recount because *this app has no mechanism that decrements stock on shipment*.
 *
 * What the plan actually requires is that the units are not subtracted twice. Keeping the pick
 * inside `available` gets that with no core change at all, and it stays true whatever core later
 * decides to do about shipment. See the bundle README for the full account of that gap.
 *
 * ## Where the pick is recorded as coming FROM (#591)
 *
 * The confirm screen now asks. Before it did, this method took the picked units off the front of
 * pickableRows() — the earliest-expiring row anywhere in the warehouse — and called that the record
 * of where the picker had been. It is not: that query answers "where SHOULD this be picked from",
 * and a confirmation has to answer "where WAS it". A picker sent to A-01-03, who found five there
 * and took them, had those five decremented off B-02-01 whenever B-02-01 held an earlier-expiring
 * lot. Both bins were then wrong in opposite directions, the warehouse total was right, every unit
 * was still `available`, and no screen contradicted any of it.
 *
 * That could not be fixed here, because nothing told this method where the picker stood. So the
 * form grew the field — `picked_from[{task}]` beside `picked[{task}]` — and the answer arrives as a
 * PickSource. When it names a bin, the pick is drawn from THAT BIN and nowhere else; the ORDER
 * within it is still pickableRows(), because which lot goes first is #550's decision and not this
 * one's.
 *
 * Scoping it to `pick_task.suggested_location_id` instead was explicitly rejected: it swaps the
 * earliest-expiring bin for the suggested one, which is a different wrong answer every time the
 * picker legitimately went somewhere else because the suggested bin was empty. Only the picker
 * knows, so only the picker is asked; the suggested bin is what the field is PRE-FILLED with, which
 * is a default they can change rather than a guess made on their behalf.
 *
 * ### What happens when they name a bin that does not hold that much
 *
 * Nothing spills onto another bin. The units the named bin has are moved, and the rest are reported
 * back as unmoved — because a count taken at one bin is evidence about one bin, and quietly sourcing
 * the difference three aisles away is the exact shape of the bug #589 fixed on the destructive side.
 * The order keeps owing them, and the honest routes to settling it are another round or the
 * Inventory Depth adjustment screen. See PickConfirmation::$unmoved.
 *
 * ## Everything below goes through StockMovementService
 *
 * There is no write to `inventory_detail` here, and no second decrement path. What this class
 * decides is *which rows and how many*; the movement service is what changes them, in the same
 * transaction as the core total, exactly as a manual adjustment on the desktop screen does.
 */
final class PickConfirmationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockMovementService $movements,
        private readonly InventoryDetailRepository $details,
        private readonly InventoryModeResolver $inventoryModes,
    ) {
    }

    /**
     * Records what the picker found, and where they found it.
     *
     * @param string|int|float $picked     quantity physically found and moved to staging
     * @param string|null     $operationId idempotency key from the device or the form; a retry applies once
     * @param PickSource|null $from        where the picker says the units came off. Null means the
     *                                     caller did not say — which is what every request written
     *                                     before the form grew the field sends — and is treated as
     *                                     silence rather than as "no bin"; see declaredSource().
     *
     * @throws PickConfirmationException when the task cannot be confirmed at all
     */
    public function confirm(PickTask $task, string|int|float $picked, ?string $operationId, ?string $actor, ?PickSource $from = null): PickConfirmation
    {
        $list = $task->getPickList();
        $product = $task->getProduct();
        $staging = $list->getStagingLocation();

        if (!$product instanceof ProductCore) {
            throw new PickConfirmationException('This task names a product that no longer exists, so there is nothing to move.');
        }
        // Through the resolver, never the raw column: with InventoryDepthBundle Inactive a
                // stored `dimensional` must read as simple everywhere (#566).
        if (!$this->inventoryModes->isDimensional($product)) {
            throw new PickConfirmationException(sprintf(
                '%s is back on simple inventory; its quantity is a number an admin types, and this layer must not touch it.',
                $product->getSku() ?: $product->getName(),
            ));
        }
        if (!$staging instanceof WarehouseLocation) {
            throw new PickConfirmationException(sprintf(
                'Pick list %s has no staging bin. Release it against one first — picked stock has to be put down somewhere that is not the shelf it came off.',
                $list->getNumber(),
            ));
        }

        // A pick is a quantity — 0.6 kg off a lot is picked, not refused — and the columns behind
        // it are decimals since `App\Doctrine\Type\QuantityType` widened, so the arithmetic that
        // allocates, sums and short-counts is done through QuantityScale rather than as floats: a
        // bin balance that drifts a ten-thousandth per line refuses a pick the shelf can cover.
        $requested = QuantityScale::canonical($task->getQuantityRequested());
        $picked = self::atLeastZero(self::minQty(QuantityScale::canonical($picked), $requested));
        $warehouse = $list->getWarehouse();

        $declared = $this->declaredSource($task, $from, $staging);

        // The pick faces, in the order #550 would draw on them — earliest expiry, then lowest bin —
        // with the staging bin taken out. Filtering that list is not a second allocation rule: the
        // ORDER still comes from InventoryDetailRepository::pickableRows(). Excluding staging is
        // what stops a short-pick write-off from taking back the units this same confirmation just
        // put there, and stops a second round drawing on the first round's staged stock.
        $faces = $this->pickFaces($product, $warehouse, $staging);

        // ...and then, if anybody said where the picker stood, only the rows AT that place (#591).
        // Same list, same order, fewer rows. When nobody said, the whole warehouse answers, exactly
        // as it did before — and the confirmation reports that it had to, rather than passing a
        // guess off as a record.
        $pickPlan = $this->allocate($declared->isNamed() ? $this->rowsAt($faces, $declared) : $faces, $picked);
        $movedGroup = null;

        if ($pickPlan !== []) {
            $request = MovementRequest::of(
                InventoryMovementGroup::TYPE_PICK,
                $operationId === null ? null : $operationId . '-pick',
                sprintf('Pick list %s', $list->getNumber()),
                $actor,
                $task->getOrderNumber(),
            );

            foreach ($pickPlan as $step) {
                $row = $step['detail'];
                $from = new DetailKey($warehouse, $row->getLocation(), $row->getLot(), $row->getSerial(), InventoryDetail::STATUS_AVAILABLE);
                // Same lot, same serial, same status — only the bin changes. Both sides are
                // `available` in the same warehouse, so syncCoreTotal() recomputes the identical
                // number. That is the property this whole method is built around.
                $to = new DetailKey($warehouse, $staging, $row->getLot(), $row->getSerial(), InventoryDetail::STATUS_AVAILABLE);

                $request->move($product, $from, $to, $step['quantity']);
            }

            $movedGroup = $this->movements->apply($request);
        }

        $moved = $this->sum($pickPlan);

        // What the picker says is not on the shelf. Only the part the SYSTEM still thinks is there
        // can be written off; the rest was never in the system, so there is nothing to adjust and
        // the order simply still needs it.
        //
        // And only the part the system thinks is there AT THE PLACE THIS CONFIRMATION NAMES — what
        // the picker said, or failing that what the printout said. See declaredSource() and
        // countedFaces(): the shortfall is evidence about one bin, and this is where that is
        // enforced (#589, #591).
        $declaredShort = self::atLeastZero(QuantityScale::sub($requested, $moved));
        $remaining = $this->pickFaces($product, $warehouse, $staging);
        $counted = $this->countedFaces($remaining, $declared);
        $writeOffPlan = $this->allocate($counted, self::minQty($declaredShort, $this->sumRows($counted)));
        $missingGroup = null;

        if ($writeOffPlan !== []) {
            $request = MovementRequest::of(
                InventoryMovementGroup::TYPE_ADJUSTMENT,
                $operationId === null ? null : $operationId . '-short',
                sprintf('Short pick on %s — counted missing at the bin', $list->getNumber()),
                $actor,
                $task->getOrderNumber(),
            );

            foreach ($writeOffPlan as $step) {
                $row = $step['detail'];
                $from = new DetailKey($warehouse, $row->getLocation(), $row->getLot(), $row->getSerial(), InventoryDetail::STATUS_AVAILABLE);

                // To `lost`, not to nowhere (#581).
                //
                // This used to be remove() — a movement with no destination — which is arithmetically
                // correct and accounting-blind. Stock that leaves with no destination is recorded by
                // nothing, so `received` absorbs it, and availability drops by the right amount. But
                // the units are then indistinguishable from a receipt that never happened: shrinkage
                // found at the bin lands in the bucket named "stock arrived" and never reaches
                // `write_off`, which is where the loss is supposed to be visible.
                //
                // Every other way of losing stock — damage, expiry, scrapping, losing it on an
                // adjustment — ends in `write_off`. A short pick is the same event: it was on the
                // shelf and it is not any more. `lost` is the honest status for it, because nobody
                // has said what became of it.
                //
                // forStatus() drops the bin, since `lost` is terminal: naming the bin it was not in
                // would be a claim about where it is, and the whole point is that nobody knows.
                $request->move($product, $from, $from->forStatus(InventoryDetail::STATUS_LOST), $step['quantity']);
            }

            $missingGroup = $this->movements->apply($request);
        }

        $missing = $this->sum($writeOffPlan);

        // A shortfall nobody can pin on a bin. Not written off — reported, so the discrepancy is
        // visible as work owed rather than being silently either destroyed or forgotten. This is
        // exactly the quantity the pre-#589 code wrote off, which is why it is worth a number of
        // its own: what used to disappear is now what gets said out loud.
        $unattributed = $declared->isNamed()
            ? QuantityScale::canonical(0)
            : self::minQty($declaredShort, $this->sumRows($remaining));

        // Units the picker says they carried away that the named place could not supply. Nothing is
        // written for them — there is nothing to write, since the system never had them there — but
        // they are said out loud, because the alternative is taking them off some other bin on the
        // strength of a count that was not taken there. See PickConfirmation::$unmoved.
        $unmoved = self::atLeastZero(QuantityScale::sub($picked, $moved));

        $task->setQuantityPicked($moved)
            ->setQuantityMissing($missing);
        $this->em->flush();

        return new PickConfirmation(
            $task,
            $moved,
            $missing,
            $task->outstanding(),
            $movedGroup,
            $missingGroup,
            $unattributed,
            $unmoved,
            $declared,
        );
    }

    /**
     * Which place this confirmation is about: what the picker said, or failing that what the
     * printout said, or failing that nothing at all.
     *
     * The fall-through order is the whole of #591's answer, so each step is worth stating:
     *
     *  1. **The picker named it.** They walked there. Nothing in the system knows better, and it is
     *     used for both the pick and the write-off.
     *  2. **Nobody named it, but the task carries a suggested bin.** The printout said go to that
     *     shelf and the confirmation came back without contradicting it — the picker agreeing by
     *     silence. This is also what keeps every request written before the form grew the field
     *     behaving exactly as #589 left it.
     *  3. **Neither.** `pick_task.suggested_location_id` is NULL, which is the compiler saying it
     *     could not name a bin, and the request said nothing either. The pick is then drawn from the
     *     whole warehouse in pickableRows() order — the pre-#591 behaviour, and a guess — while
     *     nothing at all is written off, which is #589 and is not negotiable. The guess is confined
     *     to a movement that leaves the product total alone; the destruction is not allowed to
     *     happen at all, and the shortfall is reported as unattributed so somebody goes and counts.
     *
     * The staging bin is refused outright at step 1. Its rows are excluded from the pick faces
     * anyway, so naming it could only ever move nothing — and a picker who typed 12 and watched
     * nothing happen deserves a sentence rather than a silence.
     *
     * @throws PickConfirmationException when the named place cannot be a source at all
     */
    private function declaredSource(PickTask $task, ?PickSource $from, WarehouseLocation $staging): PickSource
    {
        $from ??= PickSource::unspecified();

        if ($from->isNamed()) {
            if ($from->bin instanceof WarehouseLocation && $from->bin->getId() === $staging->getId()) {
                throw new PickConfirmationException(sprintf(
                    '%s is the staging bin for this round, so it is where picked stock is going, not where it came from. Name the shelf the units were taken off.',
                    $staging->getCode(),
                ));
            }

            return $from;
        }

        $suggested = $task->getSuggestedLocation();

        return $suggested instanceof WarehouseLocation ? PickSource::bin($suggested) : PickSource::unspecified();
    }

    /**
     * The rows of an already-ordered list that sit where the picker says they were standing.
     *
     * @param list<InventoryDetail> $faces
     *
     * @return list<InventoryDetail>
     */
    private function rowsAt(array $faces, PickSource $source): array
    {
        $rows = [];

        foreach ($faces as $row) {
            if ($source->holds($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * The rows this product can be picked from, excluding the staging bin.
     *
     * @return list<InventoryDetail>
     */
    private function pickFaces(ProductCore $product, Warehouse $warehouse, WarehouseLocation $staging): array
    {
        $rows = [];

        foreach ($this->details->pickableRows($product, $warehouse) as $row) {
            if ($row->getLocation()?->getId() === $staging->getId()) {
                continue;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The rows a short pick is allowed to be written off from: the bin the picker was standing at.
     *
     * ## Why this exists at all (#589)
     *
     * pickFaces() is the auto-source population. It answers "where could this product be picked
     * FROM", which is the right question for choosing a pick face and the wrong question entirely
     * for "what is the picker telling us is not there". Handing the whole warehouse to the
     * write-off allocator meant a picker asked for 20, finding the 5 their bin actually held and
     * typing 5 — the ordinary thing to do — wrote off the 15 sitting undisturbed three aisles away,
     * to `lost`, with the bin dropped. The success flash then advised topping the order up "from
     * another lot": the stock it had just destroyed.
     *
     * It did not even need a picker reporting a shortage. The compiler blocks a LINE that is
     * already on an open list, but two orders for the same SKU are two lines, so two pickers can be
     * routed to the same bin. The second finds it empty, types 0, and the product's entire
     * remaining stock in the warehouse is written off.
     *
     * A typed count is evidence about one place — the bin the picker walked to. Since #591 that
     * place is what the confirm form asked them for, falling back to `pick_task.suggested_location_id`
     * when nobody said; either way it arrives here as an already-resolved PickSource, and the
     * write-off is capped at what that place still holds.
     *
     * Taking the picker's answer over the printout's makes this strictly tighter, not looser. A
     * picker who went to B because A was empty used to have their count of B charged against A —
     * destroying stock at a bin they never visited, on evidence about a different one. Now the count
     * lands where it was taken.
     *
     * ## Why a null bin writes off NOTHING rather than falling back
     *
     * The tempting fallback — no bin, so use the warehouse — reinstates the bug for precisely the
     * tasks where the system is least sure of itself. `suggested_location_id` is null when the
     * compiler could not name a bin: no pickable stock at all, or stock sitting in no bin. If the
     * system cannot say which bin the picker was standing at, it has no basis on which to destroy
     * anything anywhere.
     *
     * The asymmetry is the argument. Leaving an order short is recoverable — the shortfall stays on
     * the task as outstanding, and it is topped up or backordered (#548). Writing off stock in
     * another aisle is not: it lands in `lost` with the bin dropped, and nothing records where it
     * came from. Between a recoverable wrong number and an unrecoverable one, this takes the
     * recoverable one every time.
     *
     * ## Why this is not gated behind an "I counted this bin" affirmation
     *
     * Both were on the table for #589, and scoping alone is what shipped. Three reasons:
     *
     *  1. An affirmation cannot be added without picking a default, and every default is wrong. Off
     *     by default silently stops every printout, device and existing POST from recording
     *     shrinkage at all — trading an over-broad write-off for a silent under-recording, which is
     *     the failure #552 built the second movement group to prevent. On by default is decorative.
     *  2. Once the scope is the bin, the picker has already made the affirmation. Confirming a task
     *     for 5 against a bin IS the statement "I went to that bin and there were 5". Asking again
     *     on the same form is asking the same question twice and treating the second answer as more
     *     true than the first.
     *  3. The residual risk — a picker typing 5 because 5 is all they wanted to carry, not because
     *     5 is all there was — is real, and it is a question about what the confirm form asks, not
     *     about what this service is allowed to destroy. That belongs with the bin/lot/serial fields
     *     in #590, where the form learns to talk about bins at all. Scoping is correct on its own
     *     and does not have to wait for it.
     *
     * @param list<InventoryDetail> $faces
     *
     * @return list<InventoryDetail>
     */
    private function countedFaces(array $faces, PickSource $counted): array
    {
        if (!$counted->isNamed()) {
            return [];
        }

        return $this->rowsAt($faces, $counted);
    }

    /**
     * Takes $quantity off the front of an already-ordered list of rows.
     *
     * Deliberately not a choice: the ORDER arrives from #550's pickableRows() and this only walks
     * it. Anything cleverer here would be a second allocation policy competing with the one that
     * actually moves the stock.
     *
     * @param list<InventoryDetail> $rows
     *
     * @return list<array{detail: InventoryDetail, quantity: string}>
     */
    private function allocate(array $rows, string $quantity): array
    {
        $plan = [];
        $outstanding = self::atLeastZero($quantity);

        foreach ($rows as $row) {
            if (QuantityScale::compare($outstanding, 0) <= 0) {
                break;
            }

            $take = self::minQty($outstanding, QuantityScale::canonical($row->getQuantity()));
            if (QuantityScale::compare($take, 0) <= 0) {
                continue;
            }

            $plan[] = ['detail' => $row, 'quantity' => $take];
            $outstanding = QuantityScale::sub($outstanding, $take);
        }

        return $plan;
    }

    /** @param list<array{detail: InventoryDetail, quantity: string}> $plan */
    private function sum(array $plan): string
    {
        $total = QuantityScale::canonical(0);
        foreach (array_column($plan, 'quantity') as $quantity) {
            $total = QuantityScale::add($total, $quantity);
        }

        return $total;
    }

    /** @param list<InventoryDetail> $rows */
    private function sumRows(array $rows): string
    {
        $total = QuantityScale::canonical(0);
        foreach ($rows as $row) {
            $total = QuantityScale::add($total, $row->getQuantity());
        }

        return $total;
    }

    private static function atLeastZero(string|int|float $quantity): string
    {
        $quantity = QuantityScale::canonical($quantity);

        return QuantityScale::compare($quantity, 0) > 0 ? $quantity : QuantityScale::canonical(0);
    }

    private static function minQty(string $a, string $b): string
    {
        return QuantityScale::compare($a, $b) <= 0 ? $a : $b;
    }
}
