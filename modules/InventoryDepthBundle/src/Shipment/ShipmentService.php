<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Shipment;

use App\Entity\InvoiceLine;
use App\Entity\ProductCore;
use App\Entity\TrackingPolicy;
use App\Entity\Warehouse;
use App\Service\Inventory\InventoryModeResolver;
use App\Service\Inventory\OrderInventoryBucketResolver;
use App\Service\DisplayNumber;
use App\Service\QuantityScale;
use App\Service\WarehouseFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\Shipment;
use InventoryDepthBundle\Entity\ShipmentLine;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use InventoryDepthBundle\Repository\InventoryDetailRepository;
use InventoryDepthBundle\Repository\InventoryLotRepository;

/**
 * Records what actually left the building, against one or more invoices for one customer
 * (`docs/plans/2026-09-14-shipment-dispatch.md`).
 *
 * The two questions the plan reduces Shipment to:
 *
 *  1. **Always, every product**: how much of each invoice line shipped, on this shipment. Answered
 *     by writing a `ShipmentLine` row. Zero `ProductInventory` effect — nothing in this app
 *     decrements Starting Inventory on shipment for any product.
 *  2. **Only when dimensional AND tracked outbound**: which lot(s)/serial(s), reconciling to
 *     question 1. Answered by moving that exact quantity from `available` to `sold` via
 *     `StockMovementService`.
 *
 * ## Step 1 and step 2 are one call, not two services (2026-09-20 revision)
 *
 * Question 1 alone — `ShipmentRequest::add($invoiceLine, $quantity)`, no breakdown — is the whole
 * feature for a `simple` product and the ONLY thing `InvoiceShippingRemainderSubscriber` ever sends
 * (it has no human to ask which lot). For that case this falls back to the invoice line's own
 * captured lot/serial, exactly as it always has: `MandatoryCaptureGuard` still requires and
 * validates one before the invoice can leave Draft, and that requirement is NOT loosened by this
 * revision — it is what stops "mark Completed in one click" from being a silent way around
 * mandatory outbound identity capture, since that path has no picker to fall back on either.
 *
 * Question 2's real granularity — which specific lot(s)/serial(s) actually left, potentially several
 * of them for one invoice line, potentially different ones across a partial shipment shipped in more
 * than one trip — cannot be answered by a single field on the invoice line, and was wrong to try:
 * a serial identifies one unit, so a qty-5 line can never have "the" serial; a lot can run out
 * mid-pick and need a second, later-received batch to finish the line. `ShipmentRequest::add()`
 * therefore accepts an optional list of allocations — `{lotId or serial, quantity}` pairs — and when
 * given, THAT is authoritative for this shipment: it may name a different lot/serial than the
 * invoice line's own captured one, because picking happens for real here, against live stock, at the
 * moment goods are actually pulled off the shelf. One invoice line can therefore produce several
 * `ShipmentLine` rows in one shipment, each with its own lot/serial — the schema always allowed this
 * (`ShipmentLine.invoiceLine` is a plain FK, not unique), so this needed no migration.
 *
 * The one new rule this adds: **the allocations for a line must sum to exactly what's being shipped
 * for that line, in this shipment** — checked in `assertRequestIsShippable()`, never assumed. That
 * sits beside, not instead of, the existing invoice-line-level reconciliation
 * (`remainingToShip()`/`shippedUnitsSoFar()`): total shipped across every shipment and every lot/serial
 * split for one invoice line still can never exceed what was billed, and that check is
 * split-agnostic by construction — it sums `ShipmentLine.quantity` by `invoiceLine`, not by lot.
 *
 * ## It does not write stock unless a line answers question 2. It asks #550 to.
 *
 * Same discipline as `ProcurementBundle\Receiving\ReceivingService` on the way in: this builds a
 * `MovementRequest` and hands it to `StockMovementService::apply()` — never touches
 * `inventory_detail` directly, never touches `product_inventory.quantity`. A `simple` product, or a
 * `dimensional` one whose TrackingPolicy does not track lots/serials outbound, gets only the
 * `ShipmentLine` row, `movementApplied = false` — the same distinction
 * `GoodsReceiptLine::$movementApplied` draws on the way in.
 *
 * ## Never touches a reservation or a bucket column
 *
 * `approved`/`shipped` already hold the right numbers by the time this runs — see
 * `docs/plans/2026-09-15-shipment-approved-to-shipped-bucket.md`, whose accrual is triggered by the
 * invoice's own status transition, unconditionally, whether or not a `Shipment` ever exists. This
 * service never writes `InvoiceInventoryReservation` or either bucket column;
 * `SellingADimensionalProductTest` is what settled that this is correct rather than an oversight —
 * the invoice's hold does not release on ship, because nothing in this app has a mechanism to hand
 * Starting Inventory back.
 *
 * ## Quantities are decimal. Stock is not. (2026-09-17)
 *
 * Every quantity this class reasons about goes through `App\Service\QuantityScale` — whole units of
 * the exact precision of `invoice_line.quantity` and `shipment_line.quantity`, and the same
 * arithmetic `App\Service\InvoiceShippingStatusDeriver` already does in core. It used to be
 * `(int) round((float) $quantity)` in six places, which refused a 0.4 part shipment outright ("must
 * be greater than zero") and let a 1.6 one ship 2 — more than was ever billed. Fractional quantities
 * are settled policy here, so both were bugs rather than simplifications.
 *
 * The line that does NOT become decimal is question 2's. `inventory_detail.quantity` is a plain
 * `int` column of physical units and `MovementRequest::move()` takes an `int`: there is no such
 * thing as 0.4 of a serial, or of a binned row. So a line that reaches the movement layer must name
 * a whole number of units, and {@see self::assertRequestIsShippable()} refuses a fractional pick for
 * a tracked product by NAME rather than rounding it into something the ledger can hold. Paperwork
 * for an untracked line stays free to be as fractional as the invoice was.
 */
final class ShipmentService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockMovementService $movements,
        private readonly InventoryDetailRepository $details,
        private readonly InventoryLotRepository $lots,
        private readonly InventoryModeResolver $modes,
        private readonly WarehouseFulfillmentRegionService $regions,
        private readonly ShipmentNumberGenerator $numbers,
    ) {
    }

    /**
     * Records a whole shipment atomically and returns it.
     *
     * Idempotent on the request's `clientOperationId`, the same two-layer shape
     * `ReceivingService::receive()` uses: a resubmitted form finds the existing shipment and
     * applies nothing.
     *
     * @throws ShipmentException when the request cannot be recorded as described
     */
    public function ship(ShipmentRequest $request, ?string $actor = null): Shipment
    {
        if ($request->isEmpty()) {
            throw new ShipmentException('A shipment with no lines records nothing. Enter what shipped.');
        }

        // Idempotency first, and deliberately BEFORE the oversell guard below: that guard reads
        // how much of each invoice line is already shipped, which a resubmission's own earlier
        // attempt already changed. Checking business rules before recognising a resubmission would
        // make the second, identical call to ship() fail the very check the first call satisfied.
        $existing = $this->existingShipmentFor($request->clientOperationId);
        if ($existing instanceof Shipment) {
            return $existing;
        }

        // Whole-request checks next, so a submission is either recordable whole or refused whole
        // — same discipline as ReceivingService::assertLineIsBookable() running before anything is
        // written.
        $this->assertRequestIsShippable($request);

        return $this->em->wrapInTransaction(function () use ($request, $actor): Shipment {
            $shipmentNumber = $this->numbers->next($this->em);

            $key = trim((string) $request->clientOperationId);

            $shipment = (new Shipment())
                ->setCompany($request->company)
                ->setShipmentNumber($shipmentNumber)
                ->setClientOperationId($key !== '' ? $key : null)
                ->setShippedAt($request->shippedAt)
                ->setShippedBy($request->shippedBy)
                ->setNotes($request->notes);

            $this->em->persist($shipment);

            $movement = MovementRequest::of(
                InventoryMovementGroup::TYPE_SHIP,
                $request->clientOperationId,
                sprintf('Shipped against invoice(s) for %s', $request->company->getName()),
                $actor,
                $shipmentNumber,
                $request->shippedAt,
            );

            /** @var array<string, Warehouse>|null $warehousesByRegion built lazily, only if a question-2 line needs it */
            $warehousesByRegion = null;

            foreach ($request->lines() as $line) {
                $invoiceLine = $line['invoiceLine'];
                $product = $invoiceLine->getProduct();

                // No breakdown given: the implicit single pick, at the invoice line's own captured
                // identity — question 1 only, for a simple product or one not tracked outbound, and
                // the shape InvoiceShippingRemainderSubscriber always sends (it has no picker to ask).
                // A breakdown given is authoritative: it may name a different lot/serial than the
                // invoice line's own, because it was picked for real, against live stock, at the
                // moment this shipment was actually packed.
                $picks = $line['allocations'] !== []
                    ? $line['allocations']
                    : [['lotId' => $invoiceLine->getLotId(), 'serial' => $invoiceLine->getSerial(), 'quantity' => $line['quantity']]];

                foreach ($picks as $pick) {
                    // Rounded to the store's configured scale (or the product's own step) once, and
                    // used for both the shipment line written and the movement planned.
                    $pick['quantity'] = QuantityScale::round($pick['quantity'], $product);
                    if (QuantityScale::compare($pick['quantity'], 0) <= 0) {
                        continue;
                    }

                    $shipmentLine = (new ShipmentLine())
                        ->setInvoiceLine($invoiceLine)
                        ->setProduct($product)
                        ->setName($invoiceLine->getName())
                        ->setSku($invoiceLine->getSku())
                        // The pick's own string, at the store's configured scale — see above.
                        ->setQuantity($pick['quantity']);

                    $shipment->addLine($shipmentLine);
                    $this->em->persist($shipmentLine);

                    if (!$product instanceof ProductCore || !$this->tracksOutbound($product)) {
                        continue;
                    }

                    $warehousesByRegion ??= $this->regions->warehousesByLowerRegionName();
                    $warehouse = OrderInventoryBucketResolver::resolveLineWarehouse(
                        $invoiceLine->getLocation(),
                        $invoiceLine->getInvoice()->getFulfillmentRegion(),
                        $warehousesByRegion,
                    );

                    if (!$warehouse instanceof Warehouse) {
                        throw new ShipmentException(sprintf(
                            'Cannot ship %s: no warehouse serves the fulfillment region named on this invoice line.',
                            $invoiceLine->getName(),
                        ));
                    }

                    // The movement layer is decimal now; only a SERIAL is held to whole units,
                    // by assertSerialPicksAreWholeUnits().
                    $pickQuantity = $pick['quantity'];

                    $policy = $product->getTrackingPolicy();
                    if ($policy instanceof TrackingPolicy && $policy->tracksLotsOutbound()) {
                        $this->planLotWithdrawal($movement, $product, $warehouse, $invoiceLine, $pick['lotId'], $pickQuantity);
                        $shipmentLine->setLotId($pick['lotId']);
                    } else {
                        $this->planSerialWithdrawal($movement, $product, $warehouse, $invoiceLine, $pick['serial'], $pickQuantity);
                        $shipmentLine->setSerial($pick['serial']);
                    }

                    $shipmentLine->setMovementApplied(true);
                }
            }

            if (!$movement->isEmpty()) {
                $this->movements->apply($movement);
            }

            return $shipment;
        });
    }

    /**
     * Refuses the whole request, before anything is written, for any of the reasons that apply
     * across the request rather than to one physical pick: a same-customer violation
     * (`docs/plans/2026-09-14-shipment-dispatch.md`'s "Combined shipments" guard, same
     * whole-submission shape as `StockMovementService::assertSerialRowsStayAtOne()`), shipping more
     * of an invoice line than is left on it, or a lot/serial breakdown that doesn't sum to the
     * quantity it's supposed to account for.
     *
     * Every comparison here is exact-decimal arithmetic (`App\Service\QuantityScale`, backed by
     * bcmath), never floats and never rounded whole units. That is what lets 0.4 of a case be shipped
     * at all — it used to round to 0 and be refused as "must be greater than zero" — and what stops
     * 1.6 rounding up to 2 and overshipping the line it was billed on. A breakdown of 0.6 + 0.4
     * against a 1.0 line also only sums exactly because bcadd carries every digit: as floats it is
     * short of 1 forever.
     *
     * The one guard that is NOT about decimals is the last one. A tracked line's pick has to become
     * a row count in `inventory_detail`, which is an `int` column, so a fraction is refused BY NAME
     * here rather than rounded into something the ledger can hold two methods later.
     */
    private function assertRequestIsShippable(ShipmentRequest $request): void
    {
        /** @var array<int|string, string> $requested decimal strings, keyed by invoice line id (or object id, unsaved) */
        $requested = [];
        /** @var array<int|string, InvoiceLine> $byId */
        $byId = [];

        foreach ($request->lines() as $line) {
            $invoiceLine = $line['invoiceLine'];
            $quantity = QuantityScale::canonical($line['quantity']);

            if (QuantityScale::compare($quantity, 0) <= 0) {
                throw new ShipmentException(sprintf('%s: shipment quantity must be greater than zero.', $invoiceLine->getName()));
            }

            if ($line['allocations'] !== []) {
                $allocated = '0.0000';
                foreach ($line['allocations'] as $allocation) {
                    $allocated = QuantityScale::add($allocated, $allocation['quantity']);
                }

                if (QuantityScale::compare($allocated, $quantity) !== 0) {
                    throw new ShipmentException(sprintf(
                        '%s: the lot/serial breakdown totals %s, but this shipment is for %s.',
                        $invoiceLine->getName(),
                        $allocated,
                        $quantity,
                    ));
                }
            }

            $lineCompany = $invoiceLine->getInvoice()->getCompany();
            if ($lineCompany !== $request->company && $lineCompany->getId() !== $request->company->getId()) {
                throw new ShipmentException(sprintf(
                    '%s belongs to an invoice for a different company than this shipment.',
                    $invoiceLine->getName(),
                ));
            }

            $this->assertSerialPicksAreWholeUnits($line);

            $id = $invoiceLine->getId() ?? spl_object_id($invoiceLine);
            $requested[$id] = QuantityScale::add($requested[$id] ?? 0, $quantity);
            $byId[$id] = $invoiceLine;
        }

        foreach ($requested as $id => $quantity) {
            $invoiceLine = $byId[$id];
            $billed = QuantityScale::canonical($invoiceLine->getQuantity());
            $alreadyShipped = $this->shippedSoFar($invoiceLine);

            if (QuantityScale::compare(QuantityScale::add($alreadyShipped, $quantity), $billed) > 0) {
                $unshipped = QuantityScale::sub($billed, $alreadyShipped);

                throw new ShipmentException(sprintf(
                    '%s: this shipment would ship %s more, but only %s of %s on the invoice remains unshipped.',
                    $invoiceLine->getName(),
                    $quantity,
                    QuantityScale::compare($unshipped, 0) > 0 ? $unshipped : QuantityScale::canonical(0),
                    $billed,
                ));
            }
        }
    }

    /**
     * Refuses a fractional pick on a SERIAL-tracked line, and on nothing else.
     *
     */
    private function assertSerialPicksAreWholeUnits(array $line): void
    {
        $invoiceLine = $line['invoiceLine'];
        $product = $invoiceLine->getProduct();

        if (!$product instanceof ProductCore || !$this->tracksSerialsOutbound($product)) {
            return;
        }

        // The same picks ship() will walk: the breakdown when one was given, otherwise the single
        // implicit pick at the line's own captured lot/serial.
        $quantities = $line['allocations'] === []
            ? [$line['quantity']]
            : array_map(static fn (array $allocation): string => $allocation['quantity'], $line['allocations']);

        foreach ($quantities as $quantity) {
            if (QuantityScale::isWhole($quantity)) {
                continue;
            }

            throw new ShipmentException(sprintf(
                '%s: %s cannot ship as %s. It is tracked by serial, and a serial identifies one physical unit — there is no part of one to pick. Ship whole units, one serial each.',
                $invoiceLine->getName(),
                $product->getSku(),
                (new DisplayNumber())->qty($quantity),
            ));
        }
    }

    /**
     * How much of this invoice line is left to ship — the billed quantity minus what an earlier,
     * non-voided shipment already covered — as the decimal string the columns hold.
     *
     * A DECIMAL STRING, the same shape `InvoiceLine::getQuantity()` answers in, because that is what
     * this is: a quantity. It used to be an `int` of `(int) round((float) …)`, which reported 0.4 of
     * a case left as nothing left (so `InvoiceShippingRemainderSubscriber` skipped the line and the
     * invoice completed with goods still owed) and 1.6 left as 2 (so the auto-shipment it built was
     * refused by the oversell guard it was supposed to satisfy).
     *
     * Public for `InvoiceShippingRemainderSubscriber` and `ShipmentController`, which both have to
     * know this BEFORE building a `ShipmentRequest`: `ship()` refuses an oversell rather than
     * clamping to what remains, so the callers that ship "whatever's left" need the same number
     * `assertRequestIsShippable()` checks against, not a second computation of it. A caller doing
     * a `> 0` test compares the decimal string directly with `QuantityScale::compare()` — there is no
     * separate integer form any more; bcmath compares the string exactly, so nothing is gained by
     * scaling it first.
     */
    public function remainingToShip(InvoiceLine $invoiceLine): string
    {
        $billed = QuantityScale::canonical($invoiceLine->getQuantity());
        $remaining = QuantityScale::sub($billed, $this->shippedSoFar($invoiceLine));

        return QuantityScale::compare($remaining, 0) > 0 ? $remaining : QuantityScale::canonical(0);
    }

    /**
     * How much of this invoice line already left on an earlier, non-voided shipment, as the decimal
     * string the column holds.
     *
     * The identical sum `InventoryDepthBundle\Inventory\ShippedQuantityProvider` gives core, down to
     * the `s.voidedAt IS NULL` clause — a voided shipment has, as far as the goods are concerned,
     * not happened. It is written out twice on purpose (that class's own docblock says why: this one
     * is private and serves a guard), and now that both sides keep the fractions, the two can no
     * longer disagree about whether 0.4 of a case shipped.
     */
    private function shippedSoFar(InvoiceLine $invoiceLine): string
    {
        if ($invoiceLine->getId() === null) {
            return QuantityScale::canonical(0);
        }

        $sum = $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(sl.quantity), 0)')
            ->from(ShipmentLine::class, 'sl')
            ->innerJoin('sl.shipment', 's')
            ->andWhere('sl.invoiceLine = :invoiceLine')->setParameter('invoiceLine', $invoiceLine)
            ->andWhere('s.voidedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();

        return QuantityScale::canonical((string) $sum);
    }

    /**
     * Gate for question 2: dimensional AND tracked outbound, in either mode.
     *
     * Public so `ShipmentController` can ask the identical question when deciding whether to render
     * a lot/serial breakdown on the form — the same "one shared function" reasoning as
     * `remainingToShip()`, not a second copy of this check.
     */
    public function tracksOutbound(ProductCore $product): bool
    {
        if (!$this->modes->isDimensional($product)) {
            return false;
        }

        $policy = $product->getTrackingPolicy();

        return $policy instanceof TrackingPolicy && ($policy->tracksLotsOutbound() || $policy->tracksSerialsOutbound());
    }

    /**
     * Gate for the ONE whole-unit rule this application has — see
     * {@see self::assertSerialPicksAreWholeUnits()}.
     *
     * Deliberately narrower than {@see self::tracksOutbound()}: that one answers "does this line
     * reach the stock ledger", which lot and serial both do, and this one answers "is a unit of this
     * product an indivisible physical thing", which only a serial makes true.
     */
    public function tracksSerialsOutbound(ProductCore $product): bool
    {
        if (!$this->modes->isDimensional($product)) {
            return false;
        }

        $policy = $product->getTrackingPolicy();

        return $policy instanceof TrackingPolicy && $policy->tracksSerialsOutbound();
    }

    /**
     * Folds `$lotId` into `$movement`, drawing across as many bins as that lot needs
     * (`InventoryDetailRepository::availableRowsForLot()`). Refuses — never substitutes a different
     * lot — when it does not cover the quantity.
     *
     * `$lotId` is either the invoice line's own captured lot (no breakdown given — question 1 only)
     * or one entry of a step-2 allocation; this method has no opinion on which, because by the time
     * it runs that choice has already been made and checked (`assertRequestIsShippable()`).
     */
    private function planLotWithdrawal(
        MovementRequest $movement,
        ProductCore $product,
        Warehouse $warehouse,
        InvoiceLine $invoiceLine,
        ?int $lotId,
        string $quantity,
    ): void {
        if ($lotId === null) {
            throw new ShipmentException(sprintf(
                '%s tracks lots outbound but no lot was named.',
                $invoiceLine->getName(),
            ));
        }

        $lot = $this->lots->find($lotId);
        if (!$lot instanceof InventoryLot || $lot->getProduct() !== $product) {
            throw new ShipmentException(sprintf('%s: lot #%d no longer names this product.', $invoiceLine->getName(), $lotId));
        }

        // Drawn down as exact decimal strings, so a lot split across bins adds back up to exactly
        // what was asked for — a float subtraction here would leave a ten-thousandth outstanding
        // and refuse a pick the shelf covers.
        $requested = QuantityScale::canonical($quantity);
        $outstanding = $requested;

        foreach ($this->details->availableRowsForLot($lot, $warehouse) as $row) {
            if (QuantityScale::compare($outstanding, 0) <= 0) {
                break;
            }

            $available = QuantityScale::canonical($row->getQuantity());
            $take = QuantityScale::compare($outstanding, $available) <= 0 ? $outstanding : $available;
            if (QuantityScale::compare($take, 0) <= 0) {
                continue;
            }

            $from = new DetailKey($warehouse, $row->getLocation(), $lot, null, InventoryDetail::STATUS_AVAILABLE);
            $movement->move($product, $from, $from->forStatus(InventoryDetail::STATUS_SOLD), $take);
            $outstanding = QuantityScale::sub($outstanding, $take);
        }

        if (QuantityScale::compare($outstanding, 0) > 0) {
            $display = new DisplayNumber();

            throw new ShipmentException(sprintf(
                '%s: lot %s covers only %s of the %s unit(s) being shipped.',
                $invoiceLine->getName(),
                $lot->getLabel(),
                $display->qty(QuantityScale::sub($requested, $outstanding)),
                $display->qty($quantity),
            ));
        }
    }

    /**
     * Folds `$serial` into `$movement`. A serial identifies exactly one unit (rule 3), so unlike a
     * lot there is nothing to split across bins and no case where a partial quantity is meaningful.
     *
     * `$serial` is either the invoice line's own captured serial (no breakdown given) or one entry
     * of a step-2 allocation — see `planLotWithdrawal()`'s identical note.
     */
    private function planSerialWithdrawal(
        MovementRequest $movement,
        ProductCore $product,
        Warehouse $warehouse,
        InvoiceLine $invoiceLine,
        ?string $serial,
        string $quantity,
    ): void {
        if ($serial === null || $serial === '') {
            throw new ShipmentException(sprintf(
                '%s tracks serials outbound but no serial was named.',
                $invoiceLine->getName(),
            ));
        }

        if (QuantityScale::compare($quantity, 1) !== 0) {
            throw new ShipmentException(sprintf(
                '%s: serial %s identifies one unit; this line asks to ship %s.',
                $invoiceLine->getName(),
                $serial,
                (new DisplayNumber())->qty($quantity),
            ));
        }

        $row = $this->details->findAvailableSerial($product, $warehouse, $serial);
        if (!$row instanceof InventoryDetail) {
            throw new ShipmentException(sprintf(
                '%s: serial %s is not available in %s.',
                $invoiceLine->getName(),
                $serial,
                $warehouse->getName(),
            ));
        }

        $from = new DetailKey($warehouse, $row->getLocation(), null, $serial, InventoryDetail::STATUS_AVAILABLE);
        $movement->move($product, $from, $from->forStatus(InventoryDetail::STATUS_SOLD), 1);
    }

    private function existingShipmentFor(?string $clientOperationId): ?Shipment
    {
        $key = trim((string) $clientOperationId);
        if ($key === '') {
            return null;
        }

        $shipment = $this->em->getRepository(Shipment::class)->findOneBy(['clientOperationId' => $key]);

        return $shipment instanceof Shipment ? $shipment : null;
    }
}
