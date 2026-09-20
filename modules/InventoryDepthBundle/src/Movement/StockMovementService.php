<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Movement;

use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Service\DisplayNumber;
use App\Service\Inventory\InventoryOperation;
use App\Service\Inventory\InventoryOperationContext;
use App\Service\QuantityScale;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovement;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Repository\InventoryDetailRepository;
use InventoryDepthBundle\Repository\InventoryMovementGroupRepository;

/**
 * The only thing that writes an inventory detail row, and the only thing in this bundle that writes
 * `product_inventory.received_quantity` (#550, #564, #572).
 *
 * ## What it guarantees
 *
 *     product_inventory(product, warehouse).quantity + .received_quantity
 *       == SUM(inventory_detail.quantity) WHERE status = 'available' AND warehouse = that warehouse
 *
 * after every operation, because the detail rows and the core row are written **in the same
 * transaction as the movement that changed them**. That is only possible because it is one
 * database, and it is the whole reason this is safe.
 *
 * `quantity` is the client's own figure and only an import writes it (#564). `received` is this
 * app's accumulated delta since that figure, and this service is what accumulates it — one
 * movement's worth at a time, from the movement's own lines. It is emphatically NOT re-derived as
 * `SUM(available detail) − quantity`: that made the identity above true by construction, so it
 * could never be checked, and made the bucket a sink for every change to the detail rows this app
 * did not make (#572).
 *
 * ## Why the total is maintained and not derived
 *
 * Core reads `product_inventory`. It never reads `inventory_detail`, and nothing here ever asks it
 * to. The number is always sitting in `product_inventory`, correct, whether or not anything is
 * looking at the breakdown behind it — which is what makes deleting this bundle lose the breakdown
 * and not the number. A product at 47 across three bins is still a product at 47; it just goes back
 * to an editable field.
 *
 * ## `quantity` stays refused for every product. Detail rows no longer are (2026-09-18)
 *
 * The acceptance criterion for #550 is that the existing number keeps meaning exactly what it means
 * today, and that still holds absolutely — but the boundary it draws is narrower than it used to be.
 * `quantity` is the client's own imported figure (#564) and this service still never writes it,
 * `simple` product or not. Whether a line gets an `InventoryDetail` row is a different question:
 * WHERE stock is sits beside identity (lot/serial/expiry), not inside it, and a `simple` product —
 * one with no lot/serial/expiry tracking — is still a real product with a real bin. So every line
 * now resolves a real detail row (below), with location set whenever the caller named one and
 * lot/serial left null when the product does not track them. `SUM(available detail) == quantity +
 * received` holds for every product on the same terms either way.
 *
 * InventoryDepthBundle\Inventory\InventoryModeSwitcher::toDimensional() has to know about this: its
 * opening balance only opens a warehouse that has NO existing available detail total yet, since a
 * `simple` product can now already have bin rows from before the switch, and seeding another balance
 * on top would double what is already there.
 *
 * ## What it deliberately does NOT do
 *
 * Nothing here hooks the order or invoice lifecycle. As of #539/#546 nothing in that lifecycle
 * decrements `product_inventory.quantity` at all — approving, invoicing and completing only move
 * amounts between the hold buckets, and Approved is released by an import/recount rather than by
 * shipment (see ProductInventory::$approvedQuantity and InvoiceInventoryBucketResolver). Adding a
 * deduction here would therefore not be "keeping the layers consistent"; it would be introducing a
 * decrement that does not exist, double-counting against a bucket that is still holding the same
 * units, and changing what the number means. The `pick` and `ship` movement types exist and work;
 * wiring them to a fulfilment event is #552's job, once there is a fulfilment event to wire them to.
 */
final class StockMovementService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InventoryDetailRepository $details,
        private readonly InventoryMovementGroupRepository $groups,
        private readonly InventoryOperationContext $operations,
    ) {
    }

    /**
     * Applies a whole request atomically and returns the group it wrote.
     *
     * Idempotent on `clientOperationId`: a resubmitted form or a retried command finds the existing
     * group and applies nothing. The lookup takes no lock, so two *simultaneous* submissions of the
     * same operation can both pass it — the second is then stopped by `uniq_movement_group_op`, as a
     * constraint violation rather than as a quiet no-op. Neither one double-applies, which is the
     * property that matters; the loser just learns about it the noisy way.
     *
     * @throws InsufficientStockException when a source row no longer holds what was asked for
     */
    public function apply(MovementRequest $request): InventoryMovementGroup
    {
        if ($request->isEmpty()) {
            throw new \InvalidArgumentException('A movement group with no movements records nothing.');
        }

        if ($request->type === InventoryMovementGroup::TYPE_SHIP && ($request->reference === null || trim($request->reference) === '')) {
            // A terminal `sold` row is shared by every shipment of that lot from that warehouse, so
            // the row can only ever answer "how much left". The reference is the only thing that
            // answers "to whom", which is what makes a recall a ledger query rather than an
            // unanswerable one.
            throw new \InvalidArgumentException('A ship movement must carry a reference — it is the only record of who received the stock.');
        }

        $this->assertSerialRowsStayAtOne($request);
        $this->assertSourcesCanCover($request);

        // The transaction already made this one operation; #582 makes that operation AMBIENT rather
        // than local, so `inventory_bucket_change_log` can record which physical movement moved a
        // bucket without this method — or anything below it — knowing that table exists.
        //
        // The action is derived from the request type, so a receipt and a transfer are already
        // distinguishable in the log before anyone joins to the group. The actor comes off the
        // request rather than off the security token: a movement carries who performed it, and that
        // is a better answer than "whoever's session posted the form" for a scan gun or a command.
        return $this->operations->run(
            'movement_' . $request->type,
            fn (InventoryOperation $operation): InventoryMovementGroup => $this->em->wrapInTransaction(
                fn (): InventoryMovementGroup => $this->applyWithinOperation($request, $operation),
            ),
            $request->actor,
        );
    }

    /**
     * The body of apply(), inside both the transaction and the ambient operation.
     *
     * @throws InsufficientStockException when a source row no longer holds what was asked for
     */
    private function applyWithinOperation(MovementRequest $request, InventoryOperation $operation): InventoryMovementGroup
    {
        $existing = $this->groups->findOneByClientOperationId($request->clientOperationId);
        if ($existing instanceof InventoryMovementGroup) {
            return $existing;
        }

        $group = (new InventoryMovementGroup())
            ->setClientOperationId($request->clientOperationId)
            ->setType($request->type)
            ->setReason($request->reason)
            // The stored reason row beside the free-text note, null for everything that is not an
            // adjustment (#585).
            ->setAdjustmentReason($request->adjustmentReason)
            ->setActor($request->actor)
            ->setReference($request->reference)
            ->setOccurredAt($request->occurredAt);

        $this->em->persist($group);

        // Attached before a single bucket column moves. `getId()` is still null here and that
        // is fine — the operation holds the group, not its id, and the id is read at flush
        // time by App\EventSubscriber\InventoryBucketChangeLogger. By then the per-line flush
        // below has given the group a real one, and the syncCoreTotal() writes that follow are
        // the only thing this method does to a bucket.
        $operation->attachGroup($group);

        /** @var array<string, array{product: ProductCore, warehouse: Warehouse}> $touched */
        $touched = [];

        foreach ($request->lines() as $line) {
            $product = $line['product'];
            $quantity = $line['quantity'];

            // "Dimensional" gates lot/serial identity tracking (InventoryModeResolver requires
            // both that the product opted in AND that a provider is live — with this bundle
            // Inactive a stored `dimensional` reads as `simple` everywhere). It does NOT gate
            // whether a movement's bin is recorded: WHERE stock is is a question for every
            // product, not only ones tracking lot/serial/expiry identity. A simple product's line
            // still resolves a real InventoryDetail row below — with location set whenever the
            // caller named one, lot/serial left null — so `Where It Is` and sufficiency checks
            // work the same way for every product; only the identity dimensions stay opt-in.
            $fromRow = null;
            if ($line['from'] instanceof DetailKey) {
                $fromRow = $this->resolve($product, $line['from']);
                // Load-bearing, and not just so a row created a moment ago has an id. The
                // conditional UPDATE below reads the database directly, so anything an EARLIER
                // line in this same request delivered into this row has to be on disk first —
                // move A→B then draw on B, which assertSourcesCanCover() also allows. Inside
                // the transaction, so a failure below still rolls the whole group back.
                $this->em->flush();

                // assertSourcesCanCover() has already ruled out "there was never enough", so
                // reaching here means somebody took it in between — a genuine race, and the one
                // case where rolling back (and with it closing the EntityManager) is right.
                if (!$this->details->decrement($fromRow, $quantity)) {
                    throw InsufficientStockException::forKey($line['from'], $quantity);
                }

                $touched[$this->key($product, $line['from']->warehouse)] = ['product' => $product, 'warehouse' => $line['from']->warehouse];
            }

            $toRow = null;
            if ($line['to'] instanceof DetailKey) {
                $toRow = $this->resolve($product, $line['to']);
                $toRow->setQuantity($toRow->getQuantity() + $quantity)->touch();

                $touched[$this->key($product, $line['to']->warehouse)] = ['product' => $product, 'warehouse' => $line['to']->warehouse];
            }

            $movement = (new InventoryMovement())
                ->setProduct($product)
                ->setFromDetail($fromRow)
                ->setToDetail($toRow)
                ->setQuantity($quantity)
                // Null on every line except a reversal's (#585). Written here rather than patched on
                // afterwards so the link is committed in the SAME transaction as the quantities it
                // bounds — a reversal whose link were written separately could be committed
                // unlinked, and the next reversal of the same entry would then be told the whole
                // original was still outstanding.
                ->setReversesMovement($line['reverses']);

            $group->addMovement($movement);
            $this->em->persist($movement);

            // Settle this line before the next one resolves anything. InventoryDetailRepository
            // ::findOrCreate() upholds row uniqueness by LOOKUP, and a lookup cannot see an
            // entity that is only persisted — so without this, a request that touches the same
            // row twice (move A→B then draw on B) would build a SECOND entity for it and insert
            // a duplicate. Costs one flush per line and buys the only thing keeping that index
            // honest in a metadata-built schema, where the unique index itself does not exist.
            $this->em->flush();
        }

        // Detail rows are settled; now the core total, from the same transaction. A bin move
        // lands here with both sides `available` in the same warehouse and therefore recomputes
        // to the identical number — which is the point.
        $this->em->flush();

        foreach ($touched as $pair) {
            // Every delta comes off the REQUEST, not off the rows that were just written. See
            // MovementRequest::receivedDelta() for why that distinction is the whole fix, and
            // quarantineDelta()/writeOffDelta() for the same reasoning applied to those two buckets.
            $this->syncCoreTotal(
                $pair['product'],
                $pair['warehouse'],
                $request->receivedDelta($pair['product'], $pair['warehouse']),
                $request->quarantineDelta($pair['product'], $pair['warehouse']),
                $request->writeOffDelta($pair['product'], $pair['warehouse']),
            );
        }

        $this->em->flush();

        return $group;
    }

    /**
     * Applies one movement's delta to `product_inventory.received_quantity` and returns the detail
     * total it now stands beside. Does not flush; the caller's transaction owns that.
     *
     * `$receivedDelta` is the part of the movement that no other bucket records — see
     * MovementRequest::receivedDelta(), which is where the decision of what belongs here is made.
     * It is ADDED to whatever the bucket holds. Zero is the ordinary answer for a bin-to-bin move,
     * and now also for a write-off or a dispatch, and correctly writes nothing at all.
     *
     * ## What the resulting change log row means (#582)
     *
     * Every bucket written here produces an `inventory_bucket_change_log` row attributed to the
     * ambient operation, and that row records THE RESULTING VALUE, not this operation's increment —
     * `received`, `write_off` and `quarantine` are all now running accumulations that their own
     * delta is added to (2026-09-15: `write_off`/`quarantine` used to be recomputed to the whole
     * detail-row total instead; see the accumulation's own comment below for why that changed). So a
     * row reading `write_off: 4 → 16` after a twelve-unit scrapping is the total written off, and
     * treating the pair of numbers as the operation's own quantity will disagree with the movement
     * lines wherever anything else touched the same bucket. The movements are the record of what
     * this operation moved; the log is the record of what the bucket did.
     *
     * Neither transfer bucket appears in that list, because this layer stopped writing them (#584):
     * `transfer_out` and `transfer_in` are derived from `transfer_order_line` by
     * TransferOrderService::recomputeTransferBuckets(), and their log rows are attributed to the
     * operation THAT method runs inside.
     */
    public function syncCoreTotal(
        ProductCore $product,
        Warehouse $warehouse,
        string|int|float $receivedDelta = 0,
        string|int|float $quarantineDelta = 0,
        string|int|float $writeOffDelta = 0,
    ): string {
        $total = $this->details->availableTotal($product, $warehouse);

        $inventory = $this->em->getRepository(ProductInventory::class)->findOneBy([
            'product' => $product,
            'warehouse' => $warehouse,
        ]);

        if (!$inventory instanceof ProductInventory) {
            $inventory = (new ProductInventory())->setProduct($product)->setWarehouse($warehouse);
            $this->em->persist($inventory);
        }

        // NOT `quantity` (#564). That number is imported from the client's own inventory system and
        // this app does not own it — writing it here made the movement layer a second writer of a
        // figure the next import overwrites, and the resulting disagreement is ambiguous after the
        // fact: either their count predates our delivery, or it already included it and something
        // was booked twice. Nothing in the data distinguishes those.
        //
        // So the app's delta sits beside their number instead of inside it. The invariant is the
        // same one, rearranged:
        //
        //     SUM(available detail) == quantity + received
        //
        // Their figure is never touched; ours absorbs the difference.
        //
        // ACCUMULATED, not re-derived (#572). It used to be written as `total − quantity`, which is
        // the same number for a single receipt against a settled row and a different KIND of number:
        // a residual. A residual is defined by the invariant rather than checked against it, so the
        // check below can never fail and never told anyone anything. Worse, it made `received` the
        // sink for every change to the detail rows that this app did not make — an import writing a
        // bin, or the sentinel row being re-plugged, landed in the bucket as though a lorry had
        // turned up. Step 4 of DimensionalReceivingAndImportSequenceCest is exactly that case.
        //
        // So the movement brings its own number and the bucket adds it. The invariant
        //
        //     SUM(available detail) == quantity + received
        //
        // is then a genuine CHECK over two independently maintained figures — which is what
        // app:inventory-depth:detail-check exists to run.
        //
        // The hold buckets are untouched, as before: they are claims against this number maintained
        // by their own ledgers, and this layer has no business writing them.
        // `transfer_out` USED to be written here, as a cached sum of the in-transit rows. It is not
        // any more, and this layer no longer touches either transfer bucket (#584).
        //
        // The in-transit rows are the wrong source of truth for it. They are consumed by the
        // receipt, so the bucket fell back to zero the moment the goods landed and the source
        // warehouse sprang back to full availability for stock that is now in another building. The
        // permanent loss had to be pushed into `received` to keep the arithmetic straight, which
        // put a WarehouseOpsBundle fact into a bucket ProcurementBundle's flag gates — turn
        // procurement off and the source over-reported by the size of every transfer it had ever
        // sent.
        //
        // So `transfer_out` and `transfer_in` are now cumulative and derived from
        // `transfer_order_line` by TransferOrderService, the way `sales_hold` is derived from orders
        // and `pending` from invoices: the bundle that owns the document owns the bucket. Nothing
        // here writes them, and a movement that happens to cross `in_transit` must not, or it would
        // fight the document.
        //
        // `write_off` and `quarantine` were recomputed HERE, wholesale, from
        // `SUM(inventory_detail)`, until the simple-inventory bucket parity plan
        // (2026-09-15, `docs/plans/2026-09-15-simple-inventory-bucket-parity.md`). That made both
        // buckets a pure derivation of the detail rows — correct arithmetic, but not an independent
        // figure, which is exactly why neither could exist for a product with no detail rows to
        // derive from, and why there was nothing for a dimensional product's detail rows to
        // RECONCILE against either: recomputing a number from its own definition can never disagree
        // with itself. Both are now accumulated exactly like `received` below — from the request's
        // own crossings (`MovementRequest::quarantineDelta()`/`writeOffDelta()`), which read only the
        // `from`/`to` statuses on the lines themselves and need no detail row to answer from. A
        // line — simple or dimensional — still gets its detail row written above, in the same
        // transaction as this credit, so the two cannot disagree the moment they're written — only
        // drift apart later, which is what `app:inventory-depth:detail-check`'s extended
        // reconciliation exists to catch.
        //
        // `write_off` — damaged, expired, scrapped, lost, returned to vendor. Gone or unsellable for
        //               good.
        // `quarantine` — quarantined and returned. Present, unsellable until somebody rules on it,
        //                and may come back.
        //
        // `staged` and `sold` are in NEITHER, deliberately. The invoice that bills those units is
        // already holding them in `pending`/`approved`, and those holds never release — this app
        // has no mechanism that decrements Starting Inventory on shipment. Counting them here as
        // well would subtract the same units twice.
        // Compared against zero as a NUMBER rather than with `!== 0`: the deltas are decimal
        // strings now, and `'0.0000' !== 0` is true, which would touch every row on every movement.
        if ((float) $writeOffDelta !== 0.0) {
            $inventory->setWriteOffQuantity((float) $inventory->getWriteOffQuantity() + (float) $writeOffDelta)->touch();
        }
        if ((float) $quarantineDelta !== 0.0) {
            $inventory->setQuarantineQuantity((float) $inventory->getQuarantineQuantity() + (float) $quarantineDelta)->touch();
        }

        // `received` now means what it says: stock ARRIVED (or left outright) and the external
        // system does not know it.
        //
        // It used to take every non-zero change to the available pool, so damaging a pallet, picking
        // an order or dispatching a transfer wrote it too. That kept availability right and the
        // meaning wrong — and `clear_received_balance` cleared a mixture of arrivals and write-offs
        // while an admin believed they were baselining deliveries (#581).
        //
        // Those departures are carried by the buckets above, which is why they can come out of here
        // now and could not before. Which crossings still belong here is decided in
        // MovementRequest::receivedDelta(); by the time it reaches this method the answer is already
        // a number, and a plain withdrawal — stock leaving the ledger with no destination and so no
        // bucket — is still in it.
        if ((float) $receivedDelta !== 0.0) {
            $inventory->setReceivedQuantity((float) $inventory->getReceivedQuantity() + (float) $receivedDelta)->touch();
        }

        return $total;
    }

    /**
     * A serial names one physical unit, so no destination row carrying one may end up above 1
     * (#573) — refused **before** the transaction opens, for the same reason
     * assertSourcesCanCover() is: a `wrapInTransaction()` failure closes the EntityManager, which is
     * an expensive way to say "enter one line per unit".
     *
     * InventoryDetail::setQuantity() is the guard that cannot be bypassed; this is the one that
     * produces a sentence somebody can act on. Both exist on purpose and in that order.
     *
     * Simulated in request order like the source check, so two lines each delivering one unit under
     * the same serial are refused as the contradiction they are rather than passing individually.
     *
     * **A sentinel serial is skipped**, exactly as InventoryDetail::setQuantity() skips it. The
     * policy's `sentinel_in` is a label on the unidentified row, not a serial number: it identifies
     * no physical unit, so N unidentified units are one row of N wearing the label. The rule this
     * enforces is about genuine serial numbers, each of which identifies exactly one unit.
     */
    private function assertSerialRowsStayAtOne(MovementRequest $request): void
    {
        /** @var array<string, int> $balance */
        $balance = [];

        foreach ($request->lines() as $line) {
            foreach ([['from', -1], ['to', 1]] as [$side, $sign]) {
                $key = $line[$side];
                if (!$key instanceof DetailKey || $key->serial === null) {
                    continue;
                }

                if ($line['product']->getTrackingPolicy()?->isSentinelIn($key->serial) === true) {
                    continue;
                }

                $key = $key->normalized();
                $signature = implode('|', [
                    $line['product']->getId() ?? 0,
                    $key->warehouse->getId() ?? 0,
                    $key->location?->getId() ?? 0,
                    $key->lot?->getId() ?? 0,
                    $key->serial,
                    $key->status,
                    $key->lot === null ? $key->expiry?->format('Y-m-d') : null,
                ]);

                $balance[$signature] ??= $this->details->findExisting(
                    $line['product'],
                    $key->warehouse,
                    $key->location,
                    $key->lot,
                    $key->serial,
                    $key->status,
                    $key->expiry,
                )?->getQuantity() ?? '0';

                // Accumulated via bcmath rather than as floats, so a ledger balance cannot drift a
                // ten-thousandth per line and refuse a movement that the stock actually covers.
                $balance[$signature] = $sign > 0
                    ? QuantityScale::add($balance[$signature], $line['quantity'])
                    : QuantityScale::sub($balance[$signature], $line['quantity']);

                if (QuantityScale::compare($balance[$signature], 1) > 0) {
                    throw new \InvalidArgumentException(sprintf(
                        'Serial %s would end up holding %s units at %s. A serial identifies one unit, so each unit is its own row of 1.',
                        $key->serial,
                        (new DisplayNumber())->qty($balance[$signature]),
                        $key->describe(),
                    ));
                }
            }
        }
    }

    /**
     * Replays the request against the quantities currently on disk and refuses the whole thing if
     * any source runs dry — **before** the transaction opens.
     *
     * The check exists twice on purpose, and the two halves are not redundant:
     *
     *  - this one is a pure read, so the ordinary "there isn't that much" answer costs nothing and,
     *    critically, does not roll anything back. `EntityManager::wrapInTransaction()` closes the
     *    EntityManager on any exception, so a failure raised INSIDE it leaves the rest of the
     *    request unable to touch the database — an expensive way to say "you asked for 9 and there
     *    are 5";
     *  - the conditional UPDATE inside the transaction is the real concurrency guard, and stays,
     *    because between this read and that write somebody else can still take the stock. That case
     *    genuinely is a failed write, and rolling it back is correct.
     *
     * It is a running simulation rather than a per-row sum, because within one request a line can
     * legitimately draw on stock an earlier line put there — move A→B, then B→C. Summing the demands
     * per row would refuse that, and totalling them per row without crediting the deliveries would
     * refuse it wrongly. Two lines drawing 15 each from a row of 20 is still correctly refused,
     * because the simulation runs them in order and the second finds 5 left.
     */
    private function assertSourcesCanCover(MovementRequest $request): void
    {
        /** @var array<string, int> $balance quantity on hand per detail key, as the request proceeds */
        $balance = [];
        /** @var array<string, DetailKey> $keys */
        $keys = [];

        $seed = function (ProductCore $product, DetailKey $key) use (&$balance, &$keys): string {
            $signature = implode('|', [
                $product->getId() ?? 0,
                $key->warehouse->getId() ?? 0,
                $key->location?->getId() ?? 0,
                $key->lot?->getId() ?? 0,
                $key->serial ?? '',
                $key->status,
                $key->lot === null ? ($key->expiry?->format('Y-m-d') ?? '') : '',
            ]);

            if (!\array_key_exists($signature, $balance)) {
                $row = $this->details->findExisting(
                    $product,
                    $key->warehouse,
                    $key->location,
                    $key->lot,
                    $key->serial,
                    $key->status,
                    $key->expiry,
                );

                $balance[$signature] = $row?->getQuantity() ?? 0;
                $keys[$signature] = $key;
            }

            return $signature;
        };

        foreach ($request->lines() as $line) {
            // Every product now resolves a real InventoryDetail row (see applyWithinOperation()),
            // so every product's sufficiency is checked against it the same way — a simple
            // product's bin is just as real a constraint on what can leave it as a dimensional
            // one's.
            $from = $line['from'];
            if ($from instanceof DetailKey) {
                $signature = $seed($line['product'], $from->normalized());

                if (QuantityScale::compare($balance[$signature], $line['quantity']) < 0) {
                    throw new InsufficientStockException(sprintf(
                        '%s holds %s unit(s); this movement asks for %s.',
                        $keys[$signature]->describe(),
                        (new DisplayNumber())->qty($balance[$signature]),
                        (new DisplayNumber())->qty($line['quantity']),
                    ));
                }

                $balance[$signature] = QuantityScale::sub($balance[$signature], $line['quantity']);
            }

            $to = $line['to'];
            if ($to instanceof DetailKey) {
                // Credited so a later line may draw on it — the delivery genuinely will have
                // happened by then, inside the same transaction.
                $toSignature = $seed($line['product'], $to->normalized());
                $balance[$toSignature] = QuantityScale::add($balance[$toSignature], $line['quantity']);
            }
        }
    }

    private function resolve(ProductCore $product, DetailKey $key): InventoryDetail
    {
        // A terminal status drops its bin on the way in, whatever the caller named — see
        // DetailKey::normalized() and rule 3 on InventoryDetail.
        $key = $key->normalized();

        $row = $this->details->findOrCreate(
            $product,
            $key->warehouse,
            $key->location,
            $key->lot,
            $key->serial,
            $key->status,
            $key->expiry,
        );

        // Only ever upwards (#573). A caller that knows it is writing a placeholder identity says
        // so; one that does not know is not entitled to declare somebody else's to-do finished, and
        // the worklist is where a row's flag gets cleared.
        if ($key->expectResolution) {
            $row->setExpectResolution(true);
        }

        return $row;
    }

    private function key(ProductCore $product, Warehouse $warehouse): string
    {
        return sprintf('%d|%d', $product->getId() ?? 0, $warehouse->getId() ?? 0);
    }
}
