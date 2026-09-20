<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Controller\Admin;

use App\Entity\Warehouse;
use App\Repository\BundleStatusRepository;
use App\Service\QuantityScale;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\InsufficientStockException;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WarehouseOpsBundle\Entity\TransferOrder;
use WarehouseOpsBundle\Entity\TransferOrderLine;
use WarehouseOpsBundle\Repository\TransferOrderRepository;
use WarehouseOpsBundle\Transfer\TransferException;
use WarehouseOpsBundle\Transfer\TransferOrderService;

/**
 * "Move selected" off the Stock screen (#787 steps 2-3). The app, not the operator, decides which
 * kind of move a submission is (#787 §5): destination warehouse = source warehouse is a bin move —
 * no document, no bucket change, built directly on StockMovementService, exactly like the scan
 * console's own bin-to-bin path. A different destination warehouse is a transfer: a real
 * TransferOrder is created, naming each selected row's own exact lot and serial (no suggestLot()
 * guessing — the row IS the choice), then dispatched and, unless "track in transit" was chosen,
 * received in the same request.
 *
 * Three POSTs, none needing JavaScript. /move renders one page: every row to move, a quantity per
 * row, and a single `destination` control listing every active warehouse's bins (grouped by
 * warehouse, `bin:<id>`) plus a "put away later" option per warehouse (`warehouse:<id>`) — one
 * choice that decides both the destination warehouse and bin at once, since a bin always implies
 * its own warehouse and there is nothing here that depends on an earlier answer on the same page.
 * The travel-mode radio (send and receive now / track in transit) is shown unconditionally too;
 * /preview and /confirm ignore it for a bin move, where it does not apply. /move/preview validates
 * and shows what will change, writing nothing. /move/confirm performs it. Re-validated at every
 * step rather than trusted from the one before, because the time between "preview" and "confirm" is
 * exactly when a warehouse worker elsewhere in the building can move the same stock.
 */
#[Route('/admin/bundles/warehouse-ops/move')]
final class MoveController extends AbstractWarehouseOpsController
{
    private const TRAVEL_SEND_AND_RECEIVE = 'send_and_receive';
    private const TRAVEL_TRACK_IN_TRANSIT = 'track_in_transit';

    public function __construct(
        EntityManagerInterface $em,
        BundleStatusRepository $bundleStatusRepo,
        private readonly StockMovementService $movements,
        private readonly TransferOrderService $transfers,
        private readonly TransferOrderRepository $transferOrders,
    ) {
        parent::__construct($em, $bundleStatusRepo);
    }

    #[Route('', name: 'admin_bundle_warehouse_ops_move', methods: ['POST'])]
    public function start(Request $request): Response
    {
        $this->denyIfInactive();

        [$rows, $error] = $this->rowsFromRequest($request);
        if ($error !== null) {
            $this->addFlash('error', $error);

            return $this->redirectToRoute('admin_bundle_warehouse_ops_stock');
        }

        $sourceWarehouse = $rows[0]->getWarehouse();

        // Every active warehouse's bins are already on hand — nothing here depends on a choice
        // made earlier on the page, so there is no reason to ask "which warehouse" before showing
        // "which bin": one destination control, grouped by warehouse, decides both at once. The
        // travel-mode choice is shown unconditionally too (only relevant once /move/preview finds
        // the chosen destination is a different warehouse — a bin move ignores it).
        $binsByWarehouse = [];
        foreach ($this->activeWarehouses() as $w) {
            $bins = $this->em->getRepository(WarehouseLocation::class)->findBy(
                ['warehouse' => $w, 'status' => 'Active'],
                ['sortKey' => 'ASC', 'code' => 'ASC'],
            );
            $binsByWarehouse[] = ['warehouse' => $w, 'bins' => $bins, 'isSource' => $w->getId() === $sourceWarehouse->getId()];
        }

        return $this->render('@WarehouseOps/move.html.twig', [
            'rows' => $rows,
            'warehouse' => $sourceWarehouse,
            'binsByWarehouse' => $binsByWarehouse,
        ]);
    }

    #[Route('/preview', name: 'admin_bundle_warehouse_ops_move_preview', methods: ['POST'])]
    public function preview(Request $request): Response
    {
        $this->denyIfInactive();

        $plan = $this->resolvePlan($request);
        if ($plan['error'] !== null) {
            $this->addFlash('error', $plan['error']);

            return $this->redirectToRoute('admin_bundle_warehouse_ops_stock');
        }

        $opId = bin2hex(random_bytes(16));

        if (!$plan['isTransfer']) {
            $destinationBefore = $this->binTotal($plan['toBin']);
            $movingTotal = array_reduce($plan['lines'], static fn (string $sum, array $line): string => QuantityScale::add($sum, $line['qty']), '0');

            return $this->render('@WarehouseOps/move_preview.html.twig', [
                'isTransfer' => false,
                'lines' => $plan['lines'],
                'warehouse' => $plan['sourceWarehouse'],
                'toWarehouse' => $plan['sourceWarehouse'],
                'toBin' => $plan['toBin'],
                'destinationBefore' => $destinationBefore,
                'destinationAfter' => QuantityScale::add($destinationBefore, $movingTotal),
                'opId' => $opId,
            ]);
        }

        return $this->render('@WarehouseOps/move_preview.html.twig', [
            'isTransfer' => true,
            'lines' => $plan['lines'],
            'sourceWarehouse' => $plan['sourceWarehouse'],
            'toWarehouse' => $plan['toWarehouse'],
            'toBin' => $plan['toBin'],
            'travelMode' => $plan['travelMode'],
            'travelModeLabel' => $plan['travelMode'] === self::TRAVEL_TRACK_IN_TRANSIT ? 'Track in transit' : 'Send and receive now',
            'opId' => $opId,
        ]);
    }

    #[Route('/confirm', name: 'admin_bundle_warehouse_ops_move_confirm', methods: ['POST'])]
    public function confirm(Request $request): Response
    {
        $this->denyIfInactive();

        $plan = $this->resolvePlan($request);
        if ($plan['error'] !== null) {
            $this->addFlash('error', $plan['error']);

            return $this->redirectToRoute('admin_bundle_warehouse_ops_stock');
        }

        $opId = trim((string) $request->request->get('op_id', ''));

        if (!$plan['isTransfer']) {
            return $this->confirmBinMove($plan, $opId);
        }

        return $this->confirmTransfer($plan, $opId);
    }

    private function confirmBinMove(array $plan, string $opId): Response
    {
        $warehouse = $plan['sourceWarehouse'];
        $toBin = $plan['toBin'];

        $movement = MovementRequest::of(InventoryMovementGroup::TYPE_MOVE, $opId, null, $this->actor());
        foreach ($plan['lines'] as $line) {
            /** @var InventoryDetail $detail */
            $detail = $line['detail'];
            $from = new DetailKey($warehouse, $detail->getLocation(), $detail->getLot(), $detail->getSerial(), InventoryDetail::STATUS_AVAILABLE);
            $to = new DetailKey($warehouse, $toBin, $detail->getLot(), $detail->getSerial(), InventoryDetail::STATUS_AVAILABLE);
            $movement->move($detail->getProduct(), $from, $to, $line['qty']);
        }

        try {
            $this->movements->apply($movement);
        } catch (InsufficientStockException|\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_bundle_warehouse_ops_stock');
        }

        $this->addFlash('success', sprintf('Moved %d row(s) to %s.', \count($plan['lines']), $toBin->getCode()));

        return $this->redirectToRoute('admin_bundle_warehouse_ops_stock');
    }

    /**
     * Creates the TransferOrder, dispatches it, and — unless "track in transit" was chosen —
     * receives it too, all inside one DB transaction (#787 §9.2's own open question: dispatch()/
     * receive() are each their own committed unit of work, so a bare "call both" could dispatch and
     * then fail to receive, leaving stock in transit nobody asked to leave that way). Doctrine's
     * `use_savepoints: true` (config/packages/doctrine.yaml) makes the inner wrapInTransaction()
     * calls StockMovementService/TransferOrderService already make into real SAVEPOINTs nested
     * inside this one, so a receive() failure rolls the dispatch back too rather than leaving it
     * half done — verified directly, not just inferred: a test forces receive() to fail after a
     * real dispatch() and asserts nothing landed on disk, not even the TransferOrder itself.
     */
    private function confirmTransfer(array $plan, string $opId): Response
    {
        $actor = $this->actor();
        $sourceWarehouse = $plan['sourceWarehouse'];
        $toWarehouse = $plan['toWarehouse'];
        $toBin = $plan['toBin'];
        $travelMode = $plan['travelMode'];

        try {
            $transfer = $this->em->wrapInTransaction(function () use ($plan, $opId, $actor, $sourceWarehouse, $toWarehouse, $toBin, $travelMode): TransferOrder {
                $transfer = (new TransferOrder())
                    ->setNumber($this->transferOrders->nextNumber())
                    ->setFromWarehouse($sourceWarehouse)
                    ->setToWarehouse($toWarehouse)
                    ->setStatus(TransferOrder::STATUS_DRAFT)
                    ->setNotes('Created from Warehouse \u{2192} Stock\'s Move selected.');
                $this->em->persist($transfer);

                foreach ($plan['lines'] as $line) {
                    /** @var InventoryDetail $detail */
                    $detail = $line['detail'];
                    $transferLine = (new TransferOrderLine())
                        ->setProduct($detail->getProduct())
                        ->setLot($detail->getLot())
                        ->setSerial($detail->getSerial())
                        ->setQuantityRequested($line['qty']);
                    $transfer->addLine($transferLine);
                }

                $this->em->flush();

                $this->transfers->dispatch($transfer, $opId, $actor);

                if ($travelMode === self::TRAVEL_SEND_AND_RECEIVE) {
                    $received = [];
                    $intoBins = [];
                    foreach ($transfer->getLines() as $transferLine) {
                        $id = $transferLine->getId();
                        $received[$id] = (int) $transferLine->getQuantityRequested();
                        $intoBins[$id] = $toBin;
                    }

                    $this->transfers->receive($transfer, $received, $intoBins, $opId, $actor);
                }

                return $transfer;
            });
        } catch (TransferException|InsufficientStockException|\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_bundle_warehouse_ops_stock');
        }

        $this->addFlash('success', sprintf(
            'Moved %d row(s) via transfer %s%s.',
            \count($plan['lines']),
            $transfer->getNumber(),
            $travelMode === self::TRAVEL_SEND_AND_RECEIVE ? ' (dispatched and received)' : ' (dispatched, in transit)',
        ));

        return $this->redirectToRoute('admin_bundle_warehouse_ops_transfer', ['id' => $transfer->getId()]);
    }

    /**
     * Every checked row, refused as a whole (nothing rendered, nothing written) the moment ANY of
     * them fails: mixed source warehouses (#787 §5's v1 restriction), a row that no longer exists,
     * or a row that is not (or is no longer) `available`.
     *
     * @return array{0: list<InventoryDetail>, 1: ?string}
     */
    private function rowsFromRequest(Request $request): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (mixed $v): int => (int) $v,
            array_filter($request->request->all('rows'), static fn (mixed $v): bool => \is_scalar($v)),
        )));

        if ($ids === []) {
            return [[], 'Select at least one row to move.'];
        }

        $rows = [];
        $warehouseId = null;
        foreach ($ids as $id) {
            $detail = $this->em->find(InventoryDetail::class, $id);
            if (!$detail instanceof InventoryDetail || $detail->getStatus() !== InventoryDetail::STATUS_AVAILABLE) {
                return [[], 'One of the selected rows is no longer available. Nothing was moved — refresh the stock table and try again.'];
            }

            $warehouseId ??= $detail->getWarehouse()->getId();
            if ($detail->getWarehouse()->getId() !== $warehouseId) {
                return [[], 'Selected rows come from more than one warehouse. Move one warehouse at a time.'];
            }

            $rows[] = $detail;
        }

        return [$rows, null];
    }

    /**
     * Reads and re-validates qty[]/to_warehouse_id/to_bin_id/travel_mode off the request — the one
     * place both /preview and /confirm check "is this still true", so the two cannot drift into
     * checking different things.
     *
     * @return array{
     *     lines: list<array{detail: InventoryDetail, qty: string}>,
     *     sourceWarehouse: ?Warehouse,
     *     toWarehouse: ?Warehouse,
     *     toBin: ?WarehouseLocation,
     *     travelMode: string,
     *     isTransfer: bool,
     *     error: ?string,
     * }
     */
    private function resolvePlan(Request $request): array
    {
        $blank = ['lines' => [], 'sourceWarehouse' => null, 'toWarehouse' => null, 'toBin' => null, 'travelMode' => self::TRAVEL_SEND_AND_RECEIVE, 'isTransfer' => false];

        /** @var array<string, mixed> $rawQty */
        $rawQty = $request->request->all('qty');
        if ($rawQty === []) {
            return $blank + ['error' => 'Nothing to move.'];
        }

        [$toWarehouse, $toBin, $destinationError] = $this->resolveDestination($request);
        if ($destinationError !== null) {
            return $blank + ['error' => $destinationError];
        }

        $travelMode = $request->request->get('travel_mode', self::TRAVEL_SEND_AND_RECEIVE);
        $travelMode = \in_array($travelMode, [self::TRAVEL_SEND_AND_RECEIVE, self::TRAVEL_TRACK_IN_TRANSIT], true) ? $travelMode : self::TRAVEL_SEND_AND_RECEIVE;

        $lines = [];
        $sourceWarehouse = null;

        foreach ($rawQty as $id => $value) {
            $detail = $this->em->find(InventoryDetail::class, (int) $id);
            if (!$detail instanceof InventoryDetail || $detail->getStatus() !== InventoryDetail::STATUS_AVAILABLE) {
                return $blank + ['error' => 'One of the selected rows is no longer available. Nothing was moved — refresh the stock table and try again.'];
            }

            $sourceWarehouse ??= $detail->getWarehouse();
            if ($detail->getWarehouse()->getId() !== $sourceWarehouse->getId()) {
                return $blank + ['error' => 'Selected rows come from more than one warehouse. Move one warehouse at a time.'];
            }

            $qty = \is_scalar($value) ? QuantityScale::trim(trim((string) $value)) : '';
            if ($qty === '' || QuantityScale::compare($qty, '0') <= 0) {
                return $blank + ['error' => 'Every row needs a quantity greater than zero.'];
            }

            // A serial row is one unit and cannot be split — same rule the entity itself enforces
            // on write (InventoryDetail::setQuantity()), checked here too so a bad request reads as
            // a refusal with a reason rather than the entity's own exception.
            if ($detail->getSerial() !== null && QuantityScale::compare($qty, '1') !== 0) {
                return $blank + ['error' => sprintf('Serial %s identifies one unit and cannot be split — quantity must be 1.', $detail->getSerial())];
            }

            if (QuantityScale::compare($qty, $detail->getQuantity()) > 0) {
                return $blank + ['error' => sprintf(
                    'Only %s of %s is available in %s — asked to move %s.',
                    $detail->getQuantity(),
                    $detail->getProduct()->getSku(),
                    $detail->getLocation()?->getCode() ?? '(no bin)',
                    $qty,
                )];
            }

            $lines[] = ['detail' => $detail, 'qty' => $qty];
        }

        $isTransfer = $toWarehouse->getId() !== $sourceWarehouse->getId();

        if (!$isTransfer) {
            if (!$toBin instanceof WarehouseLocation) {
                return $blank + ['error' => 'Choose a destination bin.'];
            }

            foreach ($lines as $line) {
                /** @var InventoryDetail $detail */
                $detail = $line['detail'];
                if ($detail->getLocation() instanceof WarehouseLocation && $detail->getLocation()->getId() === $toBin->getId()) {
                    return $blank + ['error' => sprintf('%s is already in %s.', $detail->getProduct()->getSku(), $toBin->getCode())];
                }
            }
        } else {
            // receive()'s own $received array is int-keyed quantities (TransferOrderService's
            // long-standing contract, unchanged here) — a fractional transfer quantity would be
            // silently truncated on arrival, which is a worse answer than refusing it up front. A
            // same-warehouse bin move has no such limit, since it never goes through receive().
            foreach ($lines as $line) {
                if (!QuantityScale::isWhole($line['qty'])) {
                    return $blank + ['error' => sprintf(
                        '%s: a transfer between warehouses moves whole units only — %s is not whole. Use a bin move for partial units within one warehouse.',
                        $line['detail']->getProduct()->getSku(),
                        $line['qty'],
                    )];
                }
            }
        }

        return [
            'lines' => $lines,
            'sourceWarehouse' => $sourceWarehouse,
            'toWarehouse' => $toWarehouse,
            'toBin' => $toBin,
            'travelMode' => $travelMode,
            'isTransfer' => $isTransfer,
            'error' => null,
        ];
    }

    /**
     * The single `destination` field the form posts is `bin:<id>` or `warehouse:<id>` — one
     * control, not a warehouse select and a bin select that could disagree with each other. A bin
     * always implies its own warehouse, so there is nothing to cross-check: choosing a bin decides
     * both at once, and `warehouse:<id>` is only ever offered for "put away later", which by
     * definition names a warehouse with no bin.
     *
     * @return array{0: ?Warehouse, 1: ?WarehouseLocation, 2: ?string}
     */
    private function resolveDestination(Request $request): array
    {
        $destination = trim((string) $request->request->get('destination', ''));

        if (str_starts_with($destination, 'bin:')) {
            $bin = $this->em->find(WarehouseLocation::class, (int) substr($destination, 4));

            return $bin instanceof WarehouseLocation ? [$bin->getWarehouse(), $bin, null] : [null, null, 'Choose a destination.'];
        }

        if (str_starts_with($destination, 'warehouse:')) {
            $warehouse = $this->em->find(Warehouse::class, (int) substr($destination, 10));

            return $warehouse instanceof Warehouse ? [$warehouse, null, null] : [null, null, 'Choose a destination.'];
        }

        return [null, null, 'Choose a destination.'];
    }

    private function binTotal(WarehouseLocation $bin): string
    {
        $total = $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(d.quantity), 0)')
            ->from(InventoryDetail::class, 'd')
            ->andWhere('d.location = :bin')->setParameter('bin', $bin)
            ->andWhere('d.status = :status')->setParameter('status', InventoryDetail::STATUS_AVAILABLE)
            ->getQuery()
            ->getSingleScalarResult();

        return QuantityScale::canonical((string) $total);
    }
}
