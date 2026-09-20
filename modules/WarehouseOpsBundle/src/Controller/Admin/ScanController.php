<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Controller\Admin;

use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Repository\BundleStatusRepository;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\InsufficientStockException;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use InventoryDepthBundle\Repository\InventoryDetailRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WarehouseOpsBundle\Entity\TransferOrder;
use WarehouseOpsBundle\Scan\ScanMatch;
use WarehouseOpsBundle\Scan\ScanResolver;
use App\Service\Inventory\InventoryModeResolver;

/**
 * The scan console (#552): a phone-shaped, scan-first screen for a keyboard-wedge scanner.
 *
 * ## It is a fast path into the same service, never a second write path
 *
 * This is the rule the whole issue hangs on. If a scanner wrote to `inventory_detail` by its own
 * route there would be two implementations of "move stock" and they would drift — different
 * validation, different reservation handling, different audit. So `apply()` below builds a
 * MovementRequest and hands it to StockMovementService, which is the same object the desktop
 * adjustment screen calls with the same argument shape. A scan and a typed adjustment produce
 * byte-identical movement rows, and there is a test that asserts exactly that.
 *
 * ## Scan where → scan what → confirm how many
 *
 * Bin first, always, because knowing where you are is what makes everything after it unambiguous.
 * Each step is a form whose only field is focused and whose submit is the scanner pressing Enter, and
 * the state between steps lives in URL GET parameters — so a mis-scan is the back button, a dropped
 * connection loses nothing but the current step, and a supervisor can be sent the exact URL.
 *
 * ## Three things that matter more than they look
 *
 *  - **The idempotency key.** Warehouse wi-fi is bad. A submit that times out and gets retried must
 *    not move stock twice, so the confirm form carries an `op_id` minted when the form is rendered;
 *    resubmitting it finds the existing group and applies nothing. A device that mints its own key
 *    can send it in the same field — see AbstractWarehouseOpsController::operationKey().
 *  - **A failed scan is loud.** Unknown barcode, wrong warehouse, ambiguous code, quantity exceeding
 *    what is there — every one blocks with a sentence the person can act on. Silent failure is how
 *    stock goes missing.
 *  - **No free-text quantity where a scan will do.** A serial scan resolves to one specific unit and
 *    fixes the quantity at one; scanning ten serials is ten scans, not typing "10". That is the whole
 *    point of serial tracking.
 *
 * ## Offline
 *
 * It blocks. There is no local queue and no background sync: queuing is more work than it looks and
 * needs the idempotency key to be watertight across devices, and at this scale a scanner that says
 * "no connection, nothing was recorded" is honest where one that silently drops a scan is not. That
 * is a deliberate choice, not an omission — it must block, and it must say so.
 */
#[Route('/admin/bundles/warehouse-ops/scan')]
final class ScanController extends AbstractWarehouseOpsController
{
    /** The generic bin-to-bin flows, and the movement group type each one records. */
    private const FLOWS = [
        'receive' => InventoryMovementGroup::TYPE_RECEIPT,
        'putaway' => InventoryMovementGroup::TYPE_PUTAWAY,
        'pick' => InventoryMovementGroup::TYPE_PICK,
        'move' => InventoryMovementGroup::TYPE_MOVE,
        'count' => InventoryMovementGroup::TYPE_ADJUSTMENT,
    ];

    public function __construct(
        EntityManagerInterface $em,
        BundleStatusRepository $bundleStatusRepo,
        private readonly ScanResolver $resolver,
        private readonly StockMovementService $movements,
        private readonly InventoryDetailRepository $details,
        private readonly InventoryModeResolver $inventoryModes,
    ) {
        parent::__construct($em, $bundleStatusRepo);
    }

    /**
     * One screen for every step. Which step it is falls out of what is already in the URL, so there
     * is no wizard state anywhere else and nothing to expire.
     */
    #[Route('', name: 'admin_bundle_warehouse_ops_scan', methods: ['GET'])]
    public function console(Request $request): Response
    {
        $this->denyIfInactive();

        $flow = (string) $request->query->get('flow', '');
        $warehouse = $this->em->find(Warehouse::class, $request->query->getInt('warehouse', 0));
        $bin = $this->binOrNull($request->query->getInt('bin', 0));
        $target = $this->binOrNull($request->query->getInt('target', 0));
        $product = $this->em->find(ProductCore::class, $request->query->getInt('product', 0));
        $lot = $this->em->find(InventoryLot::class, $request->query->getInt('lot', 0));
        $serial = $this->nullable((string) $request->query->get('serial', ''));

        $step = match (true) {
            !$warehouse instanceof Warehouse, !isset(self::FLOWS[$flow]) => 'start',
            !$bin instanceof WarehouseLocation => 'bin',
            !$product instanceof ProductCore => 'item',
            $this->needsTarget($flow) && !$target instanceof WarehouseLocation => 'target',
            default => 'quantity',
        };

        return $this->render('@WarehouseOps/scan.html.twig', [
            'step' => $step,
            'flow' => isset(self::FLOWS[$flow]) ? $flow : '',
            'flows' => array_keys(self::FLOWS),
            'warehouses' => $this->activeWarehouses(),
            'warehouse' => $warehouse instanceof Warehouse ? $warehouse : null,
            'bin' => $bin,
            'target' => $target,
            'product' => $product instanceof ProductCore ? $product : null,
            'lot' => $lot instanceof InventoryLot ? $lot : null,
            'serial' => $serial,
            'onHand' => $this->onHand($product, $bin, $lot, $serial),
            // Minted per render, so a resubmitted form applies once and a fresh scan applies again.
            'operationId' => bin2hex(random_bytes(12)),
            'draftTransfers' => $this->em->getRepository(TransferOrder::class)->findBy(
                ['status' => [TransferOrder::STATUS_DRAFT, TransferOrder::STATUS_DISPATCHED]],
                ['id' => 'DESC'],
                20,
            ),
        ]);
    }

    /**
     * One scan. Resolves the barcode and puts what it found back into the URL.
     *
     * The refusals are the interesting part, and each one names the thing it could not accept:
     * an unknown code, a bin in the wrong building, a code that means two things at once.
     */
    #[Route('/scan', name: 'admin_bundle_warehouse_ops_scan_step', methods: ['POST'])]
    public function scan(Request $request): Response
    {
        $this->denyIfInactive();

        $state = $this->state($request);
        $warehouse = $this->em->find(Warehouse::class, (int) ($state['warehouse'] ?? 0));
        $code = trim((string) $request->request->get('code', ''));
        $expect = (string) $request->request->get('expect', 'item');

        if ($code === '') {
            $this->addFlash('error', 'Nothing was scanned.');

            return $this->redirectToRoute('admin_bundle_warehouse_ops_scan', $state);
        }

        $match = $this->resolver->resolve($code, $warehouse instanceof Warehouse ? $warehouse : null);

        if ($match === null) {
            $collision = $this->resolver->describeCollision($code, $warehouse instanceof Warehouse ? $warehouse : null);
            $this->addFlash('error', $collision ?? sprintf(
                '%s is not a bin, a SKU, a lot or a live serial in %s. Nothing was recorded.',
                $code,
                $warehouse?->getName() ?? 'this warehouse',
            ));

            return $this->redirectToRoute('admin_bundle_warehouse_ops_scan', $state);
        }

        if ($expect === 'bin' && $match->kind !== ScanMatch::KIND_BIN) {
            $this->addFlash('error', sprintf('%s is %s, not a bin. Scan where you are first.', $code, $match->kind));

            return $this->redirectToRoute('admin_bundle_warehouse_ops_scan', $state);
        }

        switch ($match->kind) {
            case ScanMatch::KIND_BIN:
                /** @var WarehouseLocation $bin */
                $bin = $match->entity;
                $state[$expect === 'target' ? 'target' : 'bin'] = $bin->getId();
                break;

            case ScanMatch::KIND_PRODUCT:
                /** @var ProductCore $product */
                $product = $match->entity;
                // Through the resolver, never the raw column: with InventoryDepthBundle Inactive a
                // stored `dimensional` must read as simple everywhere (#566).
                if (!$this->inventoryModes->isDimensional($product)) {
                    $this->addFlash('error', sprintf(
                        '%s is on simple inventory. Its quantity is a number an admin types, and this screen must not touch it.',
                        $product->getSku(),
                    ));

                    return $this->redirectToRoute('admin_bundle_warehouse_ops_scan', $state);
                }
                $state['product'] = $product->getId();
                break;

            case ScanMatch::KIND_LOT:
                /** @var InventoryLot $lot */
                $lot = $match->entity;
                // A lot scan settles the product too — a batch belongs to exactly one — which is why
                // scanning the lot label at receipt is one scan rather than two.
                $state['lot'] = $lot->getId();
                $state['product'] = $lot->getProduct()->getId();
                break;

            case ScanMatch::KIND_SERIAL:
                /** @var InventoryDetail $row */
                $row = $match->entity;
                $state['product'] = $row->getProduct()->getId();
                $state['serial'] = (string) $row->getSerial();
                $state['lot'] = $row->getLot()?->getId();
                // The serial IS the bin: a live serial has exactly one row, so where it is is not a
                // question worth asking the person holding it.
                $state['bin'] ??= $row->getLocation()?->getId();
                break;
        }

        $this->addFlash('info', sprintf('Scanned %s.', $match->label));

        return $this->redirectToRoute('admin_bundle_warehouse_ops_scan', array_filter($state, static fn ($v): bool => $v !== null && $v !== ''));
    }

    /**
     * Confirms the quantity and applies the movement — the only write in this controller.
     *
     * Reads as a translation from "what the person scanned" into a MovementRequest, and then one call.
     * That shape is the point: everything above it is user interface, and everything below it is the
     * same code a manual adjustment runs.
     */
    #[Route('/apply', name: 'admin_bundle_warehouse_ops_scan_apply', methods: ['POST'])]
    public function apply(Request $request): Response
    {
        $this->denyIfInactive();

        $state = $this->state($request);
        $flow = (string) ($state['flow'] ?? '');
        $warehouse = $this->em->find(Warehouse::class, (int) ($state['warehouse'] ?? 0));
        $bin = $this->binOrNull((int) ($state['bin'] ?? 0));
        $target = $this->binOrNull((int) ($state['target'] ?? 0));
        $product = $this->em->find(ProductCore::class, (int) ($state['product'] ?? 0));
        $lot = $this->em->find(InventoryLot::class, (int) ($state['lot'] ?? 0));
        $serial = $this->nullable((string) ($state['serial'] ?? ''));
        $quantity = $request->request->getInt('quantity', 0);

        if (!isset(self::FLOWS[$flow]) || !$warehouse instanceof Warehouse || !$bin instanceof WarehouseLocation || !$product instanceof ProductCore) {
            $this->addFlash('error', 'That scan sequence is incomplete. Start again from the bin.');

            return $this->redirectToRoute('admin_bundle_warehouse_ops_scan');
        }

        // A serial is one physical unit. Offering a quantity box beside it invites "10", which is
        // the one thing serial tracking exists to prevent.
        if ($serial !== null) {
            $quantity = 1;
        }

        if ($quantity <= 0 && $flow !== 'count') {
            $this->addFlash('error', 'A movement needs a positive quantity. Nothing was recorded.');

            return $this->redirectToRoute('admin_bundle_warehouse_ops_scan', $state);
        }

        $here = new DetailKey($warehouse, $bin, $lot instanceof InventoryLot ? $lot : null, $serial, InventoryDetail::STATUS_AVAILABLE);

        $movement = MovementRequest::of(
            self::FLOWS[$flow],
            $this->operationKey($request),
            $this->reason($flow, $bin, $target),
            $this->actor(),
            $this->nullable((string) $request->request->get('reference', '')),
        );

        try {
            match ($flow) {
                // Stock entering the system: no from-side at all.
                'receive' => $movement->receive($product, $here, $quantity),
                // Bin to bin, status unchanged on both sides — so the product total recomputes to
                // the identical number, which is what a putaway or a move means.
                'putaway', 'move', 'pick' => $movement->move(
                    $product,
                    $here,
                    new DetailKey($warehouse, $target, $lot instanceof InventoryLot ? $lot : null, $serial, InventoryDetail::STATUS_AVAILABLE),
                    $quantity,
                ),
                'count' => $this->count($movement, $product, $here, $quantity),
                default => null,
            };

            if ($movement->isEmpty()) {
                $this->addFlash('success', sprintf('%s counted and it agreed. Nothing was written.', $bin->getCode()));

                return $this->redirectToRoute('admin_bundle_warehouse_ops_scan', ['flow' => $flow, 'warehouse' => $warehouse->getId(), 'bin' => $bin->getId()]);
            }

            $group = $this->movements->apply($movement);
        } catch (InsufficientStockException|\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_bundle_warehouse_ops_scan', $state);
        }

        $this->addFlash('success', sprintf(
            '%s recorded: %d unit(s) of %s. Movement group #%d.',
            ucfirst($flow),
            $quantity,
            $product->getSku() ?: $product->getName(),
            $group->getId() ?? 0,
        ));

        // Back to the bin step with the bin kept, because the next scan is almost always another
        // item in the same bin. Keeping the product too would be worse: it invites confirming a
        // quantity against whatever was scanned last.
        return $this->redirectToRoute('admin_bundle_warehouse_ops_scan', [
            'flow' => $flow,
            'warehouse' => $warehouse->getId(),
            'bin' => $bin->getId(),
            'target' => $target?->getId(),
        ]);
    }

    /**
     * A count writes the DIFFERENCE, not the count.
     *
     * More on the shelf than the system thought came from outside the system, which is the only
     * honest thing a count can say about it; less is stock that is gone. A count that agrees writes
     * nothing at all — a movement group recording "nothing moved" is a record of nothing, and it
     * would bury the counts that did find something.
     */
    private function count(MovementRequest $movement, ProductCore $product, DetailKey $here, int $counted): void
    {
        $onHand = $this->details->findExisting(
            $product,
            $here->warehouse,
            $here->location,
            $here->lot,
            $here->serial,
            $here->status,
        )?->getQuantity() ?? 0;

        $difference = $counted - $onHand;

        if ($difference > 0) {
            $movement->receive($product, $here, $difference);
        } elseif ($difference < 0) {
            $movement->remove($product, $here, -$difference);
        }
    }

    private function needsTarget(string $flow): bool
    {
        return \in_array($flow, ['putaway', 'move', 'pick'], true);
    }

    private function reason(string $flow, WarehouseLocation $bin, ?WarehouseLocation $target): string
    {
        return match ($flow) {
            'receive' => sprintf('Scanned receipt into %s', $bin->getCode()),
            'putaway' => sprintf('Scanned put-away %s to %s', $bin->getCode(), $target?->getCode() ?? '?'),
            'pick' => sprintf('Scanned pick from %s to %s', $bin->getCode(), $target?->getCode() ?? '?'),
            'move' => sprintf('Scanned move %s to %s', $bin->getCode(), $target?->getCode() ?? '?'),
            default => sprintf('Scanned count of %s', $bin->getCode()),
        };
    }

    /**
     * How much is in front of the person right now, so the quantity step has something to check
     * against.
     *
     * A decimal string, not an int (#785): InventoryDetail::getQuantity() is a decimal string —
     * this app's canonical quantity representation everywhere else — and this method's own
     * `: int` return type threw a TypeError the moment a real row existed to return, under
     * declare(strict_types=1). It only ever "worked" for the no-stock case, which returns the
     * literal 0 rather than a fetched quantity.
     */
    private function onHand(?object $product, ?WarehouseLocation $bin, ?object $lot, ?string $serial): string
    {
        if (!$product instanceof ProductCore || !$bin instanceof WarehouseLocation) {
            return '0';
        }

        $row = $this->details->findExisting(
            $product,
            $bin->getWarehouse(),
            $bin,
            $lot instanceof InventoryLot ? $lot : null,
            $serial,
            InventoryDetail::STATUS_AVAILABLE,
        );

        return $row?->getQuantity() ?? '0';
    }

    /**
     * The wizard state, taken from whichever side of the request carries it.
     *
     * @return array<string, mixed>
     */
    private function state(Request $request): array
    {
        $state = [];
        foreach (['flow', 'warehouse', 'bin', 'target', 'product', 'lot', 'serial'] as $key) {
            $value = $request->request->get($key, $request->query->get($key));
            if (\is_scalar($value) && trim((string) $value) !== '') {
                $state[$key] = trim((string) $value);
            }
        }

        return $state;
    }
}
