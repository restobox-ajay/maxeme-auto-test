<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Controller\Admin;

use App\Entity\ProductCore;
use App\Entity\TrackingPolicy;
use App\Entity\Warehouse;
use App\Repository\BundleStatusRepository;
use App\Repository\TrackingPolicyRepository;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryAdjustmentReason;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\InventoryMovement;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Import\DimensionalImportProvider;
use InventoryDepthBundle\Movement\AutomaticSourcePicker;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\InsufficientStockException;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\ReversalPlanner;
use InventoryDepthBundle\Movement\StockMovementService;
use InventoryDepthBundle\Repository\InventoryAdjustmentReasonRepository;
use InventoryDepthBundle\Repository\InventoryDetailRepository;
use InventoryDepthBundle\Repository\InventoryMovementRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\Inventory\InventoryModeResolver;

/**
 * Stock adjustment and cycle count (#550, #585) — the two screens that write.
 *
 * ## What changed in #585, and why
 *
 * This used to be a MOVEMENT EDITOR. It asked the operator to construct a `from → to` movement out
 * of two nine-value status dropdowns, two "is there a side" checkboxes and an eight-value movement
 * type, and to infer for themselves which combination meant "this pallet is broken". Zoho Inventory
 * and Dynamics 365 BC do the opposite and are right to: you pick WHAT HAPPENED and the system
 * derives the movement.
 *
 * The evidence that the old shape was wrong, all of it observable rather than theoretical:
 *
 *  - the reason was free text. On dev: "Opening balance on switching to dimensional inventory" (84),
 *    "Received without a purchase order" (45), `E2E <img src=x onerror=alert(1)>` (2). Nothing could
 *    be reported on it and nothing could map to a G/L account.
 *  - the `type` dropdown offered all eight movement types as a free choice unrelated to what the
 *    form did. A write-off could be filed as a `receipt`.
 *  - **spoilage could not be recorded at all.** `expired` was not on the withdraw list, so a human
 *    finding spoiled stock ahead of the nightly lot sweep had to mis-file it as `scrapped`.
 *  - found stock has two correct answers — `— → available` for goods never counted, and
 *    `lost → available` for goods previously written off — and the screen guided neither. The first
 *    silently invents stock while leaving the loss on the books.
 *  - one serial text box, against a service that correctly refuses more than one unit per serial
 *    row. 300 found serialised units meant 300 submissions.
 *  - the same five fields rendered on both sides regardless of the product's TrackingPolicy, whose
 *    `track_in` and `track_out` flags are independent and mean different things.
 *
 * So the screen is now four steps — what happened, which goods, how many, and the paperwork — and
 * **the operator never sees or picks a status**. InventoryAdjustmentReason carries the
 * (from_status, to_status) pair, the movement type is derived from it, and the bucket that moves is
 * a consequence recomputed by StockMovementService rather than anything this controller commands.
 *
 * ## What was removed
 *
 * Both status dropdowns, the type dropdown, the `has_from`/`has_to` checkboxes and the entire second
 * form — `POST /withdraw`, "Take it out" — whose four destination statuses are four of the reasons
 * now. AutomaticSourcePicker, which that form existed to expose, is emphatically NOT removed: it is
 * what answers an outbound reason on a product whose policy does not track identity outbound, which
 * is the common case and the default policy.
 *
 * ## What this screen deliberately cannot do
 *
 * **If a document should exist, it is not an adjustment.**
 *
 *  - transfers have `/admin/bundles/warehouse-ops/transfers`, with draft, dispatch, receipt, partial
 *    receipt and stock lost in transit. 27 orders on dev;
 *  - bin moves have `/admin/bundles/warehouse-ops/scan`, and change no bucket at all;
 *  - customer returns need a credit memo against the invoice (#586, not built), which is why
 *    `returned` still has no producer.
 *
 * Losing the bin move here is a real loss of a working path and it is deliberate. A bin move is a
 * scan, not a decision about goods, and leaving it on a reason-first screen would have meant one
 * reason whose destination the operator picks — which is the from/to structure back again.
 *
 * ## A cycle count is still an adjustment
 *
 * Unchanged by #585. A count's differences are written as adjustment movements with the count sheet
 * as the reason, which is why there is no separate reconciliation table: a discrepancy is a movement
 * like any other, and giving it its own storage would mean two places to look when asking what
 * happened to a bin. It writes no reason CODE, because "the count disagreed" is not a class of
 * physical event — what happened to the goods is exactly what a count cannot say.
 */
#[Route('/admin/bundles/inventory-depth')]
final class AdjustmentController extends AbstractInventoryDepthController
{
    /**
     * What fieldsFor() would say if there were a reason to ask: nothing is asked for, nothing is
     * offered.
     *
     * Spelled out rather than left to `|default` in the template, because `strict_variables` is on
     * under `when@test` and the two halves of that would disagree — the form would render in dev and
     * throw the moment a functional test loaded it without a reason. Which is the only environment
     * that would have told anyone.
     *
     * @var array<string, mixed>
     */
    private const NO_REASON_CHOSEN_YET = [
        'asksInboundLot' => false,
        'asksInboundSerials' => false,
        'stampsSentinel' => false,
        'asksNamedSource' => false,
        'asksSerialSources' => false,
        'autoPicks' => false,
        'lots' => [],
        'sourceRows' => [],
        'reversible' => [],
        'prefersExpiredLots' => false,
        'expiryRequired' => false,
    ];

    public function __construct(
        EntityManagerInterface $em,
        BundleStatusRepository $bundleStatusRepo,
        InventoryModeResolver $inventoryModes,
        private readonly StockMovementService $movements,
        private readonly AutomaticSourcePicker $picker,
        private readonly ReversalPlanner $reversals,
        private readonly InventoryAdjustmentReasonRepository $reasons,
        private readonly InventoryMovementRepository $ledger,
        private readonly InventoryDetailRepository $details,
        private readonly TrackingPolicyRepository $policies,
    ) {
        parent::__construct($em, $bundleStatusRepo, $inventoryModes);
    }

    /**
     * Step 1 is the reason, and every field below it is shaped by the answer.
     *
     * Product still comes first and still by GET, for the reason it always did: a lot belongs to
     * exactly one product, so nothing lot-shaped can be offered until the product is known. The
     * reason then arrives the same way, because which fields exist at all depends on it — a form
     * that rendered every field and hid the irrelevant ones with JavaScript would be the old screen
     * with a stylesheet.
     */
    #[Route('/adjust', name: 'admin_bundle_inventory_depth_adjust', methods: ['GET'])]
    public function form(Request $request): Response
    {
        $this->denyIfInactive();

        // This used to open with $this->reasons->ensureCatalogue(), which CREATED the eight shipped
        // reasons on a GET. It does not any more: InventoryDepthBundle\ReferenceData\
        // InventoryAdjustmentReasonSeeder owns them and runs once, on the first admin login. This
        // action reads.
        $productId = $request->query->getInt('product', 0);
        $product = $productId > 0 ? $this->em->find(ProductCore::class, $productId) : null;
        $product = $product instanceof ProductCore ? $product : null;

        $reason = $this->reasons->findActiveByCode((string) $request->query->get('reason', ''));
        $policy = $product instanceof ProductCore ? $this->policies->policyFor($product) : null;

        return $this->render('@InventoryDepth/adjust.html.twig', array_merge(
            [
                'product' => $product,
                'products' => $this->adjustableProducts(),
                'reasons' => $this->reasons->activeInOrder(),
                'reason' => $reason,
                'policy' => $policy,
                'warehouses' => $this->activeWarehouses(),
                'bins' => $this->em->getRepository(WarehouseLocation::class)->findBy(['status' => 'Active'], ['sortKey' => 'ASC', 'code' => 'ASC']),
                'warehouseId' => $request->query->getInt('warehouse', 0),
                'today' => (new \DateTimeImmutable())->format('Y-m-d'),
                // How many repeatable rows to render, for the two shapes that need one row per unit.
                // A GET parameter and not JavaScript: "add ten more rows" is then a link, the form
                // works with scripting off, and a re-render cannot lose what has been typed because
                // nothing has been typed until the operator has enough rows.
                'rowCount' => min(200, max(12, $request->query->getInt('rows', 12))),
            ],
            // Every key the template reads is present in BOTH branches. `strict_variables` is on in
            // the test environment, so a key that only exists once a reason is chosen would render
            // fine in dev and throw on the product picker in every functional test.
            $product instanceof ProductCore && $reason instanceof InventoryAdjustmentReason && $policy instanceof TrackingPolicy
                ? $this->fieldsFor($product, $reason, $policy)
                : self::NO_REASON_CHOSEN_YET,
        ));
    }

    /**
     * One reason, one group.
     *
     * Everything the movement needs is derived: the type from the reason, the destination from the
     * reason, and the source from the reason plus the product's TrackingPolicy. What the operator
     * supplies is which goods, how many, and the paperwork.
     */
    #[Route('/adjust', name: 'admin_bundle_inventory_depth_adjust_submit', methods: ['POST'])]
    public function submit(Request $request): Response
    {
        $this->denyIfInactive();

        $product = $this->productOr404($request->request->getInt('product_id', 0));
        $reasonCode = trim((string) $request->request->get('reason', ''));
        $reason = $this->reasons->findActiveByCode($reasonCode);

        if (!$reason instanceof InventoryAdjustmentReason) {
            // Covers three cases with one sentence, because from the operator's side they are the
            // same case: a code that never existed, one an admin has since deactivated, and a POST
            // that names none at all. The last is the one that matters — the reason is what derives
            // the movement, so a submission without one has not said what happened.
            $this->addFlash('error', sprintf(
                '"%s" is not something this screen records. Pick what happened from the list; the movement follows from it.',
                $reasonCode !== '' ? $reasonCode : '(nothing)',
            ));

            return $this->back($product, null);
        }

        $quantity = $request->request->getInt('quantity', 0);
        if ($quantity <= 0) {
            $this->addFlash('error', 'A movement needs a positive quantity — the reason expresses the direction, not a minus sign.');

            return $this->back($product, $reason);
        }

        // The reason rows are configurable, so their two statuses are untrusted input by the time
        // they reach here. An admin editing "Damaged" to write `sold` would hand this screen exactly
        // the power #581 took away from it: stock that is sold with no invoice billing it, held by
        // nothing, still offered by availability. The dropdown is a courtesy; this is the rule, and
        // it is checked on BOTH sides for the reason #581 gives — writing `sold` invents a sale,
        // reading FROM `sold` unbills one with no credit note.
        foreach (['out of' => $reason->getFromStatus(), 'into' => $reason->getToStatus()] as $direction => $status) {
            if ($status === null) {
                continue;
            }

            if (!in_array($status, InventoryDetail::statuses(), true)) {
                $this->addFlash('error', sprintf('Reason "%s" is configured to move stock %s "%s", which is not a stock status at all.', $reason->getLabel(), $direction, $status));

                return $this->back($product, null);
            }

            if (in_array($status, InventoryDetail::documentBackedStatuses(), true)) {
                $this->addFlash('error', sprintf(
                    'Reason "%s" is configured to move stock %s "%s", and an adjustment may not. Stock becomes sold '
                    . 'through the invoice that bills it and in transit through a transfer order — each of which '
                    . 'leaves a document behind, and the figures downstream read that document rather than this row. '
                    . 'Correct the reason, or record what physically happened instead.',
                    $reason->getLabel(),
                    $direction,
                    str_replace('_', ' ', $status),
                ));

                return $this->back($product, null);
            }
        }

        $movement = MovementRequest::of(
            // Derived from the reason, never chosen. See InventoryAdjustmentReason::movementType().
            $reason->movementType(),
            // Keyed on the form's CSRF token, which Symfony re-randomises per render: a
            // double-submitted form applies once, while a deliberate second adjustment (a fresh page
            // load) gets a fresh token and applies again. Stored as a digest — see operationKey().
            $this->operationKey($request),
            // The free-text note, unchanged in meaning and still optional. The reason CODE goes in
            // its own field beside it (#585).
            $this->nullable((string) $request->request->get('note', '')),
            $this->actor(),
            $this->nullable((string) $request->request->get('reference', '')),
            $this->occurredAt($request),
            $reason,
        );

        try {
            $this->addLines($movement, $product, $reason, $request, $quantity);
            $group = $this->movements->apply($movement);
        } catch (InsufficientStockException|\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->back($product, $reason);
        }

        $this->addFlash('success', sprintf(
            '%s recorded: %d unit(s), %d movement(s). The product total and its buckets were recomputed from the detail rows in the same transaction.',
            $reason->getLabel(),
            $quantity,
            \count($group->getMovements()),
        ));

        return $this->redirectToRoute('admin_bundle_inventory_depth_stock_by_product', ['id' => $product->getId()]);
    }

    /**
     * Which fields the form renders, decided in ONE place so that the screen and submit() cannot
     * disagree about what was asked for.
     *
     * That is the whole point of it being a method rather than a pile of conditions in Twig: if the
     * template decided for itself, a policy change would silently produce a form asking for a serial
     * that the controller ignores, or a controller demanding a lot the form never offered. Both fail
     * as "why did nothing happen", which is the least debuggable shape of bug this screen can have.
     *
     * @return array<string, mixed>
     */
    private function fieldsFor(ProductCore $product, InventoryAdjustmentReason $reason, TrackingPolicy $policy): array
    {
        $inbound = $reason->isInbound();
        $named = $this->namesItsSource($product, $reason, $policy);

        return [
            // Inbound, lot-tracked: PICK-OR-CREATE, never a dropdown of existing lots only. A found
            // box carries a batch code printed by a vendor who has never sent this warehouse one
            // before, and a screen that could not accept it would send the operator to the lot
            // screen and back — or, far more likely, get an existing lot picked because it was
            // nearest the truth.
            'asksInboundLot' => $inbound && $policy->tracksLotsInbound(),
            // Inbound, serial-tracked: one row per unit, because a serial row is one unit and the
            // service enforces it. The row count is the quantity.
            'asksInboundSerials' => $inbound && $policy->tracksSerialsInbound(),
            // Inbound on a tracked product that does NOT capture identity on the way in: nothing is
            // asked, the sentinel is stamped, and the row is flagged for the tracking worklist. See
            // inboundKey() for the argument and its cost.
            'stampsSentinel' => $inbound && $this->inboundIsUnidentified($policy),
            // Inbound, no lot to carry it: `requires_expiry` "holds in every mode and needs neither
            // capture direction" (TrackingPolicy's own docblock), so a `none`- or `serial`-mode
            // policy asks for a date here the same way a `lot`-mode one always asked on the batch
            // form. `lot`-mode is excluded — its expiry belongs to the batch, asked above.
            'asksInboundExpiry' => $inbound && $policy->getMode() !== TrackingPolicy::MODE_LOT && $policy->requiresExpiry(),
            'asksNamedSource' => $named,
            // A named source on a serial product is one row per unit, for the same reason as inbound.
            'asksSerialSources' => $named && $policy->getMode() === TrackingPolicy::MODE_SERIAL,
            'autoPicks' => $reason->isOutbound() && !$named,
            'lots' => $inbound
                ? $this->em->getRepository(InventoryLot::class)->findBy(['product' => $product], ['expiry' => 'ASC', 'code' => 'ASC'])
                : [],
            'sourceRows' => $named && $reason->getFromStatus() !== null
                ? $this->details->namedSourceRows($product, $reason->getFromStatus())
                : [],
            // The reversal picker: what is actually reversible, with what is left on each entry.
            // An empty quantity box against an entry nobody has chosen is what this replaces.
            'reversible' => $reason->isReversal() ? $this->ledger->reversibleWriteOffs($product) : [],
            // "Spoiled / expired" defaults its lot list to batches at or past their date. That is
            // what the reason is for; see InventoryAdjustmentReason::prefersExpiredLots().
            'prefersExpiredLots' => $reason->prefersExpiredLots(),
            'expiryRequired' => $policy->requiresExpiry(),
        ];
    }

    /**
     * Does the operator have to name which rows the stock comes off?
     *
     * Two independent yeses, and they are genuinely different questions:
     *
     *  - **the reason takes stock out of something other than `available`.** Releasing a hold is
     *    always against particular goods; there is no picker for held stock and there should not be
     *    one, because "release five of the quarantined ones, you choose which" is not a sentence a
     *    warehouse says.
     *  - **the product's policy tracks identity OUTBOUND.** `track_out` exists precisely to stop
     *    something choosing on the operator's behalf. `track_in` is irrelevant here and that
     *    independence is the point of #573's two flags: a product can require a batch code on the
     *    way in and not on the way out, and this screen has to respect the direction it is actually
     *    moving stock in.
     *
     * When neither holds, AutomaticSourcePicker chooses — earliest expiry, then lowest bin sort key
     * — and the operator is not asked at all. That is the default policy and therefore the common
     * case, and asking anyway would be the movement editor's habit of making every field everyone's
     * problem.
     */
    private function namesItsSource(ProductCore $product, InventoryAdjustmentReason $reason, TrackingPolicy $policy): bool
    {
        if (!$reason->isOutbound()) {
            return false;
        }

        // A simple product has no InventoryDetail row to name — "which specific row" is not a
        // question that exists for it, so the form must never offer a picker with nothing real in
        // it. addLines() takes the same dimensional branch before this method would even be asked.
        if (!$this->inventoryModes->isDimensional($product)) {
            return false;
        }

        if ($reason->getFromStatus() !== InventoryDetail::STATUS_AVAILABLE) {
            return true;
        }

        return $policy->tracksLotsOutbound() || $policy->tracksSerialsOutbound();
    }

    /**
     * Is an inbound row's identity a placeholder rather than something the operator supplied?
     *
     * True when the product carries lots or serials at all but the policy does not capture them on
     * the way in. The row then takes the policy's `sentinel_in` — or NULL, when the sentinel is
     * blank — and `expect_resolution`, which is what puts it on
     * `/admin/bundles/inventory-depth/tracking` for somebody to complete later. That mechanism has
     * existed since #573 and the old adjustment form ignored it entirely, writing a bare NULL lot
     * onto a lot-tracked product with nothing recording that anybody should come back.
     *
     * **The honest cost:** a policy that tracks OUT only — Dynamics' "assign at ship" case — now
     * flags every found unit for the worklist even though nobody intends to identify it inbound.
     * That is the mechanism working as designed rather than a misfire: `expect_resolution` is a
     * per-row decision, editable on the worklist, and the flag says "this row's identity is a
     * placeholder", which is exactly true. Suppressing it for out-only policies would mean guessing
     * intent from direction, which InventoryDetail::$expectResolution's docblock explicitly says is
     * not inferable.
     */
    private function inboundIsUnidentified(TrackingPolicy $policy): bool
    {
        return $policy->getMode() !== TrackingPolicy::MODE_NONE
            && !$policy->tracksLotsInbound()
            && !$policy->tracksSerialsInbound();
    }

    /**
     * Turns the submitted fields into movement lines — the only place a DetailKey is built.
     *
     * Four shapes, one per way a reason gets hold of its stock. Each throws \InvalidArgumentException
     * with a sentence rather than returning false, so submit() has one catch and the message reaches
     * the operator unchanged.
     */
    private function addLines(
        MovementRequest $movement,
        ProductCore $product,
        InventoryAdjustmentReason $reason,
        Request $request,
        int $quantity,
    ): void {
        $policy = $this->policies->policyFor($product);

        if ($reason->isReversal()) {
            $original = $this->em->find(InventoryMovement::class, $request->request->getInt('original_movement_id', 0));

            if (!$original instanceof InventoryMovement || $original->getProduct()->getId() !== $product->getId()) {
                throw new \InvalidArgumentException(
                    'Pick the entry that wrote the stock off. A reversal is that entry with its sides swapped, '
                    . 'which is what puts the units back in the bin, lot and serial they were taken from.'
                );
            }

            $this->reversals->addReversal($movement, $original, $quantity);

            return;
        }

        if ($reason->isInbound()) {
            $this->addInboundLines($movement, $product, $policy, $request, $quantity);

            return;
        }

        $fromStatus = (string) $reason->getFromStatus();
        $toStatus = (string) $reason->getToStatus();

        if (!$this->inventoryModes->isDimensional($product)) {
            // Hold / Release Hold are a pair, and unlike every other outbound reason here, Release
            // Hold finds its source GENERICALLY — (product, warehouse, quarantine) — rather than by
            // reference to the specific movement that created it (compare Reverse Write Off, which
            // reverses a named `original_movement_id` and is bin-correct on both ends for that
            // reason, dimensional or not). If Hold started keeping the bin it drew from and Release
            // Hold kept searching the one unspecified row, held stock from a named bin would become
            // unreleasable. So this pair alone keeps both ends on the one unspecified-location row a
            // simple product has always used for quarantine — losing no information a simple product
            // had before, and staying consistent with itself.
            if (!\in_array($reason->getCode(), [InventoryAdjustmentReason::CODE_HOLD, InventoryAdjustmentReason::CODE_RELEASE_HOLD], true)
                && $fromStatus === InventoryDetail::STATUS_AVAILABLE
            ) {
                // WHERE stock is is not a dimensional-only question (2026-09-18): a simple product's
                // detail rows are exactly as real as a dimensional one's now, bin included, so a
                // plain write-off draws from them the same way AutomaticSourcePicker already does
                // for a dimensional product with no tracked lot/serial — FEFO, then lowest bin sort
                // key — rather than always reaching for the unspecified row regardless of where the
                // stock actually is.
                $this->picker->addWithdrawal($movement, $product, $this->warehouseOr404($request->request->getInt('warehouse_id', 0)), $quantity, $toStatus);

                return;
            }

            $warehouse = $this->warehouseOr404($request->request->getInt('warehouse_id', 0));

            $movement->move(
                $product,
                $fromStatus !== '' ? new DetailKey($warehouse, null, null, null, $fromStatus) : null,
                $toStatus !== '' ? new DetailKey($warehouse, null, null, null, $toStatus) : null,
                $quantity,
            );

            return;
        }

        if (!$this->namesItsSource($product, $reason, $policy)) {
            // Nobody said which rows, so the picker says: earliest expiry, then lowest bin sort key.
            // It only ever reads `available` rows, which is why this branch is unreachable for a
            // reason whose from-status is anything else — namesItsSource() returns true for those.
            $this->picker->addWithdrawal($movement, $product, $this->warehouseOr404($request->request->getInt('warehouse_id', 0)), $quantity, $toStatus);

            return;
        }

        $this->addNamedSourceLines($movement, $product, $policy, $request, $quantity, $fromStatus, $toStatus);
    }

    /**
     * Stock entering the ledger: one line, or one line per serial.
     *
     * @throws \InvalidArgumentException when the serial rows do not account for the quantity
     */
    private function addInboundLines(
        MovementRequest $movement,
        ProductCore $product,
        TrackingPolicy $policy,
        Request $request,
        int $quantity,
    ): void {
        $warehouse = $this->warehouseOr404($request->request->getInt('warehouse_id', 0));
        $binId = $request->request->getInt('location_id', 0);
        $bin = $binId > 0 ? $this->em->find(WarehouseLocation::class, $binId) : null;
        $bin = $bin instanceof WarehouseLocation ? $bin : null;

        /** @var list<string> $serials */
        $serials = array_values(array_filter(array_map(
            static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
            $request->request->all('serials'),
        ), static fn (string $value): bool => $value !== ''));

        if ($policy->tracksSerialsInbound() && $serials !== []) {
            if (\count($serials) !== $quantity) {
                throw new \InvalidArgumentException(sprintf(
                    'A serial identifies one unit, so %d unit(s) needs %d serial row(s) — %d were entered. '
                    . 'Add the missing ones, or change the quantity to match what you have.',
                    $quantity,
                    $quantity,
                    \count($serials),
                ));
            }

            if (\count(array_unique($serials)) !== \count($serials)) {
                throw new \InvalidArgumentException('The same serial is entered twice. Each row is one physical unit.');
            }

            // One expiry box for the whole line, same as the quantity box it sits beside — these
            // units arrived together, so they carry the same date.
            $expiry = $this->inboundExpiry($policy, $request);

            foreach ($serials as $serial) {
                $movement->receive($product, new DetailKey($warehouse, $bin, null, $serial, InventoryDetail::STATUS_AVAILABLE, false, $expiry), 1);
            }

            return;
        }

        $movement->receive($product, $this->inboundKey($product, $warehouse, $bin, $policy, $request), $quantity);
    }

    /**
     * The destination row for stock entering the ledger, with the identity the policy asks for — or
     * the sentinel, flagged, when nobody supplied one.
     *
     * **Tracking never blocks (#573).** A blank lot on a lot-tracked product is not refused; it takes
     * `sentinel_in` and `expect_resolution` and lands on the tracking worklist, because a warehouse
     * mid-transition has real stock and no codes for any of it, and refusing those rows makes the
     * system unusable on exactly the day they need it. That is why this method never throws for a
     * missing identity and only ever throws for a missing EXPIRY, which is different: a policy with
     * `requires_expiry` is declaring that a batch without a date is not a batch, and the operator is
     * standing in front of the box with the date printed on it.
     */
    private function inboundKey(
        ProductCore $product,
        Warehouse $warehouse,
        ?WarehouseLocation $bin,
        TrackingPolicy $policy,
        Request $request,
    ): DetailKey {
        if ($policy->tracksLotsInbound()) {
            $lot = $this->chosenOrCreatedLot($product, $policy, $request);

            if ($lot instanceof InventoryLot) {
                return new DetailKey($warehouse, $bin, $lot, null, InventoryDetail::STATUS_AVAILABLE);
            }
        }

        if ($policy->getMode() === TrackingPolicy::MODE_NONE) {
            // Nothing is tracked, so nothing is a placeholder. Flagging this row would put an
            // untracked product on a worklist that can never be cleared — there is no identity for
            // anybody to come back and supply. Expiry is independent of all of that (#795): a
            // policy can require a date with no lot and no serial to hang it on.
            return new DetailKey($warehouse, $bin, null, null, InventoryDetail::STATUS_AVAILABLE, false, $this->inboundExpiry($policy, $request));
        }

        // Everything left is a placeholder identity: a tracked product whose policy does not capture
        // inbound, or a tracked one where the operator left the field blank. Same row either way —
        // the sentinel in the dimension the policy tracks, and the flag that says somebody is
        // expected to come back. A blank `sentinel_in` writes NULL, which is a real shape and is why
        // the flag rather than the value is what the worklist finds it by.
        $sentinel = $policy->getSentinelIn();

        return new DetailKey(
            $warehouse,
            $bin,
            $policy->getMode() === TrackingPolicy::MODE_LOT ? $this->sentinelLot($product, $sentinel) : null,
            $policy->getMode() === TrackingPolicy::MODE_SERIAL ? $sentinel : null,
            InventoryDetail::STATUS_AVAILABLE,
            true,
            // A lot mode row's expiry rides on the lot above — InventoryDetail::setExpiry() refuses
            // one here alongside it — so this only ever reaches the serial-mode row.
            $policy->getMode() === TrackingPolicy::MODE_SERIAL ? $this->inboundExpiry($policy, $request) : null,
        );
    }

    /**
     * The expiry typed for a row with no lot to carry it (#795) — `requires_expiry` "holds in every
     * mode and needs neither capture direction", per `TrackingPolicy`'s own docblock, so a
     * `none`-mode or `serial`-mode policy can demand a date the same way a `lot`-mode one always
     * could. `lot`-mode is excluded here: that policy's expiry belongs on the batch, via
     * `chosenOrCreatedLot()`/`sentinelLot()`, and this method is never asked for it.
     *
     * @throws \InvalidArgumentException when the policy requires an expiry and none was entered, or
     *                                   the one entered cannot be parsed
     */
    private function inboundExpiry(TrackingPolicy $policy, Request $request): ?\DateTimeImmutable
    {
        $raw = trim((string) $request->request->get('new_detail_expiry', ''));

        if ($raw === '') {
            if ($policy->requiresExpiry()) {
                throw new \InvalidArgumentException(sprintf(
                    'Policy "%s" requires an expiry date on every unit received, and none was entered. It is printed on the box.',
                    $policy->getName(),
                ));
            }

            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a date this can read. Use YYYY-MM-DD, which is what the date box sends.', $raw));
        }
    }

    /**
     * The lot the operator picked, or the one they typed — pick-or-CREATE, which is the half a
     * dropdown of existing lots cannot do.
     *
     * Returns null when they supplied neither, which inboundKey() reads as "unidentified" rather
     * than as an error.
     *
     * @throws \InvalidArgumentException when the policy requires an expiry and the new batch has
     *                                   none, or has one nobody can parse
     */
    private function chosenOrCreatedLot(ProductCore $product, TrackingPolicy $policy, Request $request): ?InventoryLot
    {
        $lotId = $request->request->getInt('lot_id', 0);
        if ($lotId > 0) {
            $lot = $this->em->find(InventoryLot::class, $lotId);

            if ($lot instanceof InventoryLot && $lot->getProduct()->getId() === $product->getId()) {
                return $lot;
            }
        }

        $code = trim((string) $request->request->get('new_lot_code', ''));
        if ($code === '') {
            return null;
        }

        $rawExpiry = trim((string) $request->request->get('new_lot_expiry', ''));
        $expiry = null;

        if ($rawExpiry !== '') {
            try {
                $expiry = new \DateTimeImmutable($rawExpiry);
            } catch (\Exception) {
                throw new \InvalidArgumentException(sprintf('"%s" is not a date this can read. Use YYYY-MM-DD, which is what the date box sends.', $rawExpiry));
            }
        }

        if ($expiry === null && $policy->requiresExpiry()) {
            throw new \InvalidArgumentException(sprintf(
                'Policy "%s" requires an expiry date on every batch, and batch %s has none. It is printed on the box; '
                . 'a batch code without a date does not identify a production run, which is the whole reason this '
                . 'product is lot-tracked.',
                $policy->getName(),
                $code,
            ));
        }

        // Matched on (product, code, expiry) rather than (product, code), the same rule
        // DimensionalImportProvider::lot() uses and for the same reason: vendors reuse batch codes
        // across production runs with different dates, so the code alone does not identify a batch
        // and matching on it would merge two genuinely different ones into a single row.
        foreach ($this->em->getRepository(InventoryLot::class)->findBy(['product' => $product, 'code' => $code]) as $candidate) {
            if ($candidate->getExpiry()?->format('Y-m-d') === $expiry?->format('Y-m-d')) {
                return $candidate;
            }
        }

        $lot = (new InventoryLot())
            ->setProduct($product)
            ->setCode($code)
            ->setExpiry($expiry)
            ->setReceivedAt(new \DateTimeImmutable());

        $this->em->persist($lot);
        // Flushed here because it is about to be a query parameter — StockMovementService resolves
        // the destination row by looking one up, and a lookup cannot see an entity that is only
        // persisted.
        $this->em->flush();

        return $lot;
    }

    /**
     * The `[PENDING]` batch row, reused rather than remade.
     *
     * Found by code and by DimensionalImportProvider's unknown-expiry date, so an adjustment's
     * unidentified stock lands on **the same lot row the import has been writing since #565** rather
     * than on a second one carrying the same code. Two sentinel lots for one product would split the
     * worklist's own subject in half and make "how much of this is still unidentified" a question
     * with two answers.
     *
     * Null when the policy leaves `sentinel_in` blank, which is a supported configuration: the row
     * then carries a NULL lot and is recognised by its `expect_resolution` flag alone.
     */
    private function sentinelLot(ProductCore $product, ?string $code): ?InventoryLot
    {
        if ($code === null || $code === '') {
            return null;
        }

        $expiry = new \DateTimeImmutable(DimensionalImportProvider::UNKNOWN_EXPIRY);

        foreach ($this->em->getRepository(InventoryLot::class)->findBy(['product' => $product, 'code' => $code], ['id' => 'ASC']) as $candidate) {
            if ($candidate->getExpiry()?->format('Y-m-d') === $expiry->format('Y-m-d')) {
                return $candidate;
            }
        }

        $lot = (new InventoryLot())->setProduct($product)->setCode($code)->setExpiry($expiry);
        $this->em->persist($lot);
        $this->em->flush();

        return $lot;
    }

    /**
     * Stock leaving a status the operator named row by row.
     *
     * Serialised products submit one row per unit and every line is quantity 1 — the row count IS
     * the quantity, and StockMovementService::assertSerialRowsStayAtOne() would refuse anything else
     * anyway. Everything else submits one row and takes the whole quantity off it, which is why a
     * write-off spanning two lots is two entries: what was damaged in lot A and what was damaged in
     * lot B are two facts, and a screen that folded them into one would lose which was which.
     *
     * @throws \InvalidArgumentException when the rows do not account for the quantity, or name goods
     *                                   that are not this product's, or are not in the status the
     *                                   reason takes stock out of
     */
    private function addNamedSourceLines(
        MovementRequest $movement,
        ProductCore $product,
        TrackingPolicy $policy,
        Request $request,
        int $quantity,
        string $fromStatus,
        string $toStatus,
    ): void {
        if ($policy->getMode() === TrackingPolicy::MODE_SERIAL) {
            /** @var list<int> $ids */
            $ids = array_values(array_filter(array_map(
                static fn (mixed $value): int => is_scalar($value) ? (int) $value : 0,
                $request->request->all('source_detail_ids'),
            )));

            if (\count($ids) !== $quantity) {
                throw new \InvalidArgumentException(sprintf(
                    'A serial identifies one unit, so %d unit(s) needs %d row(s) — %d were chosen.',
                    $quantity,
                    $quantity,
                    \count($ids),
                ));
            }

            if (\count(array_unique($ids)) !== \count($ids)) {
                throw new \InvalidArgumentException('The same serialised row is chosen twice. Each row is one physical unit.');
            }

            foreach ($ids as $id) {
                $row = $this->sourceRowOrFail($product, $id, $fromStatus);
                $movement->move($product, $this->keyFor($row), $this->keyFor($row)->forStatus($toStatus), 1);
            }

            return;
        }

        $row = $this->sourceRowOrFail($product, $request->request->getInt('source_detail_id', 0), $fromStatus);
        $movement->move($product, $this->keyFor($row), $this->keyFor($row)->forStatus($toStatus), $quantity);
    }

    /**
     * One named source row, checked to be this product's and in the status the reason takes stock
     * out of.
     *
     * Both tests exist because the id arrives in a POST body. A row belonging to another product
     * would move somebody else's stock under this product's reason; a row in another status would
     * let a hand-built POST take units out of `sold` by naming a sold row's id, which is the guard
     * #581 spent an issue installing and would be exactly the hole to reopen here.
     */
    private function sourceRowOrFail(ProductCore $product, int $id, string $status): InventoryDetail
    {
        $row = $id > 0 ? $this->em->find(InventoryDetail::class, $id) : null;

        if (!$row instanceof InventoryDetail || $row->getProduct()->getId() !== $product->getId() || $row->getStatus() !== $status) {
            throw new \InvalidArgumentException(sprintf(
                'Choose which %s stock this comes off. The row named is not this product\'s %s stock.',
                str_replace('_', ' ', $status),
                str_replace('_', ' ', $status),
            ));
        }

        return $row;
    }

    /** A detail row read back as the key that resolves to exactly it — same five identity fields. */
    private function keyFor(InventoryDetail $row): DetailKey
    {
        return new DetailKey($row->getWarehouse(), $row->getLocation(), $row->getLot(), $row->getSerial(), $row->getStatus());
    }

    /**
     * When it happened, which is not always when it was typed.
     *
     * Stock found on Friday and entered on Monday belongs on Friday, and a screen that could not say
     * so would push people into writing the real date in the note where nothing can read it. Blank
     * or unparseable reads as now rather than failing: a wrong date is worse than a late one, and
     * the alternative is refusing an otherwise complete adjustment over a field the operator did not
     * fill in.
     */
    private function occurredAt(Request $request): ?\DateTimeImmutable
    {
        $raw = trim((string) $request->request->get('occurred_at', ''));
        if ($raw === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    /** Back to the form with the product and the reason still chosen, so nothing has to be re-picked. */
    private function back(ProductCore $product, ?InventoryAdjustmentReason $reason): Response
    {
        $parameters = ['product' => $product->getId()];
        if ($reason instanceof InventoryAdjustmentReason) {
            $parameters['reason'] = $reason->getCode();
        }

        return $this->redirectToRoute('admin_bundle_inventory_depth_adjust', $parameters);
    }

    /**
     * Count a bin, enter what was actually found, and have the differences written as adjustment
     * movements with a count reason.
     *
     * A count that agrees writes nothing at all — a movement group recording "nothing moved" is a
     * record of nothing, and it would bury the counts that did find something.
     */
    #[Route('/cycle-count', name: 'admin_bundle_inventory_depth_cycle_count', methods: ['GET'])]
    public function cycleCountForm(Request $request): Response
    {
        $this->denyIfInactive();

        $binId = $request->query->getInt('bin', 0);
        $bin = $binId > 0 ? $this->em->find(WarehouseLocation::class, $binId) : null;

        $rows = [];
        if ($bin instanceof WarehouseLocation) {
            $rows = $this->em->getRepository(InventoryDetail::class)->findBy(
                ['location' => $bin, 'status' => InventoryDetail::STATUS_AVAILABLE],
                ['id' => 'ASC'],
            );
        }

        return $this->render('@InventoryDepth/cycle_count.html.twig', [
            'bin' => $bin instanceof WarehouseLocation ? $bin : null,
            'bins' => $this->em->getRepository(WarehouseLocation::class)->findBy(['status' => 'Active'], ['sortKey' => 'ASC', 'code' => 'ASC']),
            'rows' => $rows,
        ]);
    }

    #[Route('/cycle-count', name: 'admin_bundle_inventory_depth_cycle_count_submit', methods: ['POST'])]
    public function cycleCountSubmit(Request $request): Response
    {
        $this->denyIfInactive();

        $bin = $this->em->find(WarehouseLocation::class, $request->request->getInt('bin_id', 0));
        if (!$bin instanceof WarehouseLocation) {
            $this->addFlash('error', 'No such bin.');

            return $this->redirectToRoute('admin_bundle_inventory_depth_cycle_count');
        }

        /** @var array<string, mixed> $counted */
        $counted = $request->request->all('counted');

        $movement = MovementRequest::of(
            InventoryMovementGroup::TYPE_ADJUSTMENT,
            $this->operationKey($request),
            sprintf('Cycle count of %s', $bin->getCode()),
            $this->actor(),
            $this->nullable((string) $request->request->get('reference', '')),
        );

        $surplus = 0;
        $shortfall = 0;
        $skipped = [];

        foreach ($counted as $detailId => $rawFound) {
            $detail = $this->em->find(InventoryDetail::class, (int) $detailId);
            if (!$detail instanceof InventoryDetail || $detail->getLocation()?->getId() !== $bin->getId()) {
                continue;
            }

            // A bin can hold rows for a product since switched back to simple — the switch leaves
            // its rows in place deliberately. StockMovementService refuses those, and one of them
            // would take the whole count sheet down with it. Counted, reported, and left alone.
            // Through the resolver, never the raw column: with InventoryDepthBundle Inactive a
                // stored `dimensional` must read as simple everywhere (#566).
            if (!$this->inventoryModes->isDimensional($detail->getProduct())) {
                $skipped[] = $detail->getProduct()->getSku() ?: ('#' . ($detail->getProduct()->getId() ?? '?'));
                continue;
            }

            $found = max(0, (int) $rawFound);
            // getQuantity() is a decimal STRING ('5.0000'); subtracting it from the int $found
            // coerces the whole expression to a FLOAT, so an agreeing count produced 0.0, not 0 —
            // and 0.0 === 0 is false in PHP, so the "nothing changed" guard below never matched.
            // The count that then "moved" was zero units, which MovementRequest correctly refuses
            // as not a positive quantity (#782). Cast to int here, once, so $difference stays the
            // int it always meant to be.
            $difference = $found - (int) $detail->getQuantity();

            if ($difference === 0) {
                continue;
            }

            $key = new DetailKey(
                $detail->getWarehouse(),
                $bin,
                $detail->getLot(),
                $detail->getSerial(),
                InventoryDetail::STATUS_AVAILABLE,
            );

            if ($difference > 0) {
                // More on the shelf than the system thought: it came from outside the system, which
                // is the only honest thing a count can say about it.
                $movement->receive($detail->getProduct(), $key, $difference);
                $surplus += $difference;
            } else {
                $movement->remove($detail->getProduct(), $key, -$difference);
                $shortfall += -$difference;
            }
        }

        if ($movement->isEmpty()) {
            $this->addFlash('success', sprintf('%s counted and everything agreed. Nothing was written.', $bin->getCode()));

            return $this->redirectToRoute('admin_bundle_inventory_depth_cycle_count', ['bin' => $bin->getId()]);
        }

        try {
            $this->movements->apply($movement);
        } catch (InsufficientStockException|\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_bundle_inventory_depth_cycle_count', ['bin' => $bin->getId()]);
        }

        $this->addFlash('success', sprintf(
            '%s counted: %d unit(s) found that the system did not have, %d unit(s) missing. Written as adjustment movements with the count as the reason.',
            $bin->getCode(),
            $surplus,
            $shortfall,
        ));

        if ($skipped !== []) {
            $this->addFlash('info', sprintf(
                'Skipped %s — back on simple inventory, so its quantity is typed rather than counted here.',
                implode(', ', array_unique($skipped)),
            ));
        }

        return $this->redirectToRoute('admin_bundle_inventory_depth_cycle_count', ['bin' => $bin->getId()]);
    }

    /**
     * Every adjustable product, dimensional or simple (2026-09-15, the simple-inventory bucket
     * parity plan) — this screen's whole point now is one reason-driven flow for both, with the
     * bin/lot/serial questions skipped for a product that has no InventoryDetail row to ask them
     * about, per `addLines()`'s own dimensional check.
     *
     * @return list<ProductCore>
     */
    private function adjustableProducts(): array
    {
        /** @var list<ProductCore> $rows */
        $rows = $this->em->getRepository(ProductCore::class)->findBy(
            ['deleted' => false],
            ['sku' => 'ASC'],
        );

        return $rows;
    }

    private function nullable(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
