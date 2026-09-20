<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Controller\Admin;

use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Repository\BundleStatusRepository;
use App\Service\DisplayNumber;
use App\Service\QuantityScale;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Movement\InsufficientStockException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use WarehouseOpsBundle\Entity\TransferOrder;
use WarehouseOpsBundle\Entity\TransferOrderLine;
use WarehouseOpsBundle\Repository\TransferOrderRepository;
use WarehouseOpsBundle\Transfer\TransferException;
use WarehouseOpsBundle\Transfer\TransferOrderService;
use WarehouseOpsBundle\Transfer\TransferSourceStock;

/**
 * Transfer order index, detail, dispatch and receive (#552).
 *
 * Both stock-moving actions are TransferOrderService calls, which are MovementRequests handed to
 * StockMovementService. What this controller owns is the document.
 *
 * ## The lines ARE the form (#590 P2, #611)
 *
 * A draft's line table is the entry grid, the way a sales order's is: every existing line renders as
 * a row of controls, and one blank row sits under them. Filling that row and pressing **Add line**
 * posts, appends a `transfer_order_line` row and comes back with the row in the table and a fresh
 * blank one beneath it — a plain form post, so it works with scripting off, which is the whole
 * reason the add-a-row path is a round trip rather than a cloned `<tr>`.
 *
 * There is no separate "Add a line" panel any more and no `?edit=` fork: a row is edited where it
 * is. The forms behind the controls are emitted OUTSIDE the table and wired to it by the HTML5
 * `form=` attribute, because a `<form>` inside a `<form>` is invalid HTML and every parser drops the
 * inner one — the same shape #613's delete buttons already use.
 *
 * Lot and serial are dropdowns of what the SOURCE warehouse actually holds, labelled
 * `LOT-SEA-2609 · exp 2026-09-10 · 40 available` rather than by primary key, built by
 * TransferSourceStock. Beside each one is a typed pattern box (`lot_code`, `serial_match`) matched
 * server-side with wildcards, which is both the escape hatch when the list is long and the only
 * search a browser with no JavaScript can run. `js-searchable-select` upgrades the dropdowns to
 * type-to-filter when scripting is on and is invisible when it is not — the options are rendered
 * server-side either way, so the plain `<select>` underneath is fully usable on its own.
 *
 * ## Editing and removing a DRAFT line (#613)
 *
 * A mistyped line used to mean cancelling the whole draft and retyping it, because the only thing
 * this screen could do to a line was add one. Editing and deleting are both draft-only, and the
 * bound is `TransferOrder::isEditable()` — the same one addLine() already used, so there is one
 * answer to "may this document still be changed" rather than three.
 *
 * Once a transfer has dispatched, its lines are history: `quantity_dispatched` says stock left a
 * building and `inventory_detail` has the in-transit rows to prove it, so a line that could still be
 * edited would be a line that could contradict the ledger. The refusal says that rather than
 * silently doing nothing.
 *
 * A line's **product** is deliberately not editable, for the reason a lot's product is not: the lot
 * on the line was chosen for that product, and swapping one while keeping the other is how a line
 * ends up naming a batch of something else. Getting the product wrong is Delete followed by Add,
 * which is two facts instead of one row quietly becoming a different row.
 *
 * ## Deleting a draft transfer, and why only a draft
 *
 * `transfer_order_line` cascades on `transfer_order_id`, so deleting the document really does take
 * its lines with it — which is correct for a draft that never moved anything and catastrophic for
 * one that did. Delete therefore refuses on any status but `draft` and `cancelled`, and refuses
 * again if any line carries a dispatched quantity. A dispatched transfer is cancelled or receipted,
 * never deleted: those units are somewhere, and the document is the only thing that says where.
 */
#[Route('/admin/bundles/warehouse-ops/transfers')]
final class TransferOrderController extends AbstractWarehouseOpsController
{
    public function __construct(
        EntityManagerInterface $em,
        BundleStatusRepository $bundleStatusRepo,
        private readonly TransferOrderService $transfers,
        private readonly TransferOrderRepository $repository,
        private readonly TransferSourceStock $sourceStock,
    ) {
        parent::__construct($em, $bundleStatusRepo);
    }

    #[Route('', name: 'admin_bundle_warehouse_ops_transfers', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyIfInactive();

        $filters = $this->filtersFromRequest($request, ['status', 'from', 'to', 'number']);

        $qb = $this->repository->createQueryBuilder('t')
            ->innerJoin('t.fromWarehouse', 'f')->addSelect('f')
            ->innerJoin('t.toWarehouse', 'd')->addSelect('d');

        if ($filters['status'] !== '') {
            $qb->andWhere('t.status = :status')->setParameter('status', $filters['status']);
        }
        if ($filters['from'] !== '' && ctype_digit($filters['from'])) {
            $qb->andWhere('f.id = :from')->setParameter('from', (int) $filters['from']);
        }
        if ($filters['to'] !== '' && ctype_digit($filters['to'])) {
            $qb->andWhere('d.id = :to')->setParameter('to', (int) $filters['to']);
        }
        if ($filters['number'] !== '') {
            $qb->andWhere('t.number LIKE :number')->setParameter('number', '%' . $filters['number'] . '%');
        }

        $paging = $this->paging($request, 'id', 'desc');
        $total = (int) (clone $qb)->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / $paging['limit']));
        $page = min($paging['page'], $pages);

        return $this->render('@WarehouseOps/transfers.html.twig', [
            'rows' => $qb->orderBy('t.id', $paging['dir'])
                ->setFirstResult(($page - 1) * $paging['limit'])
                ->setMaxResults($paging['limit'])
                ->getQuery()->getResult(),
            'warehouses' => $this->activeWarehouses(),
            'statuses' => TransferOrder::statuses(),
            'filters' => $filters,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'limit' => $paging['limit'],
            'currentSort' => $paging['sort'],
            'currentDir' => strtolower($paging['dir']),
        ]);
    }

    #[Route('/new', name: 'admin_bundle_warehouse_ops_transfer_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->denyIfInactive();

        $from = $this->warehouseOr404($request->request->getInt('from_warehouse_id', 0));
        $to = $this->warehouseOr404($request->request->getInt('to_warehouse_id', 0));

        if ($from->getId() === $to->getId()) {
            $this->addFlash('error', 'A transfer needs two different warehouses. Moving stock inside one building is a bin move, on the adjustment screen.');

            return $this->redirectToRoute('admin_bundle_warehouse_ops_transfers');
        }

        $transfer = (new TransferOrder())
            ->setNumber($this->repository->nextNumber())
            ->setFromWarehouse($from)
            ->setToWarehouse($to)
            ->setStatus(TransferOrder::STATUS_DRAFT)
            ->setNotes($this->nullable((string) $request->request->get('notes', '')));

        $this->em->persist($transfer);
        $this->em->flush();

        return $this->redirectToRoute('admin_bundle_warehouse_ops_transfer', ['id' => $transfer->getId()]);
    }

    #[Route('/{id}', name: 'admin_bundle_warehouse_ops_transfer', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id): Response
    {
        $this->denyIfInactive();

        $transfer = $this->transferOr404($id);
        $from = $transfer->getFromWarehouse();

        // Only a draft is typed into, so only a draft pays for the lists. A dispatched transfer's
        // lines are history and its lot cells are text.
        $editable = $transfer->isEditable();

        // What each existing line can choose from, and what the source holds on the pair it names
        // right now — the "40 available" that used to be discoverable only by dispatching and
        // reading the refusal (#611).
        $lineStock = [];
        if ($editable) {
            foreach ($transfer->getLines() as $line) {
                $lineStock[(int) $line->getId()] = [
                    // The line's OWN lot and serial are forced into their lists even when the source
                    // holds none of them, because a `<select>` that cannot express what the row
                    // currently says would blank the column on the next save — silently turning a
                    // named batch into "auto" for no reason anybody asked for.
                    'lots' => $this->withCurrentLot($this->sourceStock->lotChoices($from, $line->getProduct()), $line),
                    'serials' => $this->withCurrentSerial($this->sourceStock->serialChoices($from, $line->getProduct()), $line),
                    'available' => $this->sourceStock->available($from, $line->getProduct(), $line->getLot(), $line->getSerial()),
                ];
            }
        }

        // #626: the same shortfall the per-line hint already shows, gathered for the document.
        // A draft with three good lines and one short one looks fine unless you happen to read that
        // row, so the notice names the products rather than counting them — on a forty-line transfer
        // a count tells you there is a problem and not where it is.
        //
        // Recomputed on every view and never stored. Availability moves under a draft constantly, so
        // a stored flag would be a lie the moment another order took the stock — the same stale-cache
        // shape as #584, where transfer_out_quantity read 50 at a warehouse holding 40.
        $shortLines = [];
        foreach ($transfer->getLines() as $line) {
            $stock = $lineStock[(int) $line->getId()] ?? null;
            if ($stock === null || QuantityScale::compare($line->getQuantityRequested(), $stock['available']) <= 0) {
                continue;
            }

            // Lot and serial are what tell two lines of the same product apart. Without them a
            // transfer with four lines of one SKU reads as the same sentence four times, which is
            // worse than a count: it looks like a rendering bug rather than four separate rows.
            $shortLines[] = [
                'product' => $line->getProduct()->getSku() ?: $line->getProduct()->getName(),
                'lot' => $line->getLot()?->getCode(),
                'serial' => $line->getSerial(),
                'requested' => $line->getQuantityRequested(),
                'available' => $stock['available'],
                'shortBy' => QuantityScale::sub($line->getQuantityRequested(), $stock['available']),
            ];
        }

        // The blank row's lists span every product at the source, because no product has been chosen
        // yet. Truncated rather than unbounded: past the cap the template says so and the pattern box
        // beside the dropdown is the way through.
        $entryLots = $editable ? $this->sourceStock->lotChoices($from) : [];
        $entrySerials = $editable ? $this->sourceStock->serialChoices($from) : [];

        return $this->render('@WarehouseOps/transfer.html.twig', [
            'transfer' => $transfer,
            'products' => $editable ? $this->dimensionalProducts() : [],
            'lineStock' => $lineStock,
            'shortLines' => $shortLines,
            'entryLots' => \array_slice($entryLots, 0, TransferSourceStock::OPTION_CAP),
            'entrySerials' => \array_slice($entrySerials, 0, TransferSourceStock::OPTION_CAP),
            'entryLotsTruncated' => \count($entryLots) > TransferSourceStock::OPTION_CAP,
            'entrySerialsTruncated' => \count($entrySerials) > TransferSourceStock::OPTION_CAP,
            'destinationBins' => $this->em->getRepository(WarehouseLocation::class)->findBy(
                ['warehouse' => $transfer->getToWarehouse(), 'status' => 'Active'],
                ['sortKey' => 'ASC', 'code' => 'ASC'],
            ),
        ]);
    }

    /**
     * Appends one line — the blank row at the bottom of the grid, submitted.
     *
     * Four ways to name the batch, in this order, and every one of them checked here rather than in
     * the browser:
     *
     *  1. `lot_id` from the dropdown — REFUSED unless it is a batch of the line's own product;
     *  2. `lot_code` as a typed pattern (`SEA-26*`) matched against the lots this product has at the
     *     source — refused when it matches nothing, and refused with the candidates named when it
     *     matches more than one, because choosing on the operator's behalf is how a line ends up
     *     naming a batch nobody meant;
     *  3. `serial` / `serial_match`, the same pair for a single unit;
     *  4. neither, which is the deliberate transfer default: the service suggests the
     *     **longest-dated** batch — the opposite of the earliest-expiry rule a customer shipment
     *     uses, because sending the earliest-expiring stock to a slower site is how it expires
     *     there. The blank option in the dropdown SAYS that, so it is a choice on screen rather
     *     than a hint buried in a label (#611).
     *
     * A quantity larger than the source holds is not refused. A draft is a plan — stock arriving
     * tomorrow is an ordinary reason to raise one — so the shortfall is stated in the flash and
     * printed under the line's quantity, and `dispatch()` stays the place it is enforced.
     */
    #[Route('/{id}/lines', name: 'admin_bundle_warehouse_ops_transfer_add_line', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addLine(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $transfer = $this->transferOr404($id);

        if (!$transfer->isEditable()) {
            $this->addFlash('error', sprintf('%s has already been dispatched; its lines are history now.', $transfer->getNumber()));

            return $this->redirectToRoute('admin_bundle_warehouse_ops_transfer', ['id' => $id]);
        }

        $product = $this->em->find(ProductCore::class, $request->request->getInt('product_id', 0));
        $quantity = $request->request->getInt('quantity', 0);

        if (!$product instanceof ProductCore || $quantity <= 0) {
            $this->addFlash('error', 'A transfer line needs a product and a positive quantity.');

            return $this->redirectToRoute('admin_bundle_warehouse_ops_transfer', ['id' => $id]);
        }

        $from = $transfer->getFromWarehouse();

        $lot = $this->lotFromRequest($request, $product, $from, $refusal);
        if ($refusal !== null) {
            $this->addFlash('error', $refusal);

            return $this->redirectToRoute('admin_bundle_warehouse_ops_transfer', ['id' => $id]);
        }

        $serial = $this->serialFromRequest($request, $product, $from, $refusal);
        if ($refusal !== null) {
            $this->addFlash('error', $refusal);

            return $this->redirectToRoute('admin_bundle_warehouse_ops_transfer', ['id' => $id]);
        }

        $suggested = false;
        if (!$lot instanceof InventoryLot) {
            $lot = $this->transfers->suggestLot($product, $from);
            $suggested = $lot instanceof InventoryLot;
        }

        $line = (new TransferOrderLine())
            ->setProduct($product)
            ->setLot($lot)
            ->setSerial($serial)
            ->setSku($product->getSku())
            ->setName($product->getName())
            ->setQuantityRequested($quantity);

        $transfer->addLine($line);
        $this->em->persist($line);
        $this->em->flush();

        $this->addFlash('success', sprintf(
            '%d × %s added%s.%s',
            $quantity,
            $product->getSku() ?: $product->getName(),
            $lot instanceof InventoryLot
                ? sprintf(' from %s%s', $lot->getLabel(), $suggested ? ' (auto — the longest-dated batch there)' : '')
                : '',
            $this->shortfallNote($from, $product, $lot, $serial, $quantity),
        ));

        return $this->redirectToRoute('admin_bundle_warehouse_ops_transfer', ['id' => $id]);
    }

    /**
     * Corrects a draft line in place: quantity, lot, serial.
     *
     * The hidden `line_id` names the row, exactly as #590's lot form does, and a `line_id` that
     * resolves to nothing — or to a line on another transfer — is REFUSED rather than falling
     * through to add a line. A stale Edit link must not mint a second line for goods somebody was
     * trying to correct.
     *
     * The product is not among the fields; see the class docblock.
     */
    #[Route('/{id}/lines/update', name: 'admin_bundle_warehouse_ops_transfer_update_line', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateLine(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $transfer = $this->transferOr404($id);

        if (!$transfer->isEditable()) {
            $this->addFlash('error', sprintf('%s has already been dispatched; its lines are history now.', $transfer->getNumber()));

            return $this->redirectToRoute('admin_bundle_warehouse_ops_transfer', ['id' => $id]);
        }

        $line = $this->lineOnOrNull($transfer, $request->request->getInt('line_id', 0));
        if (!$line instanceof TransferOrderLine) {
            $this->addFlash('error', sprintf('That line is not on %s any more. Nothing was saved.', $transfer->getNumber()));

            return $this->redirectToRoute('admin_bundle_warehouse_ops_transfer', ['id' => $id]);
        }

        $quantity = $request->request->getInt('quantity', 0);
        if ($quantity <= 0) {
            $this->addFlash('error', 'A transfer line needs a positive quantity. Removing the line is Delete, not a quantity of zero.');

            return $this->redirectToRoute('admin_bundle_warehouse_ops_transfer', ['id' => $id, '_fragment' => 'line-' . $line->getId()]);
        }

        // Blank means "leave it to the suggestion at dispatch", which is what a blank lot has always
        // meant on this form — and the dropdown's blank option now says so in words. A lot id naming
        // a lot of a different product is refused: the pair (product, lot) is what every detail row
        // hangs off, and a line naming a mismatched pair dispatches into a row that describes goods
        // nobody has. Same resolution as addLine(), so a typed `SEA-26*` means the same thing in
        // both places.
        $from = $transfer->getFromWarehouse();

        $lot = $this->lotFromRequest($request, $line->getProduct(), $from, $refusal);
        if ($refusal !== null) {
            $this->addFlash('error', $refusal);

            return $this->redirectToRoute('admin_bundle_warehouse_ops_transfer', ['id' => $id]);
        }

        $serial = $this->serialFromRequest($request, $line->getProduct(), $from, $refusal);
        if ($refusal !== null) {
            $this->addFlash('error', $refusal);

            return $this->redirectToRoute('admin_bundle_warehouse_ops_transfer', ['id' => $id]);
        }

        $line
            ->setQuantityRequested($quantity)
            ->setLot($lot)
            ->setSerial($serial);

        $this->em->flush();

        $this->addFlash('success', sprintf(
            'transfer_order_line row %d updated: %d × %s%s.%s',
            $line->getId(),
            $line->getQuantityRequested(),
            $line->getSku() ?: $line->getName(),
            $line->getLot() instanceof InventoryLot ? sprintf(' from %s', $line->getLot()->getLabel()) : '',
            $this->shortfallNote($from, $line->getProduct(), $lot, $serial, $quantity),
        ));

        return $this->redirectToRoute('admin_bundle_warehouse_ops_transfer', ['id' => $id]);
    }

    /** Removes a draft line. Draft only, for the reason the class docblock gives. */
    #[Route('/{id}/lines/{line}/delete', name: 'admin_bundle_warehouse_ops_transfer_delete_line', methods: ['POST'], requirements: ['id' => '\d+', 'line' => '\d+'])]
    public function deleteLine(int $id, int $line): Response
    {
        $this->denyIfInactive();

        $transfer = $this->transferOr404($id);

        if (!$transfer->isEditable()) {
            $this->addFlash('error', sprintf(
                '%s has already been dispatched, so its lines cannot be removed — %s says stock left the building and the in-transit rows are on the ledger. Receipt what arrived instead.',
                $transfer->getNumber(),
                'transfer_order_line.quantity_dispatched',
            ));

            return $this->redirectToRoute('admin_bundle_warehouse_ops_transfer', ['id' => $id]);
        }

        $row = $this->lineOnOrNull($transfer, $line);
        if (!$row instanceof TransferOrderLine) {
            $this->addFlash('error', sprintf('That line is not on %s any more. Nothing was deleted.', $transfer->getNumber()));

            return $this->redirectToRoute('admin_bundle_warehouse_ops_transfer', ['id' => $id]);
        }

        $label = sprintf('%d × %s', $row->getQuantityRequested(), $row->getSku() ?: $row->getName());

        // Through the collection, so orphanRemoval does the DELETE and the in-memory document is not
        // left holding a row the database no longer has.
        $transfer->getLines()->removeElement($row);
        $this->em->remove($row);
        $this->em->flush();

        $this->addFlash('success', sprintf('%s removed from %s (transfer_order_line row %d deleted). Nothing had moved.', $label, $transfer->getNumber(), $line));

        return $this->redirectToRoute('admin_bundle_warehouse_ops_transfer', ['id' => $id]);
    }

    /**
     * Deletes a transfer that never moved anything.
     *
     * Refused on any status but `draft` and `cancelled`, and refused again while any line carries a
     * dispatched quantity. See the class docblock: `transfer_order_line` cascades, so this really
     * does take the lines with it, and a document that moved stock is the only record of where those
     * units went.
     */
    #[Route('/{id}/delete', name: 'admin_bundle_warehouse_ops_transfer_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id): Response
    {
        $this->denyIfInactive();

        $transfer = $this->transferOr404($id);

        if (!in_array($transfer->getStatus(), [TransferOrder::STATUS_DRAFT, TransferOrder::STATUS_CANCELLED], true)) {
            $this->addFlash('error', sprintf(
                '%s is %s and cannot be deleted. A transfer that has moved stock is the only record of where those units went — cancel a draft, receipt a dispatch, but never delete either.',
                $transfer->getNumber(),
                $transfer->getStatus(),
            ));

            return $this->redirectToRoute('admin_bundle_warehouse_ops_transfer', ['id' => $id]);
        }

        $dispatched = 0;
        foreach ($transfer->getLines() as $line) {
            $dispatched += $line->getQuantityDispatched();
        }

        // Belt and braces against a row whose status and quantities disagree. A cancelled transfer
        // provably never dispatched — cancel() refuses once it has — so this can only fire on data
        // edited outside the app, and deleting on it would drop the only document explaining
        // in-transit stock.
        if ($dispatched > 0) {
            $this->addFlash('error', sprintf(
                '%s cannot be deleted: its lines still carry %d dispatched unit(s) in transfer_order_line.quantity_dispatched. Those units are somewhere, and this document is what says where.',
                $transfer->getNumber(),
                $dispatched,
            ));

            return $this->redirectToRoute('admin_bundle_warehouse_ops_transfer', ['id' => $id]);
        }

        $number = $transfer->getNumber();
        $lines = $transfer->getLines()->count();

        $this->em->remove($transfer);
        $this->em->flush();

        $this->addFlash('success', sprintf(
            '%s deleted (transfer_order row %d, with its %d transfer_order_line row(s)). Nothing had moved, so nothing was written to the ledger to lose.',
            $number,
            $id,
            $lines,
        ));

        return $this->redirectToRoute('admin_bundle_warehouse_ops_transfers');
    }

    #[Route('/{id}/dispatch', name: 'admin_bundle_warehouse_ops_transfer_dispatch', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function dispatch(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $transfer = $this->transferOr404($id);

        try {
            $this->transfers->dispatch($transfer, $this->operationKey($request), $this->actor());
            $this->addFlash('success', sprintf(
                '%s dispatched. The stock is in transit and owned by %s until it is receipted — unsellable at both ends, and still locatable.',
                $transfer->getNumber(),
                $transfer->getFromWarehouse()->getName(),
            ));
        } catch (TransferException|InsufficientStockException|\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_bundle_warehouse_ops_transfer', ['id' => $id]);
    }

    #[Route('/{id}/receive', name: 'admin_bundle_warehouse_ops_transfer_receive', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function receive(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $transfer = $this->transferOr404($id);

        /** @var array<int|string, mixed> $arrived */
        $arrived = $request->request->all('received');
        /** @var array<int|string, mixed> $bins */
        $bins = $request->request->all('bin');

        $quantities = [];
        $intoBins = [];
        foreach ($arrived as $lineId => $quantity) {
            $quantities[(int) $lineId] = \is_scalar($quantity) ? (int) $quantity : 0;
            $intoBins[(int) $lineId] = $this->binOrNull(\is_scalar($bins[$lineId] ?? null) ? (int) $bins[$lineId] : 0);
        }

        try {
            $this->transfers->receive($transfer, $quantities, $intoBins, $this->operationKey($request), $this->actor());

            $lost = $transfer->inTransitUnits();
            $this->addFlash('success', sprintf(
                '%s received.%s',
                $transfer->getNumber(),
                (float) $lost > 0.0
                    ? sprintf(' %s unit(s) left and did not arrive — still sitting on %s\'s in-transit row, unsellable, until somebody writes them off deliberately.', (new DisplayNumber())->qty($lost), $transfer->getFromWarehouse()->getName())
                    : '',
            ));
        } catch (TransferException|InsufficientStockException|\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_bundle_warehouse_ops_transfer', ['id' => $id]);
    }

    #[Route('/{id}/cancel', name: 'admin_bundle_warehouse_ops_transfer_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancel(int $id): Response
    {
        $this->denyIfInactive();

        $transfer = $this->transferOr404($id);

        // Only a draft. A dispatched transfer has stock on a truck; "cancelling" it would leave those
        // units in transit forever with no document saying where they were going. Receipt it, or
        // write it off — both are real events, and cancelling is neither.
        if (!$transfer->isEditable()) {
            $this->addFlash('error', sprintf('%s has already dispatched stock. Receipt what arrived instead — cancelling would leave the units in transit with nothing to explain them.', $transfer->getNumber()));

            return $this->redirectToRoute('admin_bundle_warehouse_ops_transfer', ['id' => $id]);
        }

        $transfer->setStatus(TransferOrder::STATUS_CANCELLED);
        $this->em->flush();
        $this->addFlash('success', sprintf('%s cancelled. Nothing had moved, so nothing was written.', $transfer->getNumber()));

        return $this->redirectToRoute('admin_bundle_warehouse_ops_transfers');
    }

    /**
     * @param list<array{id: int, sku: string, product: string, code: string, expiry: ?string, available: int, label: string}> $choices
     *
     * @return list<array{id: int, sku: string, product: string, code: string, expiry: ?string, available: int, label: string}>
     */
    private function withCurrentLot(array $choices, TransferOrderLine $line): array
    {
        $lot = $line->getLot();
        if (!$lot instanceof InventoryLot) {
            return $choices;
        }

        foreach ($choices as $choice) {
            if ($choice['id'] === (int) $lot->getId()) {
                return $choices;
            }
        }

        array_unshift($choices, [
            'id' => (int) $lot->getId(),
            'sku' => $lot->getProduct()->getSku(),
            'product' => $lot->getProduct()->getName(),
            'code' => $lot->getCode(),
            'expiry' => $lot->getExpiry()?->format('Y-m-d'),
            'available' => 0,
            'label' => $this->sourceStock->lotLabel($lot->getCode(), $lot->getExpiry()?->format('Y-m-d'), 0),
        ]);

        return $choices;
    }

    /**
     * @param list<array{serial: string, sku: string, product: string, lot: ?string, available: int, label: string}> $choices
     *
     * @return list<array{serial: string, sku: string, product: string, lot: ?string, available: int, label: string}>
     */
    private function withCurrentSerial(array $choices, TransferOrderLine $line): array
    {
        $serial = $line->getSerial();
        if ($serial === null || $serial === '') {
            return $choices;
        }

        foreach ($choices as $choice) {
            if ($choice['serial'] === $serial) {
                return $choices;
            }
        }

        array_unshift($choices, [
            'serial' => $serial,
            'sku' => $line->getProduct()->getSku(),
            'product' => $line->getProduct()->getName(),
            'lot' => $line->getLot()?->getCode(),
            'available' => 0,
            'label' => sprintf('S/N %s · 0 available', $serial),
        ]);

        return $choices;
    }

    /**
     * The lot this submission names, from the dropdown or from a typed pattern.
     *
     * `$refusal` comes back with a sentence whenever the request named something that cannot be
     * used, and the caller aborts on it. Null lot with null refusal is the ordinary "no lot named"
     * case, which is what makes the longest-dated default fire.
     */
    private function lotFromRequest(Request $request, ProductCore $product, Warehouse $from, ?string &$refusal): ?InventoryLot
    {
        $refusal = null;

        $lotId = $request->request->getInt('lot_id', 0);
        if ($lotId > 0) {
            $lot = $this->em->find(InventoryLot::class, $lotId);
            if (!$lot instanceof InventoryLot || $lot->getProduct()->getId() !== $product->getId()) {
                $refusal = sprintf(
                    'Lot %d is not a batch of %s. A line names a lot of its own product or none at all.',
                    $lotId,
                    $product->getSku() ?: $product->getName(),
                );

                return null;
            }

            return $lot;
        }

        $pattern = trim((string) $request->request->get('lot_code', ''));
        if ($pattern === '') {
            return null;
        }

        $matches = $this->sourceStock->matchLots($from, $product, $pattern);

        if ($matches === []) {
            $refusal = sprintf(
                'No batch of %s at %s matches "%s". Only lots with inventory_detail rows in status available and quantity above 0 there can be sent — leave the lot blank to take the longest-dated one.',
                $product->getSku() ?: $product->getName(),
                $from->getName(),
                $pattern,
            );

            return null;
        }

        if (\count($matches) > 1) {
            $refusal = sprintf(
                '"%s" matches %d batches of %s at %s: %s. Name one of them, or narrow the pattern.',
                $pattern,
                \count($matches),
                $product->getSku() ?: $product->getName(),
                $from->getName(),
                implode('; ', array_map(static fn (array $m): string => $m['label'], \array_slice($matches, 0, 8))),
            );

            return null;
        }

        $lot = $this->em->find(InventoryLot::class, $matches[0]['id']);

        return $lot instanceof InventoryLot ? $lot : null;
    }

    /**
     * The serial this submission names, from the dropdown or from a typed pattern.
     *
     * The dropdown posts the serial string itself, so a chosen one needs no lookup. A pattern is
     * matched against the serials the source actually holds for this product, which is the answer to
     * the error this screen used to produce — `S/N 11 [available] holds 0 unit(s)` — where the
     * operator had guessed at a string nobody had.
     */
    private function serialFromRequest(Request $request, ProductCore $product, Warehouse $from, ?string &$refusal): ?string
    {
        $refusal = null;

        $chosen = $this->nullable((string) $request->request->get('serial', ''));
        if ($chosen !== null) {
            return $chosen;
        }

        $pattern = trim((string) $request->request->get('serial_match', ''));
        if ($pattern === '') {
            return null;
        }

        $matches = $this->sourceStock->matchSerials($from, $product, $pattern);

        if ($matches === []) {
            $refusal = sprintf(
                'No serial of %s at %s matches "%s". Leave the serial blank to send loose units rather than one named one.',
                $product->getSku() ?: $product->getName(),
                $from->getName(),
                $pattern,
            );

            return null;
        }

        if (\count($matches) > 1) {
            $refusal = sprintf(
                '"%s" matches %d serials of %s at %s: %s. A line names one unit, so name one.',
                $pattern,
                \count($matches),
                $product->getSku() ?: $product->getName(),
                $from->getName(),
                implode('; ', array_map(static fn (array $m): string => $m['serial'], \array_slice($matches, 0, 8))),
            );

            return null;
        }

        return $matches[0]['serial'];
    }

    /**
     * The sentence appended to a save that asks for more than the source holds.
     *
     * A statement, not a refusal — see addLine()'s docblock. Empty string when the stock is there,
     * so the ordinary save reads exactly as it always did.
     */
    private function shortfallNote(Warehouse $from, ProductCore $product, ?InventoryLot $lot, ?string $serial, int $quantity): string
    {
        $available = $this->sourceStock->available($from, $product, $lot, $serial);
        if (QuantityScale::compare($available, $quantity) >= 0) {
            return '';
        }

        return sprintf(
            ' %s holds %s available on that %s in inventory_detail and the line asks for %d — dispatch will refuse the difference until it is there.',
            $from->getName(),
            (new DisplayNumber())->qty($available),
            $lot instanceof InventoryLot ? 'batch' : 'product',
            $quantity,
        );
    }

    /**
     * The line with this id, but only if it is on THIS transfer.
     *
     * The ownership check is the point: without it `/transfers/7/lines/99/delete` would delete a
     * line off transfer 3, and the URL a person is looking at would have nothing to do with the row
     * that changed.
     */
    private function lineOnOrNull(TransferOrder $transfer, int $lineId): ?TransferOrderLine
    {
        if ($lineId <= 0) {
            return null;
        }

        $line = $this->em->find(TransferOrderLine::class, $lineId);

        return $line instanceof TransferOrderLine && $line->getTransferOrder()->getId() === $transfer->getId()
            ? $line
            : null;
    }

    private function transferOr404(int $id): TransferOrder
    {
        $transfer = $this->em->find(TransferOrder::class, $id);
        if (!$transfer instanceof TransferOrder) {
            throw new NotFoundHttpException('No such transfer order.');
        }

        return $transfer;
    }

    /** @return list<ProductCore> */
    private function dimensionalProducts(): array
    {
        /** @var list<ProductCore> $rows */
        $rows = $this->em->getRepository(ProductCore::class)->findBy(
            ['inventoryMode' => ProductCore::INVENTORY_MODE_DIMENSIONAL, 'deleted' => false],
            ['sku' => 'ASC'],
        );

        return $rows;
    }
}
