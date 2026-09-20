<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AdminColumnPreference;
use App\Entity\AdminUser;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Repository\AdminColumnPreferenceRepository;
use App\Service\DisplayNumber;
use App\Service\Inventory\BackorderReleaseService;
use App\Service\Inventory\CoreInventoryTotalCountService;
use App\Service\Inventory\InventoryGridColumns;
use App\Service\Inventory\InventoryModeResolver;
use App\Service\QuantityScale;
use App\Validation\Constraint\ValidInventoryUpdate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/admin')]
final class InventoryController extends AbstractAdminController
{
    #[Route('/inventory', name: 'admin_inventory_index', methods: ['GET'])]
    public function index(
        EntityManagerInterface $entityManager,
        Request $request,
        InventoryModeResolver $inventoryMode,
        InventoryGridColumns $gridColumns,
        AdminColumnPreferenceRepository $columnPrefRepo,
    ): Response {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = $request->query->getInt('limit', 100);

        /** @var array<string, mixed> $rawFilters */
        $rawFilters = $request->query->all('filters');
        $filters = [
            'id' => trim((string) ($rawFilters['id'] ?? '')),
            'name' => trim((string) ($rawFilters['name'] ?? '')),
            'sku' => trim((string) ($rawFilters['sku'] ?? '')),
            'category' => trim((string) ($rawFilters['category'] ?? '')),
            // A LIST, unlike the four above, because the answer to "which warehouse" is routinely
            // "these two". Kept in the query string with the rest of the filters — it narrows what
            // you are looking at now, so it has to be linkable, bookmarkable and reversible with
            // the back button. The COLUMN choice is the other kind of decision and lives in a saved
            // preference; see InventoryGridColumns::VIEW_KEY for that argument.
            'warehouses' => $this->warehouseIdFilter($rawFilters),
        ];

        $allWarehouses = $entityManager->getRepository(Warehouse::class)->findBy(['status' => 'Active'], ['name' => 'ASC']);

        // An id in the filter that names no active warehouse narrows to nothing rather than being
        // ignored: silently widening a filter shows stock the person asked not to see.
        $warehouses = $filters['warehouses'] === []
            ? $allWarehouses
            : array_values(array_filter(
                $allWarehouses,
                static fn (Warehouse $w): bool => \in_array($w->getId(), $filters['warehouses'], true),
            ));

        $admin = $this->getUser();
        $visibleColumns = $gridColumns->resolve(
            $admin instanceof AdminUser ? $columnPrefRepo->resolveVisibleColumns($admin, InventoryGridColumns::VIEW_KEY) : null,
        );

        $qb = $entityManager->getRepository(ProductCore::class)->createQueryBuilder('p')
            ->where('p.deleted = false');

        $needsCategoryJoin = false;

        if ($filters['id'] !== '' && ctype_digit($filters['id'])) {
            $qb->andWhere('p.id = :fid')->setParameter('fid', (int) $filters['id']);
        }
        if ($filters['name'] !== '') {
            $qb->andWhere('p.name LIKE :fname')->setParameter('fname', '%' . $filters['name'] . '%');
        }
        if ($filters['sku'] !== '') {
            $qb->andWhere('p.sku LIKE :fsku')->setParameter('fsku', '%' . $filters['sku'] . '%');
        }
        // Same convention as the Detail and Price grids: the category filter is a select of
        // category ids (issue #424), so this is always an exact id match.
        if ($filters['category'] !== '') {
            $needsCategoryJoin = true;
            $qb->andWhere('cat.id = :fcatId')->setParameter('fcatId', (int) $filters['category']);
        }

        $sort = trim((string) $request->query->get('sort', 'id'));
        $dir = strtolower(trim((string) $request->query->get('dir', 'asc'))) === 'desc' ? 'DESC' : 'ASC';

        $orderExpr = match ($sort) {
            'name' => 'p.name',
            'sku' => 'p.sku',
            'category' => 'cat.name',
            default => 'p.id',
        };

        if ($sort === 'category') {
            $needsCategoryJoin = true;
        }

        if ($needsCategoryJoin) {
            $qb->leftJoin('p.category', 'cat');
        }

        if (str_starts_with($sort, 'warehouse_')) {
            $warehouseId = (int) substr($sort, 10);
            $qb->leftJoin(
                ProductInventory::class,
                'inv_sort',
                'WITH',
                'inv_sort.product = p AND inv_sort.warehouse = :sortWarehouseId'
            )->setParameter('sortWarehouseId', $warehouseId);
            $orderExpr = 'CASE WHEN inv_sort.quantity IS NULL THEN 0 ELSE inv_sort.quantity END';
        }

        $totalQuery = clone $qb;
        $total = (int) $totalQuery->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();

        $products = $qb->select('p')
            ->orderBy($orderExpr, $dir)
            ->addOrderBy('p.id', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $rows = $this->buildRows($products, $warehouses, $entityManager, $inventoryMode, $gridColumns, $visibleColumns);

        $registry = $gridColumns->all();

        return $this->render('admin/inventory/index.html.twig', [
            'rows' => $rows,
            'warehouses' => $warehouses,
            'allWarehouses' => $allWarehouses,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'filters' => $filters,
            'currentSort' => $sort,
            'currentDir' => strtolower($dir),
            'categories' => $this->categoryRowsFromDatabase($entityManager),
            'columnRegistry' => $registry,
            'visibleColumns' => $visibleColumns,
            // The registry entries for the visible keys, in render order — so the header row, the
            // filter row's colspan, the body cells and the empty state's colspan are all driven by
            // ONE list. Three numbers written forty lines apart that must agree is how the previous
            // version of this grid could shear every warehouse group off by one.
            'columns' => array_map(
                static fn (string $key): array => $registry[$key] + ['key' => $key],
                $visibleColumns,
            ),
        ]);
    }

    /**
     * The warehouse ids in `filters[warehouses][]`, as ints.
     *
     * Accepts a single scalar as well as an array so a hand-written `?filters[warehouses]=3` works
     * the way the four text filters beside it do. Anything that is not a positive integer is
     * dropped rather than coerced to 0, which would match no warehouse and read as "show nothing"
     * when the person typed a typo.
     *
     * @param array<string, mixed> $rawFilters
     * @return list<int>
     */
    private function warehouseIdFilter(array $rawFilters): array
    {
        $raw = $rawFilters['warehouses'] ?? [];
        $raw = \is_array($raw) ? $raw : [$raw];

        $ids = [];
        foreach ($raw as $value) {
            $value = trim((string) $value);
            if ($value !== '' && ctype_digit($value) && (int) $value > 0) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The column chooser's save (#36).
     *
     * A plain POST of checkboxes to a route that redirects back, so it works with JavaScript off —
     * which is not a nicety here: this grid can be nineteen columns wide per warehouse, and a
     * chooser that only opens under JS would leave a no-JS admin with whichever set somebody else
     * last saved and no way to change it. The disclosure that reveals the form is a <details>
     * element for the same reason.
     *
     * Unlike the Product Detail chooser there is no "apply to all users" here. That one is a
     * Tech-Support power over a screen every admin uses the same way; which stock buckets you want
     * in front of you depends on what you do — receiving, selling, transfers — so a site-wide
     * default would be one team overwriting another's screen.
     */
    #[Route('/inventory/columns', name: 'admin_inventory_columns_save', methods: ['POST'])]
    public function saveColumns(
        Request $request,
        EntityManagerInterface $entityManager,
        InventoryGridColumns $gridColumns,
        AdminColumnPreferenceRepository $columnPrefRepo,
    ): Response {
        /** @var AdminUser $admin */
        $admin = $this->getUser();

        $submitted = $request->request->all('columns');
        $chosen = $gridColumns->sanitize(\is_array($submitted) ? $submitted : []);

        $pref = $columnPrefRepo->findForAdmin($admin, InventoryGridColumns::VIEW_KEY)
            ?? (new AdminColumnPreference())->setAdmin($admin)->setViewKey(InventoryGridColumns::VIEW_KEY);

        $pref->setColumns($chosen)->touch();
        $entityManager->persist($pref);
        $entityManager->flush();

        // Saving nothing is a real choice to make and a bad one to honour: resolve() falls back to
        // the defaults rather than rendering a grid with no columns, and saying so here is what
        // stops that reading as the save having failed.
        $this->addFlash('success', $chosen === []
            ? 'No columns were selected, so the grid is showing its default set.'
            : 'Your column choices were saved.');

        return $this->redirectToRoute('admin_inventory_index', $this->carriedQuery($request));
    }

    /**
     * The grid's own query string, carried back across the chooser's redirect.
     *
     * The chooser is a POST to a different route, so without this a save drops whatever filter,
     * sort or page the person was on and lands them at the top of an unfiltered grid. The form
     * posts these as hidden fields; they are re-read here rather than trusted wholesale, so a
     * crafted POST cannot redirect anywhere but back to this screen.
     *
     * @return array<string, mixed>
     */
    private function carriedQuery(Request $request): array
    {
        $query = [];

        $sort = trim((string) $request->request->get('sort', ''));
        if ($sort !== '') {
            $query['sort'] = $sort;
        }

        $dir = strtolower(trim((string) $request->request->get('dir', '')));
        if (\in_array($dir, ['asc', 'desc'], true)) {
            $query['dir'] = $dir;
        }

        $limit = $request->request->getInt('limit', 0);
        if ($limit > 0) {
            $query['limit'] = $limit;
        }

        /** @var array<string, mixed> $filters */
        $filters = $request->request->all('filters');
        $carried = [];
        foreach (['id', 'name', 'sku', 'category'] as $key) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $carried[$key] = $value;
            }
        }
        $warehouses = $this->warehouseIdFilter($filters);
        if ($warehouses !== []) {
            $carried['warehouses'] = $warehouses;
        }
        if ($carried !== []) {
            $query['filters'] = $carried;
        }

        return $query;
    }

    #[Route('/inventory/update', name: 'admin_inventory_update', methods: ['POST'])]
    public function update(Request $request, EntityManagerInterface $entityManager, ValidatorInterface $validator, BackorderReleaseService $releaseService, InventoryModeResolver $inventoryMode, DisplayNumber $displayNumber, CoreInventoryTotalCountService $totalCounts): JsonResponse
    {
        $productId = $request->request->getInt('product_id', 0);
        $warehouseId = $request->request->getInt('warehouse_id', 0);
        $raw = trim((string) $request->request->get('quantity', ''));

        // Delegated to the ValidationExceptionSubscriber (issue #308) rather than hand-picking a
        // status per check: id presence and quantity format are both validation failures, so both
        // now share the same uniform JSON handler instead of each inventing its own response.
        $violations = $validator->validate($raw, new ValidInventoryUpdate($productId, $warehouseId));
        if (count($violations) > 0) {
            throw new ValidationFailedException($raw, $violations);
        }

        $product = $entityManager->find(ProductCore::class, $productId);
        $warehouse = $entityManager->find(Warehouse::class, $warehouseId);
        if (!$product instanceof ProductCore || !$warehouse instanceof Warehouse) {
            return new JsonResponse(['ok' => false, 'message' => 'Not found.'], Response::HTTP_NOT_FOUND);
        }

        $row = $entityManager->getRepository(ProductInventory::class)->findOneBy([
            'product' => $product,
            'warehouse' => $warehouse,
        ]) ?? (new ProductInventory())->setProduct($product)->setWarehouse($warehouse);

        // Read before the write, because whether this save is a RESTOCK — and therefore whether it
        // shrinks a cap or clears a queue — is the difference between the two numbers (#548).
        $previousQuantity = $row->getQuantity();

        // #550: a dimensional product's quantity is maintained by the depth layer, in the same
        // transaction as the movement that changes it, so writing it here would set a number the
        // next movement recomputes away. The grid does not offer the box at all for such a product;
        // this is the belt behind that, for a hand-made POST.
        //
        // Refused only when the POST actually asks to CHANGE it. This endpoint carries two jobs
        // since #548 — the quantity and the four backorder settings — and the settings JS echoes the
        // current quantity back with every settings change. Backorder settings are orthogonal to HOW
        // the quantity is maintained, so refusing them too would make them uneditable for exactly
        // the products someone bothered to set up properly.
        //
        // Never reached for a simple product, and never at all with no depth bundle installed.
        $isDimensional = $inventoryMode->isDimensional($product);

        if ($isDimensional && QuantityScale::compare($raw, $previousQuantity) !== 0) {
            return new JsonResponse([
                'ok' => false,
                'message' => 'This product is on dimensional inventory. Its quantity is maintained by its bin, lot and serial breakdown — adjust it there instead.',
                'breakdownUrl' => $inventoryMode->breakdownUrl($product, $warehouse),
            ], Response::HTTP_CONFLICT);
        }

        if (!$isDimensional) {
            // The same method import uses — CoreInventoryTotalCountService::setCount() — for the
            // same reason: this box and import both write product_inventory.quantity for the same
            // row, and the detail row behind it has to stay in sync either way. No bucket is
            // cleared here, unlike import: a single box being typed into is not a recount that
            // might already account for stock mid-transfer or quarantined, it is just a number.
            $row = $totalCounts->setCount($product, $warehouse, (int) $raw);
        }

        $row->touch();
        $this->applyBackorderSettings($request, $row);
        $entityManager->persist($row);

        // After the new quantity is on the row and before the flush, so a restock and the releases
        // it triggers commit together. Does nothing at all unless this row has opted in — which is
        // every row until an admin says otherwise.
        $released = $releaseService->applyRestock($row, $previousQuantity, $entityManager);

        $entityManager->flush();

        return new JsonResponse([
            'ok' => true,
            'quantity' => $displayNumber->qty($row->getQuantity()),
            'available' => $row->getAvailableQuantity(),
            'backordered' => $row->getBackorderedQuantity(),
            'allowBackorder' => $row->isAllowBackorder(),
            'maxBackorderQuantity' => $row->getMaxBackorderQuantity(),
            'backorderCapShrinksOnRestock' => $row->isBackorderCapShrinksOnRestock(),
            'autoReleaseOnRestock' => $row->isAutoReleaseOnRestock(),
            'released' => $released,
        ]);
    }

    /**
     * The four backorder settings, applied only when the request actually states them (#548).
     *
     * Absent means UNCHANGED, never false. The quantity cell and the settings are separate inline
     * edits on the same row, and the quantity one posts nothing else — without this rule, typing a
     * new Starting figure would silently switch backordering off for that SKU.
     *
     * The cap accepts a blank string as "no cap", which is a different thing from zero: zero means
     * backordering is allowed and nothing may currently use it.
     */
    private function applyBackorderSettings(Request $request, ProductInventory $row): void
    {
        if ($request->request->has('allow_backorder')) {
            $row->setAllowBackorder($request->request->getBoolean('allow_backorder'));
        }

        if ($request->request->has('max_backorder_quantity')) {
            $cap = trim((string) $request->request->get('max_backorder_quantity', ''));
            $row->setMaxBackorderQuantity($cap === '' ? null : (int) $cap);
        }

        if ($request->request->has('backorder_cap_shrinks_on_restock')) {
            $row->setBackorderCapShrinksOnRestock($request->request->getBoolean('backorder_cap_shrinks_on_restock'));
        }

        if ($request->request->has('auto_release_on_restock')) {
            $row->setAutoReleaseOnRestock($request->request->getBoolean('auto_release_on_restock'));
        }
    }

    /**
     * One square per (product, warehouse), with every column's figure and what it does to Available.
     *
     * The shape changed in #36: the row used to carry a hand-written list of seven named buckets,
     * which is exactly why the other seven never appeared — adding a bucket to the entity changed
     * nothing here, and nothing said so. Now every square carries `cells`, keyed by column, built
     * from {@see InventoryGridColumns} for ALL columns regardless of what is currently visible;
     * the template renders the visible subset. A bucket the registry does not know about has no
     * cell, and `InventoryGridBucketCoverageCest` is what fails when that happens.
     *
     * @param list<ProductCore> $products
     * @param list<Warehouse> $warehouses
     * @param list<string> $visibleColumns
     * @return list<array<string, mixed>>
     */
    private function buildRows(
        array $products,
        array $warehouses,
        EntityManagerInterface $entityManager,
        InventoryModeResolver $inventoryMode,
        InventoryGridColumns $gridColumns,
        array $visibleColumns,
    ): array {
        if ($products === []) {
            return [];
        }

        // Scoped to the warehouses actually being rendered, not every warehouse the product is
        // stocked in. Narrowing the grid is the whole point of the warehouse filter, and loading
        // the rows it excludes only to drop them again would make the filter cost more than it
        // saves. An empty set means the filter named no active warehouse, so there is nothing to
        // fetch at all — and `IN ()` is not a query.
        $inventoryRows = $warehouses === [] ? [] : $entityManager->getRepository(ProductInventory::class)->createQueryBuilder('inv')
            ->where('inv.product IN (:products)')
            ->andWhere('inv.warehouse IN (:warehouses)')
            ->setParameter('products', $products)
            ->setParameter('warehouses', $warehouses)
            ->getQuery()
            ->getResult();

        /** @var array<int, array<int, array<string, mixed>>> $map */
        $map = [];
        foreach ($inventoryRows as $inv) {
            if (!$inv instanceof ProductInventory || !$inv->getWarehouse() instanceof Warehouse) {
                continue;
            }
            $pid = $inv->getProduct()->getId();
            $rid = $inv->getWarehouse()->getId();
            if ($pid === null || $rid === null) {
                continue;
            }

            $cells = $gridColumns->cellsFor($inv);
            $map[$pid][$rid] = [
                'cells' => $cells,
                // NOT recomputed from the cells. getAvailableQuantity() is the definition, and a
                // second implementation of it here would be a place for the page to be confidently
                // wrong. The page adds the cells up and the test checks the two agree — which is a
                // real check precisely because the two are computed independently.
                'available' => $inv->getAvailableQuantity(),
                'offPage' => $gridColumns->offPageTotal($cells, $visibleColumns),
                'working' => $gridColumns->workingFor($cells, $inv->getAvailableQuantity(), $visibleColumns),
            ];
        }

        $emptyCells = $gridColumns->emptyCells();
        $empty = [
            'cells' => $emptyCells,
            'available' => 0,
            'offPage' => $gridColumns->offPageTotal($emptyCells, $visibleColumns),
            'working' => $gridColumns->workingFor($emptyCells, 0, $visibleColumns),
        ];

        $rows = [];
        foreach ($products as $product) {
            $pid = $product->getId();
            // Null for every product on simple inventory — which is every product until someone
            // opts one in, and every product again once a depth bundle is removed. A non-null value
            // is what swaps the editable box for a link to whatever maintains the number instead.
            $isDimensional = $inventoryMode->isDimensional($product);
            $qtyByWarehouse = [];
            foreach ($warehouses as $warehouse) {
                $rid = $warehouse->getId();
                if ($rid === null) {
                    continue;
                }
                $bucket = $map[$pid][$rid] ?? $empty;
                $bucket['breakdownUrl'] = $isDimensional ? $inventoryMode->breakdownUrl($product, $warehouse) : null;
                $qtyByWarehouse[$rid] = $bucket;
            }

            $rows[] = [
                'id' => $pid,
                'name' => $product->getName(),
                'sku' => $product->getSku(),
                'category' => $product->getCategory() !== null ? $this->categoryPath($product->getCategory()) : '-',
                'qtyByWarehouse' => $qtyByWarehouse,
            ];
        }

        return $rows;
    }
}
