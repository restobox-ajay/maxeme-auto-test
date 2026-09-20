<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Controller\Admin;

use App\Entity\ProductCore;
use App\Entity\SalesOrder;
use App\Entity\Warehouse;
use App\Repository\BundleStatusRepository;
use App\Service\DisplayNumber;
use App\Service\Inventory\OrderInventoryBucketResolver;
use App\Service\QuantityScale;
use BarcodeBundle\Barcode\ProductLookup;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Movement\InsufficientStockException;
use InventoryDepthBundle\Repository\InventoryDetailRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use WarehouseOpsBundle\Entity\PickList;
use WarehouseOpsBundle\Entity\PickTask;
use WarehouseOpsBundle\Pick\PickConfirmationException;
use WarehouseOpsBundle\Pick\PickConfirmationService;
use WarehouseOpsBundle\Pick\PickListCompiler;
use WarehouseOpsBundle\Pick\PickSource;
use WarehouseOpsBundle\Repository\PickTaskRepository;

/**
 * Pick list index, detail and confirmation (#552).
 *
 * The only write that touches stock is PickConfirmationService, which hands a MovementRequest to
 * InventoryDepthBundle's StockMovementService. Nothing here touches `inventory_detail`, and there is
 * no code path from this controller to a stock quantity that does not pass through the same service
 * the desktop adjustment screen uses.
 *
 * **There is deliberately no ship action.** See the bundle README: this application accounts for
 * shipped goods with the invoice's `approved` hold, released by an import or recount, and there is
 * no mechanism anywhere that decrements stock on shipment. Adding one here would subtract the same
 * units twice — once as the hold that is still standing on them, once as the stock leaving — and
 * releasing that hold is a change to core's status-to-bucket table rather than something a bundle
 * may do. Closing a round therefore writes no movements at all.
 */
#[Route('/admin/bundles/warehouse-ops/pick-lists')]
final class PickListController extends AbstractWarehouseOpsController
{
    public function __construct(
        EntityManagerInterface $em,
        BundleStatusRepository $bundleStatusRepo,
        private readonly PickListCompiler $compiler,
        private readonly PickConfirmationService $confirmations,
        private readonly PickTaskRepository $tasks,
        private readonly InventoryDetailRepository $details,
        private readonly ProductLookup $lookup,
    ) {
        parent::__construct($em, $bundleStatusRepo);
    }

    #[Route('', name: 'admin_bundle_warehouse_ops_pick_lists', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyIfInactive();

        $filters = $this->filtersFromRequest($request, ['status', 'warehouse', 'number', 'assigned']);

        $qb = $this->em->getRepository(PickList::class)->createQueryBuilder('p')
            ->innerJoin('p.warehouse', 'w')->addSelect('w');

        if ($filters['status'] !== '') {
            $qb->andWhere('p.status = :status')->setParameter('status', $filters['status']);
        }
        if ($filters['warehouse'] !== '' && ctype_digit($filters['warehouse'])) {
            $qb->andWhere('w.id = :warehouse')->setParameter('warehouse', (int) $filters['warehouse']);
        }
        if ($filters['number'] !== '') {
            $qb->andWhere('p.number LIKE :number')->setParameter('number', '%' . $filters['number'] . '%');
        }
        if ($filters['assigned'] !== '') {
            $qb->andWhere('p.assignedTo LIKE :assigned')->setParameter('assigned', '%' . $filters['assigned'] . '%');
        }

        $paging = $this->paging($request, 'id', 'desc');
        $total = (int) (clone $qb)->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();
        $pages = max(1, (int) ceil($total / $paging['limit']));
        $page = min($paging['page'], $pages);

        $orderExpr = match ($paging['sort']) {
            'number' => 'p.number',
            'status' => 'p.status',
            'warehouse' => 'w.name',
            default => 'p.id',
        };

        return $this->render('@WarehouseOps/pick_lists.html.twig', [
            'rows' => $qb->orderBy($orderExpr, $paging['dir'])
                ->setFirstResult(($page - 1) * $paging['limit'])
                ->setMaxResults($paging['limit'])
                ->getQuery()->getResult(),
            'warehouses' => $this->activeWarehouses(),
            'statuses' => PickList::statuses(),
            'filters' => $filters,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'limit' => $paging['limit'],
            'currentSort' => $paging['sort'],
            'currentDir' => strtolower($paging['dir']),
        ]);
    }

    /**
     * The compile screen: pick a warehouse, tick the orders, get a round.
     *
     * The candidate list is orders that hold `sales_hold` — approved onward, and not yet fully
     * invoiced — because that is precisely the set of orders that still owe goods. The status list
     * comes from OrderInventoryBucketResolver rather than being typed out here, so a status added to
     * the enum cannot be forgotten in one of the two places.
     */
    #[Route('/new', name: 'admin_bundle_warehouse_ops_pick_list_new', methods: ['GET'])]
    public function compileForm(Request $request): Response
    {
        $this->denyIfInactive();

        $warehouseId = $request->query->getInt('warehouse', 0);
        $warehouse = $warehouseId > 0 ? $this->em->find(Warehouse::class, $warehouseId) : null;

        /** @var list<SalesOrder> $orders */
        $orders = $this->em->getRepository(SalesOrder::class)->createQueryBuilder('o')
            ->andWhere('LOWER(o.status) IN (:statuses)')
            ->setParameter('statuses', OrderInventoryBucketResolver::salesHoldStatuses())
            ->orderBy('o.id', 'DESC')
            ->setMaxResults(200)
            ->getQuery()->getResult();

        return $this->render('@WarehouseOps/pick_list_new.html.twig', [
            'warehouses' => $this->activeWarehouses(),
            'warehouse' => $warehouse instanceof Warehouse ? $warehouse : null,
            'orders' => $orders,
        ]);
    }

    #[Route('/new', name: 'admin_bundle_warehouse_ops_pick_list_compile', methods: ['POST'])]
    public function compile(Request $request): Response
    {
        $this->denyIfInactive();

        $warehouse = $this->warehouseOr404($request->request->getInt('warehouse_id', 0));

        $orderIds = array_filter(array_map('intval', (array) $request->request->all('orders')));
        $orders = [];
        foreach ($orderIds as $id) {
            $order = $this->em->find(SalesOrder::class, $id);
            if ($order instanceof SalesOrder) {
                $orders[] = $order;
            }
        }

        if ($orders === []) {
            $this->addFlash('error', 'Tick at least one order — a pick list with no orders is a walk with nothing to collect.');

            return $this->redirectToRoute('admin_bundle_warehouse_ops_pick_list_new', ['warehouse' => $warehouse->getId()]);
        }

        $compiled = $this->compiler->compile($warehouse, $orders, $this->nullable((string) $request->request->get('assigned_to', '')));

        foreach ($compiled->skipped as $reason) {
            $this->addFlash('info', $reason);
        }

        if ($compiled->isEmpty()) {
            $this->addFlash('error', 'None of those orders had a line to pick from this warehouse.');

            return $this->redirectToRoute('admin_bundle_warehouse_ops_pick_list_new', ['warehouse' => $warehouse->getId()]);
        }

        $this->compiler->persist($compiled->list);
        $this->em->flush();

        $this->addFlash('success', sprintf(
            '%s compiled: %d task(s) across %d order(s), in pick-path order.',
            $compiled->list->getNumber(),
            $compiled->list->getTasks()->count(),
            $compiled->list->orderCount(),
        ));

        return $this->redirectToRoute('admin_bundle_warehouse_ops_pick_list', ['id' => $compiled->list->getId()]);
    }

    #[Route('/{id}', name: 'admin_bundle_warehouse_ops_pick_list', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id): Response
    {
        $this->denyIfInactive();

        $list = $this->listOr404($id);

        $tasks = $this->tasks->inRouteOrder($list);

        return $this->render('@WarehouseOps/pick_list.html.twig', [
            'list' => $list,
            'tasks' => $tasks,
            'stagingBins' => $this->stagingBins($list->getWarehouse()),
            'orders' => $this->ordersOn($list),
            'sources' => $this->sourceChoices($list, $tasks),
        ]);
    }

    /**
     * Releases the list to a picker, against the staging bin its stock will be put down in.
     *
     * The staging bin is required here rather than at confirmation time because it is the thing that
     * makes a confirmed pick leave the product's total alone — see PickConfirmationService — and
     * discovering it is missing halfway down an aisle is not a discovery worth having.
     */
    #[Route('/{id}/release', name: 'admin_bundle_warehouse_ops_pick_list_release', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function release(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $list = $this->listOr404($id);

        if ($list->getStatus() !== PickList::STATUS_DRAFT) {
            $this->addFlash('error', sprintf('%s is %s and cannot be released again.', $list->getNumber(), $list->getStatus()));

            return $this->redirectToRoute('admin_bundle_warehouse_ops_pick_list', ['id' => $id]);
        }

        $staging = $this->binOrNull($request->request->getInt('staging_location_id', 0));
        if (!$staging instanceof WarehouseLocation || $staging->getWarehouse()->getId() !== $list->getWarehouse()->getId()) {
            $this->addFlash('error', 'Choose a staging bin in this warehouse. Picked stock has to be put down somewhere that is not the shelf it came off.');

            return $this->redirectToRoute('admin_bundle_warehouse_ops_pick_list', ['id' => $id]);
        }

        $list
            ->setStagingLocation($staging)
            ->setAssignedTo($this->nullable((string) $request->request->get('assigned_to', '')) ?? $list->getAssignedTo())
            ->setStatus(PickList::STATUS_RELEASED)
            ->setReleasedAt(new \DateTimeImmutable());

        $this->em->flush();
        $this->addFlash('success', sprintf('%s released. Picked stock goes to %s.', $list->getNumber(), $staging->getCode()));

        return $this->redirectToRoute('admin_bundle_warehouse_ops_pick_list', ['id' => $id]);
    }

    /**
     * Scanning stock OUT (#609).
     *
     * ## This is what dispatch means in this application today, and it is worth being exact
     *
     * There is no customer shipment screen: #593 is parked because core has no action or event that
     * says the goods left, so `inventory_detail.status = 'sold'` has no producer and `TYPE_SHIP` has
     * no writer. That is documented at length in the bundle README and it has not changed here.
     *
     * What DOES happen when goods go out is this: a picker walks a released round and takes stock off
     * the shelf into the outbound staging bin. That is the outbound door, it is the last moment
     * anybody has a carton in their hands before it leaves, and it is therefore where a scanner
     * belongs. This screen is that scanner. When #593 lands, a shipment screen scans the same way
     * against shipment lines instead of pick tasks, and nothing here has to be undone for it.
     *
     * ## It writes nothing itself, and that is not an accident
     *
     * Every scan changes the URL and only the URL. The Confirm button POSTs to
     * `admin_bundle_warehouse_ops_pick_list_confirm` — the SAME route, with the SAME
     * `picked[taskId]` and `picked_from[taskId]` fields, that the typed screen has always posted to.
     * So a scanned round and a typed round produce byte-identical movement rows through
     * PickConfirmationService, and there is no second implementation of "stock left the shelf" to
     * drift out of step. NoSecondWritePathTest exists because that is exactly what goes wrong.
     *
     * ## No JavaScript anywhere in it
     *
     * A hardware wedge scanner types the code into the focused field and presses Enter. That is a
     * plain form submit. The tally lives in GET parameters exactly the way the scan console's wizard
     * state does, so the back button undoes a mis-scan, a dropped connection loses one step rather
     * than the round, and a supervisor can be sent the URL. A camera sits beside this field; it never
     * replaces it.
     */
    #[Route('/{id}/scan', name: 'admin_bundle_warehouse_ops_pick_list_scan', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function scanOut(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $list = $this->listOr404($id);
        $tasks = $this->tasks->inRouteOrder($list);
        $counted = $this->countedFromRequest($request, $tasks);

        return $this->render('@WarehouseOps/pick_list_scan.html.twig', [
            'list' => $list,
            'tasks' => $tasks,
            'counted' => $counted,
            'sources' => $this->sourcesFromRequest($request, $tasks),
            'bin' => $this->binOrNull($request->query->getInt('bin', 0)),
            'scannedTotal' => array_sum($counted),
            // Minted per render, so a submit that times out and gets retried applies once.
            'operationId' => bin2hex(random_bytes(12)),
        ]);
    }

    /**
     * One scan on the dispatch screen. Resolves it and puts the result back into the URL.
     *
     * Three things can be scanned and each is answered differently:
     *
     *  - **A bin code** in this warehouse sets where the picker is standing, so every product scanned
     *    after it is recorded as having come off THAT shelf — #591's question, asked by scanning
     *    rather than by choosing from a dropdown.
     *  - **A product**, by SKU or by any barcode on it, counts one unit against the first task on the
     *    round that still wants some of it.
     *  - **Anything else** blocks loudly and records nothing.
     *
     * Every refusal names the value it refused and says why. Nothing in this method writes to the
     * database at all, so a refused scan cannot leave a partial anything.
     */
    #[Route('/{id}/scan', name: 'admin_bundle_warehouse_ops_pick_list_scan_step', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function scanOutStep(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $list = $this->listOr404($id);
        $tasks = $this->tasks->inRouteOrder($list);

        $counted = $this->countedFromRequest($request, $tasks);
        $sources = $this->sourcesFromRequest($request, $tasks);
        $binId = $request->request->getInt('bin', 0);

        if (!$list->isOpen()) {
            $this->addFlash('error', sprintf('%s is %s; nothing more can be scanned against it.', $list->getNumber(), $list->getStatus()));

            return $this->redirectToRoute('admin_bundle_warehouse_ops_pick_list', ['id' => $id]);
        }

        $code = trim((string) $request->request->get('code', ''));
        if ($code === '') {
            $this->addFlash('error', 'Nothing was scanned.');

            return $this->redirectToRoute('admin_bundle_warehouse_ops_pick_list_scan', $this->scanState($id, $counted, $sources, $binId));
        }

        // NOT filtered to `status = 'Active'`, unlike ScanResolver::bin(), and that is deliberate
        // (#609's fourth gap). On the old data three of 590 bins were Active while 1,064 units sat
        // in 76 Inactive ones — so scanning a real label off a real shelf answered "not a bin". A
        // bin's status says whether it should be PUT AWAY into, not whether stock is standing in
        // it; refusing to record where units actually came from is how a round ends up attributing
        // them to a bin nobody went to. ScanResolver keeps its own filter until #590's P4 settles
        // what Inactive means everywhere.
        $bin = $this->em->getRepository(WarehouseLocation::class)->findOneBy([
            'code' => $code,
            'warehouse' => $list->getWarehouse(),
        ]);
        $products = $this->lookup->products($code);

        // A string that is both a bin code and a product identifier is refused, not ranked — the
        // rule ScanResolver has always applied, for the reason it has always applied it: a guess is
        // wrong about half the time and silent about it.
        if ($bin instanceof WarehouseLocation && $products !== []) {
            $this->addFlash('error', sprintf(
                '%s is both a bin in %s and a product identifier. Rename one of them — this scan cannot mean two things. Nothing was recorded.',
                $code,
                $list->getWarehouse()->getName(),
            ));

            return $this->redirectToRoute('admin_bundle_warehouse_ops_pick_list_scan', $this->scanState($id, $counted, $sources, $binId));
        }

        if ($bin instanceof WarehouseLocation) {
            $this->addFlash('info', sprintf('At %s. Everything scanned now is recorded as coming off that shelf.', $bin->getCode()));

            return $this->redirectToRoute('admin_bundle_warehouse_ops_pick_list_scan', $this->scanState($id, $counted, $sources, $bin->getId() ?? 0));
        }

        if (\count($products) > 1) {
            $this->addFlash('error', (string) $this->lookup->describeAmbiguity($code));

            return $this->redirectToRoute('admin_bundle_warehouse_ops_pick_list_scan', $this->scanState($id, $counted, $sources, $binId));
        }

        if ($products === []) {
            $this->addFlash('error', sprintf(
                '%s is not a bin in %s and is not a SKU or a barcode on any product. Nothing was recorded.',
                $code,
                $list->getWarehouse()->getName(),
            ));

            return $this->redirectToRoute('admin_bundle_warehouse_ops_pick_list_scan', $this->scanState($id, $counted, $sources, $binId));
        }

        $product = $products[0];
        $task = $this->taskWanting($tasks, $product, $counted);

        if (!$task instanceof PickTask) {
            $this->addFlash('error', $this->onThisRound($tasks, $product)
                ? sprintf(
                    'Every unit of %s this round asks for has already been scanned. Nothing was recorded — a round cannot dispatch more than it was compiled for.',
                    $product->getSku() ?: $product->getName(),
                )
                : sprintf(
                    '%s is %s, which is not on %s. Nothing was recorded.',
                    $code,
                    $this->lookup->describe($code, $product),
                    $list->getNumber(),
                ));

            return $this->redirectToRoute('admin_bundle_warehouse_ops_pick_list_scan', $this->scanState($id, $counted, $sources, $binId));
        }

        $taskId = $task->getId() ?? 0;
        $counted[$taskId] = ($counted[$taskId] ?? 0) + 1;
        if ($binId > 0) {
            $sources[$taskId] = $binId;
        }

        $this->addFlash('success', sprintf(
            '%s — %d of %d for %s.',
            $this->lookup->describe($code, $product),
            $counted[$taskId],
            $task->getQuantityRequested(),
            $task->getOrderNumber(),
        ));

        return $this->redirectToRoute('admin_bundle_warehouse_ops_pick_list_scan', $this->scanState($id, $counted, $sources, $binId));
    }

    /**
     * The first task on the round that still wants some of this product, in route order.
     *
     * A product can be on the round several times — once per order line that asked for it — and a
     * carton in somebody's hand cannot say which customer it is for. Filling in route order is what a
     * picker does anyway: the round is walked in sequence and the split between orders is settled at
     * the staging bench. Null when every task for it is already full, which is a refusal rather than
     * an overflow into the next one.
     *
     * @param list<PickTask>  $tasks
     * @param array<int, int> $counted
     */
    private function taskWanting(array $tasks, ProductCore $product, array $counted): ?PickTask
    {
        foreach ($tasks as $task) {
            if ($task->getProduct() !== $product) {
                continue;
            }

            if (($counted[$task->getId() ?? 0] ?? 0) < $task->getQuantityRequested()) {
                return $task;
            }
        }

        return null;
    }

    /** @param list<PickTask> $tasks */
    private function onThisRound(array $tasks, ProductCore $product): bool
    {
        foreach ($tasks as $task) {
            if ($task->getProduct() === $product) {
                return true;
            }
        }

        return false;
    }

    /**
     * The running tally, read from whichever side of the request carries it and clamped to this
     * round's own tasks.
     *
     * Clamped because the tally is in the URL, where anybody can type: a count against a task on
     * someone else's round, or a count above what the round asked for, is not something a scanner
     * can produce and is dropped rather than carried into the confirmation.
     *
     * @param list<PickTask> $tasks
     *
     * @return array<int, int>
     */
    private function countedFromRequest(Request $request, array $tasks): array
    {
        $raw = $request->isMethod('POST') ? $request->request->all('counted') : $request->query->all('counted');

        $counted = [];
        foreach ($tasks as $task) {
            $id = $task->getId() ?? 0;
            $value = $raw[$id] ?? null;

            if (\is_scalar($value) && ctype_digit(trim((string) $value))) {
                $quantity = min((int) trim((string) $value), $task->getQuantityRequested());
                if ($quantity > 0) {
                    $counted[$id] = $quantity;
                }
            }
        }

        return $counted;
    }

    /**
     * Which bin each task's units were scanned off. Same clamping, same reason.
     *
     * @param list<PickTask> $tasks
     *
     * @return array<int, int>
     */
    private function sourcesFromRequest(Request $request, array $tasks): array
    {
        $raw = $request->isMethod('POST') ? $request->request->all('source') : $request->query->all('source');

        $sources = [];
        foreach ($tasks as $task) {
            $id = $task->getId() ?? 0;
            $value = $raw[$id] ?? null;

            if (!\is_scalar($value) || !ctype_digit(trim((string) $value))) {
                continue;
            }

            $bin = $this->binOrNull((int) trim((string) $value));
            if ($bin instanceof WarehouseLocation && $bin->getWarehouse()->getId() === $task->getPickList()->getWarehouse()->getId()) {
                $sources[$id] = $bin->getId() ?? 0;
            }
        }

        return $sources;
    }

    /**
     * @param array<int, int> $counted
     * @param array<int, int> $sources
     *
     * @return array<string, mixed>
     */
    private function scanState(int $id, array $counted, array $sources, int $binId): array
    {
        $state = ['id' => $id, 'counted' => $counted, 'source' => $sources];

        if ($binId > 0) {
            $state['bin'] = $binId;
        }

        return $state;
    }

    /**
     * Confirms what was actually picked, task by task.
     *
     * One submission can confirm the whole round, which is how a paper printout comes back. Each
     * task is confirmed independently so one refusal does not lose the other nineteen — a picker who
     * walked the aisle should not have to walk it again because a SKU was switched back to simple
     * inventory an hour ago.
     */
    #[Route('/{id}/confirm', name: 'admin_bundle_warehouse_ops_pick_list_confirm', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function confirm(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $list = $this->listOr404($id);

        if (!$list->isOpen()) {
            $this->addFlash('error', sprintf('%s is %s; nothing more can be confirmed against it.', $list->getNumber(), $list->getStatus()));

            return $this->redirectToRoute('admin_bundle_warehouse_ops_pick_list', ['id' => $id]);
        }

        /** @var array<int|string, mixed> $picked */
        $picked = $request->request->all('picked');
        /** @var array<int|string, mixed> $pickedFrom */
        $pickedFrom = $request->request->all('picked_from');
        $operationKey = $this->operationKey($request);

        $movedTotal = 0;
        $missingTotal = 0;

        foreach ($picked as $taskId => $quantity) {
            $task = $this->em->find(PickTask::class, (int) $taskId);
            if (!$task instanceof PickTask || $task->getPickList()->getId() !== $list->getId()) {
                continue;
            }
            if (!\is_scalar($quantity) || trim((string) $quantity) === '') {
                continue;
            }

            // Where the picker says these came off (#591). A row that says nothing sends nothing,
            // and the service reads that as silence rather than as "no bin" — see its
            // declaredSource(). A row that names a bin in another warehouse is refused outright
            // rather than being quietly downgraded to silence: the picker meant something by it,
            // and guessing what is how this whole class of bug started.
            $from = null;
            $named = $pickedFrom[$taskId] ?? null;

            if (\is_scalar($named) && trim((string) $named) !== '') {
                $named = trim((string) $named);

                if ($named === '0') {
                    $from = PickSource::unbinned();
                } elseif (ctype_digit($named)) {
                    $bin = $this->binOrNull((int) $named);

                    if (!$bin instanceof WarehouseLocation || $bin->getWarehouse()->getId() !== $list->getWarehouse()->getId()) {
                        $this->addFlash('error', sprintf(
                            '%s: the bin named as the source is not in %s, so nothing was confirmed for that row.',
                            $task->getSku() ?: $task->getName(),
                            $list->getWarehouse()->getName(),
                        ));

                        continue;
                    }

                    $from = PickSource::bin($bin);
                } else {
                    $this->addFlash('error', sprintf(
                        '%s: the source bin was not a bin, so nothing was confirmed for that row.',
                        $task->getSku() ?: $task->getName(),
                    ));

                    continue;
                }
            }

            try {
                // Keyed per task, so re-submitting the printout applies each task once — and so two
                // tasks in one submission do not collide on a single key and silently skip the
                // second. Warehouse wi-fi is bad; this is the retry story.
                $confirmation = $this->confirmations->confirm(
                    $task,
                    (int) $quantity,
                    $operationKey === null ? null : sprintf('%s-t%d', $operationKey, $task->getId() ?? 0),
                    $this->actor(),
                    $from,
                );

                $movedTotal += $confirmation->picked;
                $missingTotal += $confirmation->missing;

                if ($confirmation->outstanding > 0) {
                    $this->addFlash('info', sprintf(
                        '%s still needs %d unit(s) of %s — top it up from another lot or backorder it.',
                        $task->getOrderNumber(),
                        $confirmation->outstanding,
                        $task->getSku() ?: $task->getName(),
                    ));
                }

                // Said out loud rather than acted on (#589). The advice above — top it up from
                // another lot — is only honest while the other lots are still there, and they are
                // only still there because a shortfall this service cannot pin on a bin is now
                // reported instead of written off. Somebody has to go and count; this is what asks.
                // Typed more than the named shelf holds (#591). Nothing was taken from anywhere
                // else to make the number up, and this is where that is said — otherwise the
                // difference reads as an ordinary shortfall and the bin they actually emptied
                // never gets counted.
                if ($confirmation->unmoved > 0) {
                    $this->addFlash('info', sprintf(
                        '%s: %d unit(s) were recorded as found at %s, which the system shows holding %d. The %d it had moved to staging; the rest were NOT taken off another bin, because a count at one shelf is no evidence about another. They are still owed — compile another round for them once this one is closed, or move them on the Inventory Depth adjustment screen.',
                        $task->getSku() ?: $task->getName(),
                        $confirmation->picked + $confirmation->unmoved,
                        $confirmation->source?->describe() ?? 'that bin',
                        $confirmation->picked,
                        $confirmation->picked,
                    ));
                }

                if ($confirmation->unattributed > 0) {
                    $this->addFlash('info', sprintf(
                        '%s: this task names no bin, so the %d unit(s) not found were NOT written off — the system still shows them on hand. Count the product and adjust it if they are gone.',
                        $task->getSku() ?: $task->getName(),
                        $confirmation->unattributed,
                    ));
                }
            } catch (PickConfirmationException|InsufficientStockException|\InvalidArgumentException $e) {
                $this->addFlash('error', sprintf('%s: %s', $task->getSku() ?: $task->getName(), $e->getMessage()));
            }
        }

        $this->addFlash('success', sprintf(
            '%d unit(s) picked to %s. %s',
            $movedTotal,
            $list->getStagingLocation()?->getCode() ?? 'staging',
            $missingTotal > 0
                ? sprintf('%d unit(s) the system expected were not in the bin they were picked from, and were written off as a separate adjustment. Stock of the same product in other bins is untouched.', $missingTotal)
                : 'Everything the list asked for was there.',
        ));

        return $this->redirectToRoute('admin_bundle_warehouse_ops_pick_list', ['id' => $id]);
    }

    #[Route('/{id}/close', name: 'admin_bundle_warehouse_ops_pick_list_close', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function close(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $list = $this->listOr404($id);
        $cancel = $request->request->get('action') === 'cancel';

        if (!$list->isOpen()) {
            $this->addFlash('error', sprintf('%s is already %s.', $list->getNumber(), $list->getStatus()));

            return $this->redirectToRoute('admin_bundle_warehouse_ops_pick_list', ['id' => $id]);
        }

        // Closing writes no movements. Whatever was picked has already been recorded, and whatever
        // was not is still on the shelf — a round is paperwork, and closing paperwork must never be
        // a stock event.
        $list->setStatus($cancel ? PickList::STATUS_CANCELLED : PickList::STATUS_CLOSED)
            ->setClosedAt(new \DateTimeImmutable());

        $this->em->flush();
        $this->addFlash('success', sprintf('%s %s. No stock moved — closing a round is paperwork.', $list->getNumber(), $cancel ? 'cancelled' : 'closed'));

        return $this->redirectToRoute('admin_bundle_warehouse_ops_pick_list', ['id' => $id]);
    }

    /**
     * Deletes a round that never picked anything (#613).
     *
     * A pick list is a plan, not a claim on stock — the entity says so, and it is what makes a
     * delete possible here at all: compiling reserves nothing, and everything a pick ever DID is in
     * `inventory_movement_group`, written by StockMovementService like every other change. Deleting
     * an unpicked round loses no stock information whatsoever.
     *
     * Two refusals, and they are different questions:
     *
     *  - **an open round** (`draft` or `released`) is one somebody may be walking. Close or cancel
     *    it first — a picker holding a printout for a list that no longer exists is how a round gets
     *    walked twice.
     *  - **any confirmed pick**, i.e. `SUM(pick_task.quantity_picked) > 0`, means stock has already
     *    moved to the staging bin under this number. The movement group keeps its `reference`, so
     *    deleting the list would leave the ledger naming a document nobody can look up.
     *
     * `pick_task` cascades on `pick_list_id`, which is exactly why both refusals run before the
     * DELETE rather than being left to the database to not enforce.
     */
    #[Route('/{id}/delete', name: 'admin_bundle_warehouse_ops_pick_list_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id): Response
    {
        $this->denyIfInactive();

        $list = $this->listOr404($id);

        if ($list->isOpen()) {
            $this->addFlash('error', sprintf(
                '%s is %s and somebody may be walking it. Close or cancel the round first — deleting a list a picker is holding a printout for is how one gets walked twice.',
                $list->getNumber(),
                $list->getStatus(),
            ));

            return $this->redirectToRoute('admin_bundle_warehouse_ops_pick_list', ['id' => $id]);
        }

        $picked = $list->pickedUnits();
        if (QuantityScale::compare($picked, 0) > 0) {
            $this->addFlash('error', sprintf(
                '%s cannot be deleted: pick_task.quantity_picked still totals %s unit(s), so stock has already moved to staging under this number and inventory_movement_group.reference names it. Deleting it would leave the ledger pointing at a document nobody can look up.',
                $list->getNumber(),
                (new DisplayNumber())->qty($picked),
            ));

            return $this->redirectToRoute('admin_bundle_warehouse_ops_pick_list', ['id' => $id]);
        }

        $number = $list->getNumber();
        $tasks = $list->getTasks()->count();

        $this->em->remove($list);
        $this->em->flush();

        $this->addFlash('success', sprintf(
            '%s deleted (pick_list row %d, with its %d pick_task row(s)). Nothing was picked against it, so no movement lost its document.',
            $number,
            $id,
            $tasks,
        ));

        return $this->redirectToRoute('admin_bundle_warehouse_ops_pick_lists');
    }

    /** The printable round: bins in walking order, nothing else on the page. */
    #[Route('/{id}/print', name: 'admin_bundle_warehouse_ops_pick_list_print', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function print(int $id): Response
    {
        $this->denyIfInactive();

        $list = $this->listOr404($id);

        return $this->render('@WarehouseOps/pick_list_print.html.twig', [
            'list' => $list,
            'tasks' => $this->tasks->inRouteOrder($list),
        ]);
    }

    /**
     * The bins a picker could honestly say each task's units came off, for the confirm form (#591).
     *
     * ## Why the screen offers a list rather than a free-text bin code
     *
     * The picker is answering a question about a shelf they were standing at, so the answer has to
     * be a shelf that exists in this warehouse — and the interesting answers are the ones that
     * currently hold the product, because those are the ones a pick can actually be sourced from.
     * Anything else typed into a text box is a support ticket.
     *
     * ## What is in it, and what is deliberately not
     *
     *  - **Every bin holding available stock of that product in this warehouse**, quantity shown, in
     *    walking order — `warehouse_location.sort_key`, the same order the round itself is in.
     *  - **The task's suggested bin**, always, pre-selected, even when it now holds nothing. It is
     *    what the printout says, so it has to be the default; a picker confirming a round without
     *    touching the control is agreeing with the paper, which is the ordinary case and must be one
     *    fewer decision rather than one more. Showing it at zero is also the screen telling them
     *    something true before they type.
     *  - **"no bin"**, only when unbinned rows actually hold some. `inventory_detail.location_id` is
     *    nullable and stock genuinely sits there — an opening balance nobody has put away — so it
     *    must be nameable, or that stock could never be picked. It is not offered when there is
     *    none, because it would then read as "leave it blank" and mean the opposite.
     *  - **NOT the staging bin.** Picked stock goes there; it does not come from there. The service
     *    refuses it as well, and this is why nobody should have to find that out.
     *
     * The lookup goes through InventoryDetailRepository::pickableRows() — the same query the
     * compiler and the confirmation use — so the bins offered here cannot disagree with the bins the
     * pick can be drawn from. It is one query per distinct product on the round, not per task.
     *
     * @param list<PickTask> $tasks
     *
     * @return array<int, list<array{value: string, label: string, selected: bool}>> task id => options
     */
    private function sourceChoices(PickList $list, array $tasks): array
    {
        $staging = $list->getStagingLocation();
        $byProduct = [];
        $choices = [];

        foreach ($tasks as $task) {
            $taskId = $task->getId();
            $product = $task->getProduct();

            if ($taskId === null) {
                continue;
            }

            $choices[$taskId] = [];

            if (!$product instanceof ProductCore) {
                continue;
            }

            $productId = $product->getId() ?? 0;

            if (!isset($byProduct[$productId])) {
                $bins = [];
                $unbinned = 0;

                foreach ($this->details->pickableRows($product, $list->getWarehouse()) as $row) {
                    if (!$row instanceof InventoryDetail) {
                        continue;
                    }

                    $bin = $row->getLocation();

                    if (!$bin instanceof WarehouseLocation) {
                        $unbinned += $row->getQuantity();
                        continue;
                    }

                    if ($staging instanceof WarehouseLocation && $bin->getId() === $staging->getId()) {
                        continue;
                    }

                    $binId = (int) $bin->getId();
                    $bins[$binId] ??= ['bin' => $bin, 'quantity' => 0];
                    $bins[$binId]['quantity'] += $row->getQuantity();
                }

                uasort($bins, static fn (array $a, array $b): int
                    => [$a['bin']->getSortKey(), $a['bin']->getCode()] <=> [$b['bin']->getSortKey(), $b['bin']->getCode()]);

                $byProduct[$productId] = ['bins' => $bins, 'unbinned' => $unbinned];
            }

            $suggested = $task->getSuggestedLocation();
            $suggestedId = $suggested instanceof WarehouseLocation ? (int) $suggested->getId() : 0;
            $options = [];

            foreach ($byProduct[$productId]['bins'] as $binId => $entry) {
                $options[] = [
                    'value' => (string) $binId,
                    'label' => sprintf('%s — %d on hand', $entry['bin']->getCode(), $entry['quantity']),
                    'selected' => $binId === $suggestedId,
                ];
            }

            // The printout's bin, even when the shelf is empty now: the default has to match the
            // paper the picker is holding.
            if ($suggested instanceof WarehouseLocation && !isset($byProduct[$productId]['bins'][$suggestedId])) {
                array_unshift($options, [
                    'value' => (string) $suggestedId,
                    'label' => sprintf('%s — 0 on hand', $suggested->getCode()),
                    'selected' => true,
                ]);
            }

            if ($byProduct[$productId]['unbinned'] > 0) {
                $options[] = [
                    'value' => '0',
                    'label' => sprintf('no bin — %d on hand', $byProduct[$productId]['unbinned']),
                    'selected' => false,
                ];
            }

            $choices[$taskId] = $options;
        }

        return $choices;
    }

    private function listOr404(int $id): PickList
    {
        $list = $this->em->find(PickList::class, $id);
        if (!$list instanceof PickList) {
            throw new NotFoundHttpException('No such pick list.');
        }

        return $list;
    }

    /** @return list<WarehouseLocation> */
    private function stagingBins(Warehouse $warehouse): array
    {
        /** @var list<WarehouseLocation> $rows */
        $rows = $this->em->getRepository(WarehouseLocation::class)->findBy(
            ['warehouse' => $warehouse, 'status' => 'Active'],
            ['sortKey' => 'ASC', 'code' => 'ASC'],
        );

        // Staging bins first, then everything else: #550 already has a `staging` bin type and this
        // is what it is for, but a warehouse that never created one must not be stuck.
        usort($rows, static fn (WarehouseLocation $a, WarehouseLocation $b): int
            => ($b->getType() === WarehouseLocation::TYPE_STAGING ? 1 : 0) <=> ($a->getType() === WarehouseLocation::TYPE_STAGING ? 1 : 0));

        return $rows;
    }

    /**
     * The orders this round is picking for, and how much of each is sitting in the staging bin.
     *
     * Shown because a picker and a packer are not always the same person: the packer needs to know
     * whose boxes are on the bench. It is not a shipping control — see the class docblock.
     *
     * @return list<array{order: ?SalesOrder, number: string, picked: int}>
     */
    private function ordersOn(PickList $list): array
    {
        $byNumber = [];

        foreach ($list->getTasks() as $task) {
            $number = $task->getOrderNumber();
            $byNumber[$number] ??= ['order' => $task->getOrder(), 'number' => $number, 'picked' => 0];
            $byNumber[$number]['picked'] += $task->getQuantityPicked();
        }

        return array_values($byNumber);
    }
}
