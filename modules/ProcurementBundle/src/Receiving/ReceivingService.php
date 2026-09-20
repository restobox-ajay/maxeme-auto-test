<?php

declare(strict_types=1);

namespace ProcurementBundle\Receiving;

use App\Entity\ProductCore;
use App\Repository\TrackingPolicyRepository;
use App\Service\QuantityScale;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use InventoryDepthBundle\Repository\InventoryMovementGroupRepository;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\GoodsReceipt;
use ProcurementBundle\Entity\GoodsReceiptLine;
use ProcurementBundle\Entity\ShortDatedReceipt;
use ProcurementBundle\Inventory\IncomingStockReconciler;
use ProcurementBundle\Numbering\PurchaseDocumentNumberGenerator;
use ProcurementBundle\Repository\ProductReceivingRuleRepository;
use ProcurementBundle\Repository\GoodsReceiptRepository;
use ProcurementBundle\Status\PurchaseOrderStatusDeriver;
use App\Service\Inventory\InventoryModeResolver;

/**
 * Goods arriving (#555). The hinge of the whole job, and the one place stock enters through
 * receiving.
 *
 * ## It does not write stock. It asks #550 to.
 *
 * This builds an `InventoryDepthBundle\Movement\MovementRequest` and hands it to
 * `StockMovementService::apply()` — the same service the adjustment screen and the cycle count
 * call — then stores the `InventoryMovementGroup` it gets back on the receipt. It never touches
 * `inventory_detail`, never touches `product_inventory.quantity`, and knows nothing about how
 * either is maintained.
 *
 * That is the plan's hardest rule and it is not tidiness. A second path into `inventory_detail`
 * diverges on validation and on audit, and receiving is precisely where lot, expiry and serial
 * capture must be enforced identically to everywhere else. It is also what makes this bundle
 * removable: delete it and stock still enters the building through the adjustment screen, which is
 * how #550 says stock enters before receiving exists. Nothing in core reads a single row this
 * writes.
 *
 * The consequence worth stating plainly: **a receipt produces the same `inventory_detail` rows and
 * the same movement group as the equivalent manual adjustment**, because it is the same call.
 *
 * ## What it enforces before it asks
 *
 * #550 gives a product the ability to carry a lot, an expiry and a serial and — correctly, for an
 * adjustment screen — no way to say that it must. Receiving is the one and only moment those values
 * can be captured: a pallet booked in without its lot code cannot be given one later, because
 * nobody can tell afterwards which boxes on the shelf it was. So `ProductReceivingRule` says what
 * this product needs, and this refuses the delivery without it. A product with no rule row needs
 * nothing, which is exactly how receiving would behave if this bundle did not exist.
 *
 * ## Two different questions, and they do not collide (#573)
 *
 * `ProductReceivingRule` says what a receiver **must type** and refuses the delivery without it.
 * `App\Entity\TrackingPolicy` says what a unit of that product **carries**. A blank identity takes
 * the policy's `sentinel_in` — batch or serial, the same substitution in both — and the detail row
 * is flagged `expect_resolution` so it lands on the worklist. A blank sentinel simply leaves the
 * dimension NULL, which is the same row with the label unset: same quantity, same flag.
 *
 * They are not two versions of one setting. A product can be not lot-tracked at all and still have
 * a rule demanding a bin. Where both apply, the rule is consulted first and simply means the
 * sentinel branch is never reached — the code was typed, so there is nothing to stand in for.
 *
 * ## Why the four guards below never fired, and what changed (item 67)
 *
 * The guards in `assertLineIsBookable()` have always refused a lot-tracked delivery with no batch
 * code, a serialised one with no serial, an expiry-bearing batch with no date, and a bin-required
 * product put away nowhere. Every one of them is correct and every one of them was dead: they ask
 * `ProductReceivingRule`, `ruleFor()` returns an EMPTY rule for a product with no row, and nothing
 * in the application ever created a row. So a warehouse could put every product on a Serial tracking
 * policy, see the word serial on every screen that names the product, and book the goods in with the
 * box blank — which is exactly what the walkthrough did, 550 units across ten SKUs.
 *
 * `ProductReceivingRule` now DERIVES its batch, expiry and serial answers from the product's
 * tracking policy, so the guards reach the products they were written for. Nothing about their
 * wording or their order changed.
 *
 * ## The sentinel is a thing you SAY now
 *
 * #573 wrote that tracking never blocks, and its reason is sound: a warehouse mid-transition has
 * real stock and no codes for any of it. What that reasoning did not survive is that NOTHING EVER
 * ASKED — the typed form rendered a serial box it never required, and the scan console refused
 * serials outright and sent receivers to the typed form, which was false. A blank box and a
 * genuinely unidentified pallet were the same submission.
 *
 * Both screens ask now, so the two cases are distinguishable, and this refuses the blank one. The
 * mid-transition case is unchanged in every respect except that somebody says it: a line carrying
 * `unidentified` takes the sentinel and the `expect_resolution` flag exactly as before, and lands on
 * the same worklist. It is a declaration, not a gap.
 *
 * A required BIN is never escapable that way. It is a column somebody set on this product, the
 * tracking policy has no opinion about bins, and a declaration about identity says nothing about
 * where the goods were put.
 *
 * ## One check here is a WARNING and not a gate (items 68, 69)
 *
 * `MinimumShelfLife` answers two questions about a line's expiry date: is it already in the past,
 * and is it closer than the minimum shelf life. Neither REFUSES the delivery, and the reason is the
 * same one #573 gives for tracking never blocking, arrived at from the other end: the pallet is
 * physically on the dock by the time anybody reads the date, so refusing the RECORD does not refuse
 * the PALLET — it only makes real stock invisible, and invisible stock gets picked anyway. So the
 * line is refused until somebody says WHY it is being accepted, and the saying is written down as a
 * `ShortDatedReceipt` against that line, with the person, the reason, and the figures the decision
 * was measured against.
 *
 * Already-expired is a SEPARATE trigger rather than a very strict minimum: it fires whatever any
 * minimum says, including a per-product exemption of zero. An exemption is a statement about how
 * much life a buyer insists on; it is not permission to book in stock that is already dead.
 *
 * It is not a tick. A tick records that somebody clicked; the only question ever asked about a
 * short-dated delivery afterwards is on what grounds it was taken.
 *
 * ## Fractional receipts
 *
 * Ordinary now. 0.4 of a drum books in as 0.4, at whatever `App\Service\QuantityScale` says the
 * store keeps, applied once here as the figure enters. Only SERIAL is still whole: a serial
 * identifies one physical unit. A lot is the batch a quantity came from, not a thing had whole.
 *
 * ## Products on simple inventory (revised 2026-09-18)
 *
 * `StockMovementService` writes a real `InventoryDetail` row for every product now, dimensional or
 * not (beaff217) — a `simple` line just resolves to the warehouse's unspecified row (no location,
 * lot, or serial) rather than a named bin, because none of that identity means anything for it.
 * `movementApplied` says which of those two happened for THIS line: `true` for a named bin/lot/
 * serial, `false` for the shared unspecified bucket. Either way `received` is credited and a detail
 * row is written — `movementApplied = false` is not "nothing happened to stock", only "nothing
 * specific was said about where".
 */
final class ReceivingService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockMovementService $movements,
        private readonly InventoryMovementGroupRepository $movementGroups,
        private readonly GoodsReceiptRepository $receipts,
        private readonly ProductReceivingRuleRepository $rules,
        private readonly TrackingPolicyRepository $policies,
        private readonly PurchaseDocumentNumberGenerator $numbers,
        private readonly PurchaseOrderStatusDeriver $poStatus,
        private readonly InventoryModeResolver $inventoryModes,
        // Goods arriving are goods no longer expected (#583): what a receipt takes out of
        // `incoming` is exactly what it credits to the purchase order line.
        private readonly IncomingStockReconciler $incoming,
        // How much shelf life a delivery has to have left (item 68). Asked in the validation pass,
        // where it WARNS rather than refuses, and again in the write pass, where the override it
        // produced becomes a row.
        private readonly MinimumShelfLife $shelfLife,
        // The store-wide quantity scale. Receiving is where a quantity ENTERS this application, so
        // it is one of the places the configured scale is actually applied rather than merely
        // respected — what a receiver types is rounded here, once, and every column downstream
        // carries what was rounded.
        private readonly QuantityScale $quantityScale,
    ) {
    }

    /**
     * Books a whole delivery in, atomically, and returns the receipt it wrote.
     *
     * Idempotent on the request's `clientOperationId`, in two layers. The inner one is #550's: a
     * resubmitted operation finds the existing movement group and applies nothing. The outer one is
     * here, and it is the one that matters to this bundle — without it, a double-submitted form
     * would move no stock (correctly) and still write a second receipt claiming it had.
     *
     * @throws ReceivingException when the delivery cannot be booked in as described
     */
    public function receive(ReceivingRequest $request, ?string $actor = null): GoodsReceipt
    {
        if ($request->isEmpty()) {
            throw new ReceivingException('A receipt with no lines records nothing. Enter what arrived.');
        }

        $this->assertOrderAcceptsReceipts($request->purchaseOrder);

        // Every line checked before anything is written, so a delivery is either bookable whole or
        // refused whole. Validating as we go would leave half a truck in the system and the rest
        // reported as an error.
        foreach ($request->lines() as $index => $line) {
            $this->assertLineIsBookable($request, $line, $index);
        }

        // Across lines rather than within one, so it has to come after the per-line pass.
        $this->assertSerialsAreDistinct($request);

        // Outer idempotency: this operation already produced a receipt, so hand back that one.
        // Checked before the transaction because it is a pure read and the answer does not change.
        $existing = $this->existingReceiptFor($request->clientOperationId);
        if ($existing instanceof GoodsReceipt) {
            return $existing;
        }

        return $this->em->wrapInTransaction(function () use ($request, $actor): GoodsReceipt {
            $receipt = (new GoodsReceipt())
                ->setReceiptNumber($this->numbers->next($this->em, PurchaseDocumentNumberGenerator::KIND_RECEIPT))
                ->setClientOperationId($request->clientOperationId)
                ->setVendor($request->vendor)
                ->setWarehouse($request->warehouse)
                ->setPackingSlip($request->packingSlip)
                ->setReceivedAt($request->receivedAt)
                ->setReceivedBy($request->receivedBy)
                // Where the goods left from, frozen now (#606). Vendor::getShipFromAddress() falls
                // back to the default address, so a vendor with one address records that one and a
                // vendor with none records nothing rather than an empty string.
                ->setShipFromAddress($request->vendor->getShipFromAddress()?->toSnapshot())
                ->setNotes($request->notes);

            if ($request->purchaseOrder instanceof PurchaseOrder) {
                $request->purchaseOrder->addReceipt($receipt);
            }

            $this->em->persist($receipt);

            $movement = MovementRequest::of(
                InventoryMovementGroup::TYPE_RECEIPT,
                $request->clientOperationId,
                $request->purchaseOrder instanceof PurchaseOrder
                    ? sprintf('Received against %s', $request->purchaseOrder->getPoNumber())
                    : 'Received without a purchase order',
                $actor,
                // The receipt number, so #550's movement history answers "where did these units
                // come from" without anyone needing this bundle installed to read it. That is the
                // same property `reference` gives a ship group, in the other direction.
                $receipt->getReceiptNumber(),
                $request->receivedAt,
            );

            $stocked = 0;

            foreach ($request->lines() as $line) {
                $product = $line['product'];
                $lot = $this->resolveLot($request, $line);

                // What the product's policy says a unit must carry, applied to what the receiver
                // actually typed (#573). Never a refusal — see applyTrackingPolicy().
                $policy = $this->policies->policyFor($product);
                $expectResolution = false;
                $serial = $line['serial'];

                // The same substitution in both dimensions: `sentinel_in` is a label on the
                // unidentified row and means the same thing whichever dimension wears it. A blank
                // sentinel leaves the dimension NULL, which is the same row with the label unset.
                if ($lot === null && $policy->tracksLotsInbound()) {
                    $expectResolution = true;
                    $lot = $this->resolveLot($request, array_merge($line, ['lotCode' => $policy->getSentinelIn()]));
                }
                if ($serial === null && $policy->tracksSerialsInbound()) {
                    $expectResolution = true;
                    $serial = $policy->getSentinelIn();
                }

                $receiptLine = (new GoodsReceiptLine())
                    ->setPurchaseOrderLine($line['purchaseOrderLine'])
                    ->setProduct($product)
                    ->setName($this->productName($product, $line['purchaseOrderLine']))
                    ->setSku($product->getSku())
                    ->setLot($lot)
                    ->setSerial($serial)
                    ->setLocation($line['location'])
                    // Rounded ONCE, here, to the store's configured scale — this is where a
                    // receiver's typed figure enters the application, so it is where the setting is
                    // applied. Everything below re-uses the same string: the movement, the credit on
                    // the purchase order line and the receipt row cannot then disagree about what
                    // arrived.
                    ->setQuantity($this->quantityScale->round($line['quantity']))
                    ->setUnitCost($line['unitCost']);

                // Every line is handed to the movement regardless of inventory mode, so
                // StockMovementService::apply() credits `received` and writes a detail row for all
                // of them — a `simple` line's key just carries no bin/lot/serial, since none of that
                // identity means anything for it, so it resolves to the warehouse's unspecified row.
                $reachesStock = $this->reachesStock($product);
                $movement->receive(
                    $product,
                    $reachesStock
                        ? new DetailKey(
                            $request->warehouse,
                            $line['location'],
                            $lot,
                            $serial,
                            InventoryDetail::STATUS_AVAILABLE,
                            $expectResolution,
                            // Only when there is no lot: a lot's own expiry already carries this
                            // line's date (see resolveLot()), and a lot row's d.expiry must stay
                            // NULL — InventoryDetail::setExpiry() refuses the alternative.
                            $lot === null ? $line['expiry'] : null,
                        )
                        : new DetailKey($request->warehouse),
                    // The quantity, whole. It used to be `intdiv(qtyUnits(…), 100)` — the movement
                    // layer took an `int` of physical units, so 2.50 arriving credited the purchase
                    // order line 2.50 and put 2 on the shelf, silently, and 0.4 put nothing there at
                    // all. `MovementRequest` takes a decimal quantity now.
                    $receiptLine->getQuantity(),
                );
                if ($reachesStock) {
                    $receiptLine->setMovementApplied(true);
                    ++$stocked;
                }

                $receipt->addLine($receiptLine);
                $this->em->persist($receiptLine);

                // The override, if this line was one (item 68). Measured again here rather than
                // carried over from the validation pass: the figures written are the figures the
                // decision is being recorded against, arrived at by the same call that produced the
                // warning, so the row and the message a receiver saw cannot report different
                // numbers. It is snapshotted rather than pointing at the settings, because the
                // minimum in force will move and this row has to go on saying what was accepted.
                $this->recordShortDated($receiptLine, $line, $request, $actor);

                $this->creditPurchaseOrderLine($line['purchaseOrderLine'], $receiptLine->getQuantity());
            }

            // The receipt's own rows go to disk before the movements rather than after, so a
            // movement failure rolls the whole delivery back instead of leaving a receipt claiming
            // goods that never reached stock. (The lots already have ids — resolveLot() settles
            // each one as it makes it, for its own reason.)
            $this->em->flush();

            // Applied whenever the movement has any lines at all, not just when one got a named bin
            // — a receipt of only `simple` products still needs StockMovementService::apply() to run
            // so `received` gets credited and the unspecified row gets written for them. $stocked
            // keeps its narrower meaning of "how many lines got a NAMED bin/lot/serial", used only
            // above.
            if (!$movement->isEmpty()) {
                $receipt->setMovementGroup($this->movements->apply($movement));
            }

            if ($request->purchaseOrder instanceof PurchaseOrder) {
                $this->recordOnPurchaseOrder($request->purchaseOrder, $receipt);
                $this->poStatus->recalculate($request->purchaseOrder);
                // After creditPurchaseOrderLine() has run for every line and after the status has
                // been rederived, so the forecast is recomputed from what the order NOW says is
                // still outstanding. A receipt with no purchase order behind it has nothing to take
                // out of `incoming` — nobody ever said those goods were coming.
                //
                // It opens its own named operation, the way TransferOrderService does around
                // recomputeTransferBuckets(): this runs after StockMovementService::apply() has
                // returned, so the operation that named the movements has already closed and the
                // `incoming` log rows would otherwise read 'unattributed' (#582).
                $this->incoming->reconcileForOrder($request->purchaseOrder);
            }

            $this->em->flush();

            return $receipt;
        });
    }

    /**
     * Writes the override row for a line that had a shelf-life finding and a reason.
     *
     * Silent for every ordinary line, which is almost all of them. `assertLineIsBookable()` has
     * already refused the case where there is a finding and NO reason, so by the time this runs the
     * two can only be both present or the finding absent — a reason typed on a line that turned out
     * to be fine records nothing, which is right: there was no decision to record.
     *
     * One row whichever trigger fired, and whichever figures it carries are the figures the refusal
     * quoted: `remaining_days` below 0 is the row saying the goods arrived expired, and a
     * `minimum_days` above `remaining_days` is it saying they were short. Both at once is one row
     * saying both, because it was one decision.
     *
     * @param array{product: ProductCore, quantity: string, purchaseOrderLine: ?PurchaseOrderLine, lotCode: ?string, expiry: ?\DateTimeImmutable, serial: ?string, location: ?WarehouseLocation, unitCost: ?string, unidentified: bool, shortDatedReason: ?string, expiryUnidentified: bool} $line
     */
    private function recordShortDated(GoodsReceiptLine $receiptLine, array $line, ReceivingRequest $request, ?string $actor): void
    {
        $reason = $line['shortDatedReason'] ?? null;
        if ($reason === null) {
            return;
        }

        $finding = $this->shelfLife->findingFor($line['product'], $line['expiry'], $request->receivedAt);
        if ($finding === null) {
            return;
        }

        $exception = (new ShortDatedReceipt())
            ->setReceiptLine($receiptLine)
            ->setExpiry($finding->expiry)
            ->setMinimumDays($finding->minimumDays)
            ->setRemainingDays($finding->remainingDays)
            ->setMinimumSource($finding->source)
            ->setReason($reason)
            // Whoever is logged in, falling back to the name typed into "Received by" — the same
            // two sources, in the same order, that the receipt's own actor comes from.
            ->setOverriddenBy($actor ?? $request->receivedBy)
            ->setOverriddenAt($request->receivedAt);

        $receiptLine->setShortDated($exception);
        $this->em->persist($exception);
    }

    /**
     * The lot these units belong to: an existing row when one matches, a new one when none does.
     *
     * **Matching is on code AND expiry**, which is the case the plan calls out and the case
     * `InventoryLot` was designed around: vendors reuse batch codes across production runs with
     * different dates, and collapsing two genuinely different batches into one row loses the
     * earlier one's expiry. A lot's identity is its row id, not its code — which is why
     * `inventory_lot` has an index on `(product, code)` and deliberately not a unique one.
     *
     * A lot code with no expiry given matches an existing lot with no expiry, and only that. Two
     * rows with the same code where one has a date and the other does not are two batches, and
     * treating them as one would put undated stock into a dated recall or keep dated stock out of
     * one.
     *
     * @param array{product: ProductCore, quantity: string, purchaseOrderLine: ?PurchaseOrderLine, lotCode: ?string, expiry: ?\DateTimeImmutable, serial: ?string, location: ?WarehouseLocation, unitCost: ?string, unidentified: bool, shortDatedReason: ?string, expiryUnidentified: bool} $line
     */
    private function resolveLot(ReceivingRequest $request, array $line): ?InventoryLot
    {
        if ($line['lotCode'] === null) {
            return null;
        }

        $expiry = $line['expiry']?->format('Y-m-d');

        $qb = $this->em->getRepository(InventoryLot::class)->createQueryBuilder('l')
            ->andWhere('l.product = :product')->setParameter('product', $line['product'])
            ->andWhere('l.code = :code')->setParameter('code', $line['lotCode'])
            ->andWhere($expiry === null ? 'l.expiry IS NULL' : 'l.expiry = :expiry')
            ->orderBy('l.id', 'ASC')
            ->setMaxResults(1);

        if ($expiry !== null) {
            $qb->setParameter('expiry', $expiry);
        }

        $lot = $qb->getQuery()->getOneOrNullResult();
        if ($lot instanceof InventoryLot) {
            return $lot;
        }

        $lot = (new InventoryLot())
            ->setProduct($line['product'])
            ->setCode($line['lotCode'])
            ->setExpiry($line['expiry'])
            ->setReceivedAt($request->receivedAt)
            // #550 left this free text "until #555 gives receiving a real vendor to point at". It
            // stays free text: the vendor's NAME at the moment the batch arrived is the useful
            // answer for a recall, and a live join would rewrite it if the vendor were ever renamed.
            ->setSource($request->vendor->getName());

        $this->em->persist($lot);

        // Settled before the next line resolves anything, and load-bearing for the same reason
        // StockMovementService flushes per line: the lookup above reads the database, and a lookup
        // cannot see an entity that is only persisted. Without this, two lines of one delivery
        // naming the same new batch — a pallet split across two bins, which is ordinary — would
        // each build a second lot row for it, splitting one batch in two on the way in. Inside the
        // caller's transaction, so a failure below still rolls the whole delivery back.
        $this->em->flush();

        return $lot;
    }

    /**
     * Adds what arrived to the PO line's running received total.
     *
     * Maintained rather than summed on read — see PurchaseOrderLine::$quantityReceived — and
     * written here, in the same transaction as the receipt that changed it, which is what stops the
     * two from drifting.
     *
     * Over-receipt is added like anything else. 250 arriving against a PO for 240 is recorded, the
     * variance is surfaced by the three-way match, and the line reads as over-received. Refusing it
     * would mean the warehouse cannot record what is physically on the dock, which is worse than
     * the discrepancy.
     */
    private function creditPurchaseOrderLine(?PurchaseOrderLine $line, string $quantity): void
    {
        if (!$line instanceof PurchaseOrderLine) {
            return;
        }

        $line->setQuantityReceived(QuantityScale::add($line->getQuantityReceived(), $quantity));
    }

    private function recordOnPurchaseOrder(PurchaseOrder $order, GoodsReceipt $receipt): void
    {
        $order->queueActivityLogEntry()
            ->setUserName($receipt->getReceivedBy() ?? 'System')
            ->setComment(sprintf(
                'Receipt %s booked in %s unit(s) across %d line(s)%s.',
                $receipt->getReceiptNumber(),
                $receipt->getTotalQuantity(),
                $receipt->getLines()->count(),
                $receipt->getPackingSlip() !== null ? sprintf(' against packing slip %s', $receipt->getPackingSlip()) : '',
            ))
            ->setType('System');
    }

    private function assertOrderAcceptsReceipts(?PurchaseOrder $order): void
    {
        if (!$order instanceof PurchaseOrder) {
            return;
        }

        // Only Draft and Cancelled are refused, and a Closed order deliberately is not — see
        // PurchaseOrderStatus::refusesReceipts(). A late delivery against a written-off remainder is
        // recorded against the line it belongs to, and the deriver leaves the Closed status alone.
        if ($order->getStatusEnum()->refusesReceipts()) {
            throw new ReceivingException(sprintf(
                'Purchase order %s is %s, so there is nothing for these goods to have arrived against. Issue it first, or record this as a receipt with no purchase order.',
                $order->getPoNumber(),
                $order->getStatus(),
            ));
        }
    }

    /**
     * @param array{product: ProductCore, quantity: string, purchaseOrderLine: ?PurchaseOrderLine, lotCode: ?string, expiry: ?\DateTimeImmutable, serial: ?string, location: ?WarehouseLocation, unitCost: ?string, unidentified: bool, shortDatedReason: ?string, expiryUnidentified: bool} $line
     */
    private function assertLineIsBookable(ReceivingRequest $request, array $line, int $index): void
    {
        $product = $line['product'];
        $label = $product->getSku() ?: ('product #' . (string) ($product->getId() ?? 0));
        $where = sprintf('Line %d (%s)', $index + 1, $label);

        if (QuantityScale::compare($line['quantity'], 0) <= 0) {
            throw new ReceivingException(sprintf('%s: a receipt line needs a positive quantity. Nothing arriving is not a line.', $where));
        }

        // The blanket refusal of a fractional receipt that used to stand here is GONE. It read
        // "the inventory ledger counts whole units", which was a true statement about the PHP layer
        // and never about the database: `inventory_detail.quantity` and every bucket on
        // `product_inventory` have been `NUMERIC(14, 4)` since #645, and what could not hold a
        // fraction was `QuantityType` handing PHP an `(int)` of them. That is fixed — the type is a
        // decimal type and the accessors above it are strings — so 0.4 of a drum now lands on the
        // shelf as 0.4 and there is nothing left for this guard to protect.
        //
        // It was also the hardest wall in the stack: it refused UNTRACKED dimensional products as
        // well as tracked ones, so inbound was the one direction in which a fractional quantity
        // could not be expressed at all, while the sell side had carried fractions for three
        // phases. The serial rule below is what survives of it, and is the only whole-unit rule
        // this application actually has: a serial identifies one physical unit. A lot does not —
        // a lot is the batch a quantity came from, and 0.6 kg of a batch is the same kind of number
        // as 0.6 kg of anything else.

        if ($line['purchaseOrderLine'] instanceof PurchaseOrderLine) {
            $orderLine = $line['purchaseOrderLine'];
            if ($request->purchaseOrder === null || $orderLine->getPurchaseOrder()->getId() !== $request->purchaseOrder->getId()) {
                throw new ReceivingException(sprintf(
                    '%s: that line belongs to a different purchase order. A receipt records one delivery against one order.',
                    $where,
                ));
            }
        }

        if ($line['location'] instanceof WarehouseLocation
            && $line['location']->getWarehouse()->getId() !== $request->warehouse->getId()
        ) {
            throw new ReceivingException(sprintf(
                '%s: bin %s is not in %s. Goods cannot be put away in a bin at another site.',
                $where,
                $line['location']->getCode(),
                $request->warehouse->getName(),
            ));
        }

        // ── The four guards, against the rule — whose batch, expiry and serial answers are DERIVED
        //    from the product's tracking policy since item 67. A fifth check follows them and is a
        //    WARNING rather than a gate; see the class docblock and the comment on it below.
        //
        //    These guards were always here and were always right. They never fired, because
        //    `ruleFor()` handed back an empty rule for a product with no `procurement_product_rule`
        //    row and NOTHING IN THE APPLICATION EVER CREATED ONE. A warehouse could put every
        //    product on a Serial policy, see the word serial on every screen, and book the goods in
        //    with the box blank. Deriving the three identity answers from the policy is what makes
        //    the guards reach the products they were written for; none of the wording below changed.
        $rule = $this->rules->ruleFor($product);

        // The receiver SAYING these units carry no code — #573's warehouse-mid-transition case,
        // where there is real stock and no paperwork for any of it. It excuses a blank identity and
        // nothing else, and only while the identity really is blank: a line that names a batch code
        // has codes, so it is not an unidentified line whatever the box says.
        $declared = $line['unidentified'] && $line['lotCode'] === null && $line['serial'] === null;

        // The SAME declaration, aimed at the expiry alone (#792). Lot Skip and expiry Skip are
        // independent on the receive screen: a row can carry a real batch code and still have no
        // expiry to give it, so this excuses `isExpiryRequired()` on its own rather than only
        // through `$declared`, which would also have to give up the batch code to reach it. It
        // excuses nothing else — a serial or a missing batch still needs `$declared` above.
        $expiryDeclared = $line['expiryUnidentified'] && $line['expiry'] === null;

        if ($rule->isLotRequired() && $line['lotCode'] === null && !$declared) {
            throw new ReceivingException(sprintf(
                '%s is lot-tracked and no batch code was entered. The code is on the carton; it cannot be recovered once the stock is on the shelf. If these units genuinely arrived with no code on them, say so on the line and they will be booked in unidentified and put on the tracking worklist.',
                $where,
            ));
        }

        if ($rule->isExpiryRequired() && $line['expiry'] === null && !$declared && !$expiryDeclared) {
            throw new ReceivingException(sprintf(
                '%s needs an expiry date. Expiry is the LAST USABLE DAY, and it is a property of the goods rather than of the delivery — a line booked in without one reads as "does not expire" everywhere it appears afterwards.',
                $where,
            ));
        }

        // ── The fifth check, and the only one that is a WARNING rather than a gate (items 68, 69).
        //
        //    Two findings reach it and neither refuses the delivery, because the pallet is already
        //    on the dock by the time anybody reads the date — refusing the RECORD does not refuse
        //    the PALLET, it makes real stock invisible, and invisible stock is picked anyway. So
        //    this refuses only until somebody says WHY, and the saying is recorded against the line
        //    as a ShortDatedReceipt with their name on it.
        //
        //    A bare tick would not do. "Accepted by X on Y" answers who and when and leaves the
        //    only question anybody actually asks a fortnight later — on what grounds — unanswered,
        //    which is exactly the difference between an override and a shrug.
        //
        //    **Item 69's finding is a separate trigger, not a stricter minimum.** A date already in
        //    the past fires whatever the global says and whatever the product's override says,
        //    including an override of 0 — see MinimumShelfLife::findingFor(). It is also NOT in
        //    `calendarDate()`, and deliberately: that method validates the FORMAT of a posted date
        //    for thirteen call sites in this bundle, several of which record or filter on dates that
        //    are past on purpose — an as-of date, a bill's document date, an overdue due date, a
        //    vendor's letter date. And its null already means "absent or not a date", so a third
        //    meaning would reach this line as "no expiry was given" instead of "that date has
        //    passed". The parser stays a parser; the judgement belongs here, where the message can
        //    say which of the two things happened.
        $finding = $this->shelfLife->findingFor($product, $line['expiry'], $request->receivedAt);
        if ($finding !== null && ($line['shortDatedReason'] ?? null) === null) {
            throw new ReceivingException(sprintf(
                '%s %s. The goods are on the dock, so this is not a refusal — book them in by saying WHY they are being accepted, in the reason box on the line. The override is recorded against the receipt, with your name and your reason on it, and appears on the receipt afterwards.',
                $where,
                $finding->describe(),
            ));
        }

        // The one requirement that is still a column somebody set, and the one a declaration does
        // not excuse: "somewhere in the warehouse" is not a place a picker can go.
        if ($rule->isLocationRequired() && !$line['location'] instanceof WarehouseLocation) {
            throw new ReceivingException(sprintf('%s needs a destination bin. "Somewhere in the warehouse" is not a place a picker can go.', $where));
        }

        if ($rule->isSerialRequired() && $line['serial'] === null && !$declared) {
            throw new ReceivingException(sprintf(
                '%s is serialised and no serial was entered. Enter one line of 1 per serial — the receipt form takes a whole column of them at once, and the scan console asks for each as you scan it. If these units genuinely arrived with no serials on them, say so on the line and they will be booked in unidentified and put on the tracking worklist.',
                $where,
            ));
        }

        // A serial is one unit, so a line naming one is a line for one — checked on the SERIAL and
        // not on the rule (#573). It used to sit inside the `isSerialRequired()` branch, which meant
        // a receiver who volunteered a serial for a product with no rule row could book 40 units in
        // under it: `uniq_live_serial` constrains the number of rows a serial may have live, never
        // the quantity on one of them, so nothing complained. InventoryDetail::setQuantity() now
        // refuses that outright; this is the copy of the rule that can say what to do about it.
        if ($line['serial'] !== null && QuantityScale::compare($line['quantity'], 1) !== 0) {
            throw new ReceivingException(sprintf(
                '%s names serial %s, so it is a line for one unit. Enter %s separate lines of 1, each with its own serial.',
                $where,
                $line['serial'],
                rtrim(rtrim($line['quantity'], '0'), '.'),
            ));
        }
    }

    /**
     * No two units on this delivery — or on the shelf — may claim the same serial.
     *
     * A serial identifies ONE unit. `uniq_live_serial` already makes the database refuse a second
     * live row for a value, so without this the failure still happens; it happens as a raw unique
     * constraint violation out of the driver, after the transaction has done work, naming an index.
     * A receiver holding seventy cartons and a pasted manifest cannot act on that. This names the
     * serial and the two lines, before anything is written.
     *
     * It is the check that makes bulk serial entry safe: a pasted column is exactly where the same
     * value appears twice, because a manifest was copied with an off-by-one or a carton was counted
     * twice.
     *
     * Compared case-insensitively. `abc-1` and `ABC-1` on two cartons are one serial typed twice far
     * more often than two units, and two rows a later scan cannot tell apart is worse than a
     * correction made in front of the goods.
     *
     * The policy's sentinel is skipped: it is a LABEL on the unidentified row rather than an
     * identity, so N unidentified units legitimately share it — see TrackingPolicy::$sentinelIn.
     */
    private function assertSerialsAreDistinct(ReceivingRequest $request): void
    {
        $seen = [];

        foreach ($request->lines() as $index => $line) {
            $serial = $line['serial'];
            if ($serial === null) {
                continue;
            }

            $policy = $this->policies->policyFor($line['product']);
            if ($policy->isSentinelIn($serial)) {
                continue;
            }

            $key = mb_strtolower($serial);

            if (isset($seen[$key])) {
                throw new ReceivingException(sprintf(
                    'Serial %s is on line %d of this delivery and again on line %d. A serial identifies one unit, so two lines carrying it are two units claiming to be the same unit. Check the manifest against the cartons and correct one of them — nothing has been booked in.',
                    $serial,
                    $seen[$key] + 1,
                    $index + 1,
                ));
            }

            $seen[$key] = $index;

            if ($this->serialIsAlreadyOnTheShelf($line['product'], $serial)) {
                throw new ReceivingException(sprintf(
                    'Serial %s is already in stock for %s, so it cannot arrive again — one serial is one unit and that unit has not left. Either this is a duplicate of a delivery already booked in, or the serial was mistyped. Nothing has been booked in.',
                    $serial,
                    $line['product']->getSku() ?: ('product #' . (string) ($line['product']->getId() ?? 0)),
                ));
            }
        }
    }

    /**
     * Whether this product already has a live `inventory_detail` row under this serial.
     *
     * Reads the detail table directly rather than through #550's service, because the question is
     * about rows rather than about a movement, and asking it is what turns a unique-index violation
     * into a sentence. `quantity > 0` matches `uniq_live_serial`'s own partial predicate exactly, so
     * this refuses precisely what the index would and nothing more: a serial whose unit has been
     * shipped or written off is free to arrive again, which is what a warranty return is.
     *
     * Compared WITHOUT case, because that is the rule its caller states and enforces in its other
     * half: `assertSerialsAreDistinct()` keys `$seen` on `mb_strtolower($serial)`, so `SN-1` and
     * `sn-1` are one serial inside a delivery. Asking `d.serial = :serial` here made them one serial
     * within a single POST and two across two, which is the same physical unit booked in twice with
     * two live `inventory_detail` rows a later scan cannot tell apart — the exact harm the rule is
     * worded against.
     *
     * This is deliberately WIDER than `uniq_live_serial`, which is binary-collated and so would let
     * the second spelling through. The index is the backstop for a race; the rule is this method's,
     * and a rule that holds only where the index happens to agree is not the rule that was written.
     */
    private function serialIsAlreadyOnTheShelf(ProductCore $product, string $serial): bool
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(d.id) FROM ' . InventoryDetail::class . ' d'
            . ' WHERE d.product = :product AND LOWER(d.serial) = LOWER(:serial) AND d.quantity > 0'
        )
            ->setParameter('product', $product)
            ->setParameter('serial', $serial)
            ->getSingleScalarResult() > 0;
    }

    /**
     * Will booking this product in resolve a NAMED bin/lot/serial, or land in the warehouse's
     * unspecified row instead.
     *
     * Every line is handed to `StockMovementService::apply()` regardless of the answer — `apply()`
     * always credits `received` and always writes a detail row now. This only decides
     * `GoodsReceiptLine::movementApplied` and which `DetailKey` the line resolves against: a
     * product that has not opted into the depth layer gets no location/lot/serial identity, so its
     * key carries none.
     */
    private function reachesStock(ProductCore $product): bool
    {
        // Through the resolver, which asks BOTH whether the product opted in AND whether the depth
        // bundle is live. Reading the column alone is how a switched-off InventoryDepthBundle kept
        // being written to: every screen agreed it was off while a receipt still booked movement
        // groups and detail rows into it (#566).
        return $this->inventoryModes->isDimensional($product);
    }

    /**
     * The receipt this operation already produced, if it produced one.
     *
     * Reads `goods_receipt.client_operation_id` directly rather than going by way of the movement
     * group's. That indirection is where this started and where it was quietly wrong: a delivery of
     * nothing but `simple`-inventory products writes no movement group, so a resubmitted form would
     * have found nothing to recognise and written a second receipt for the same goods. Idempotency
     * that only holds when stock happened to move is the kind that fails on the one delivery nobody
     * checked.
     */
    private function existingReceiptFor(?string $clientOperationId): ?GoodsReceipt
    {
        $key = trim((string) $clientOperationId);
        if ($key === '') {
            return null;
        }

        $receipt = $this->receipts->findOneBy(['clientOperationId' => $key]);
        if ($receipt instanceof GoodsReceipt) {
            return $receipt;
        }

        // A group under this key with no receipt attached means #550 applied the movements and this
        // bundle then failed to record them — which its transaction makes impossible, but the two
        // are separate tables and the cheap check keeps a resubmission from double-applying if they
        // ever stop being written together.
        $group = $this->movementGroups->findOneByClientOperationId($key);
        if (!$group instanceof InventoryMovementGroup) {
            return null;
        }

        return $this->receipts->findOneBy(['movementGroup' => $group]);
    }

    private function productName(ProductCore $product, ?PurchaseOrderLine $orderLine): string
    {
        // The PO line's snapshot wins when there is one: what the buyer ordered is what the receiver
        // is checking against, even if the catalogue has been re-titled since.
        if ($orderLine instanceof PurchaseOrderLine && $orderLine->getName() !== '') {
            return $orderLine->getName();
        }

        return $product->getName() ?: ($product->getSku() ?: '(unnamed product)');
    }
}
