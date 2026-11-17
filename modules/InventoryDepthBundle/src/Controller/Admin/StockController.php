<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Controller\Admin;

use App\Entity\AbstractPartyNote;
use App\Entity\AdminUser;
use App\Entity\CreditMemoLine;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\ProductNote;
use App\Entity\SalesOrderLine;
use App\Entity\TrackingPolicy;
use App\Entity\Warehouse;
use App\Repository\BundleStatusRepository;
use App\Repository\TrackingPolicyRepository;
use App\Service\Inventory\InventoryModeResolver;
use App\Service\Inventory\ProductActivityFeedResolver;
use App\Service\Inventory\ProductVendorResolver;
use App\Service\Product\ProductBarcodeSearchResolver;
use App\Service\Product\ProductPicker;
use App\Service\QuantityScale;
use App\Service\TextInput;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\InventoryMovement;
use InventoryDepthBundle\Entity\ProductReorderRule;
use InventoryDepthBundle\Entity\ShipmentLine;
use InventoryDepthBundle\Entity\WarehouseLocation;
use InventoryDepthBundle\Reorder\ReorderPointRule;
use InventoryDepthBundle\Repository\InventoryLotRepository;
use InventoryDepthBundle\Repository\ProductReorderRuleRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The two reading screens (#550): what is behind one product's number, and what is in one bin.
 *
 * They are counterparts rather than variations — one walks the product, the other walks the shelf —
 * and a cycle count is run from the second.
 */
#[Route('/admin/bundles/inventory-depth')]
final class StockController extends AbstractInventoryDepthController
{
    /**
     * The Recent Activity feed's row types, in the order the type filter offers them. "Order" also
     * covers invoice-sourced rows (docs/plans/2026-09-15-product-inventory-hub.md's own grouping) —
     * there is no separate "Invoice" tag, and Orders/Invoices/Purchase Orders/Credit Memos/Movements
     * is where the id-based drill-through filters landed (see build order step 1).
     */
    private const ACTIVITY_TYPES = ['order', 'purchase', 'shipment', 'creditmemo', 'movement'];

    public function __construct(
        EntityManagerInterface $em,
        BundleStatusRepository $bundleStatusRepo,
        InventoryModeResolver $inventoryModes,
        private readonly ReorderPointRule $reorderPointRule,
        private readonly ProductActivityFeedResolver $activityFeed,
        private readonly ProductVendorResolver $vendors,
        private readonly ProductPicker $productPicker,
        private readonly ProductBarcodeSearchResolver $barcodeSearch,
        private readonly TrackingPolicyRepository $trackingPolicies,
    ) {
        parent::__construct($em, $bundleStatusRepo, $inventoryModes);
    }

    /** The landing screen, and the bundle descriptor's edit route. */
    #[Route('', name: 'admin_bundle_inventory_depth_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyIfInactive();

        $dimensionalProducts = (int) $this->em->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(ProductCore::class, 'p')
            ->where('p.inventoryMode = :mode')->setParameter('mode', ProductCore::INVENTORY_MODE_DIMENSIONAL)
            ->andWhere('p.deleted = false')
            ->getQuery()
            ->getSingleScalarResult();

        return $this->render('@InventoryDepth/index.html.twig', [
            'dimensionalProducts' => $dimensionalProducts,
            'binCount' => (int) $this->em->createQueryBuilder()->select('COUNT(l.id)')->from(WarehouseLocation::class, 'l')->getQuery()->getSingleScalarResult(),
            'lotCount' => (int) $this->em->createQueryBuilder()->select('COUNT(l.id)')->from(InventoryLot::class, 'l')->getQuery()->getSingleScalarResult(),
        ]);
    }

    /**
     * The Product Inventory Hub's own front door: "I wanted to lookup a SKU and don't have a place
     * to go" (docs/plans/2026-09-15-product-inventory-hub.md's own gap statement). A bare SKU/name
     * search, GET so a looked-up query is a copyable URL like every other screen here — the same
     * shape as `serialLookup()` above, one dimension wider (many products can share a partial match,
     * a serial cannot).
     *
     * An exact SKU match goes straight to that product's hub; anything else — a partial match, more
     * than one exact match (SKUs are not enforced unique across every sync source), or no match at
     * all — lists what matched instead of guessing.
     */
    #[Route('/stock/product', name: 'admin_bundle_inventory_depth_product_lookup', methods: ['GET'])]
    public function productLookup(Request $request): Response
    {
        $this->denyIfInactive();

        $q = trim((string) $request->query->get('q', ''));

        if ($q === '') {
            return $this->render('@InventoryDepth/product_lookup.html.twig', ['q' => $q, 'results' => []]);
        }

        // #707: one shared search (ProductPicker::searchPage()) instead of this screen's own
        // name/SKU LIKE — which also means a scanned barcode is searched here for free, same as it
        // now is everywhere else that shares this class.
        $results = $this->productPicker->searchPage($q)['products'];

        $exactSkuMatches = array_values(array_filter($results, static fn (ProductCore $p): bool => strcasecmp($p->getSku(), $q) === 0));
        if (count($exactSkuMatches) === 1) {
            return $this->redirectToRoute('admin_bundle_inventory_depth_stock_by_product', ['id' => $exactSkuMatches[0]->getId()]);
        }

        // A full barcode scan is just as unambiguous an identifier as an exact SKU, and lands the
        // same way — the whole point of adding barcode matching to a screen literally named "look
        // a product up." $results is already narrowed to $q's matches, so codesForProducts() is one
        // small query, not a second full-catalog scan.
        $codesByProduct = $this->barcodeSearch->codesForProducts(array_map(
            static fn (ProductCore $p): int => (int) $p->getId(),
            $results,
        ));
        $exactBarcodeMatches = array_values(array_filter($results, static function (ProductCore $p) use ($codesByProduct, $q): bool {
            foreach ($codesByProduct[$p->getId()] ?? [] as $code) {
                if (strcasecmp($code, $q) === 0) {
                    return true;
                }
            }

            return false;
        }));
        if (count($exactBarcodeMatches) === 1) {
            return $this->redirectToRoute('admin_bundle_inventory_depth_stock_by_product', ['id' => $exactBarcodeMatches[0]->getId()]);
        }

        return $this->render('@InventoryDepth/product_lookup.html.twig', ['q' => $q, 'results' => array_slice($results, 0, 25)]);
    }

    /**
     * The dropdown suggestions behind productLookup()'s search box, as the admin types.
     *
     * A pure JS enhancement over the GET form above, which stays the working, no-JS path — this
     * only ever supplies a faster way to reach the same page. Same match (ProductPicker::searchPage(),
     * #707), same target (byProduct()), capped small since it renders in a dropdown rather than a
     * page. Below 2 characters returns nothing rather than searching, matching the floor
     * OrderController::searchOrderProducts() and app.js's own MIN_SEARCH_CHARS already use —
     * ProductPicker::MIN_SEARCH_CHARS itself, now that this shares it rather than restating it.
     */
    #[Route('/stock/product/suggest', name: 'admin_bundle_inventory_depth_product_lookup_suggest', methods: ['GET'])]
    public function productLookupSuggest(Request $request): Response
    {
        $this->denyIfInactive();

        $q = trim((string) $request->query->get('q', ''));

        $results = array_slice($this->productPicker->searchPage($q)['products'], 0, 8);

        return $this->json(['results' => array_map(fn (ProductCore $p): array => [
            'sku' => $p->getSku(),
            'name' => $p->getName(),
            'status' => $p->getStatus(),
            'url' => $this->generateUrl('admin_bundle_inventory_depth_stock_by_product', ['id' => $p->getId()]),
        ], $results)]);
    }

    /**
     * One product's whole breakdown — every warehouse, bin, lot, serial and status — with the total
     * reconciling **visibly** to `product_inventory.quantity`.
     *
     * Showing both numbers side by side is the point, not decoration: the invariant is the only
     * thing holding the two layers together, and a screen that hid the core number could not tell
     * anyone it had broken.
     */
    #[Route('/stock/product/{id}', name: 'admin_bundle_inventory_depth_stock_by_product', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function byProduct(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $product = $this->productOr404($id);
        $filters = $this->filtersFromRequest($request, ['warehouse', 'status', 'lot', 'serial', 'bin']);
        $activity = trim((string) $request->query->get('activity', ''));
        $now = new \DateTimeImmutable();

        $qb = $this->em->getRepository(InventoryDetail::class)->createQueryBuilder('d')
            ->leftJoin('d.location', 'loc')->addSelect('loc')
            ->leftJoin('d.lot', 'lot')->addSelect('lot')
            ->innerJoin('d.warehouse', 'w')->addSelect('w')
            ->where('d.product = :product')->setParameter('product', $product);

        if ($filters['warehouse'] !== '' && ctype_digit($filters['warehouse'])) {
            $qb->andWhere('w.id = :fw')->setParameter('fw', (int) $filters['warehouse']);
        }
        if ($filters['status'] !== '') {
            $qb->andWhere('d.status = :fs')->setParameter('fs', $filters['status']);
        }
        if ($filters['lot'] !== '') {
            $qb->andWhere('lot.code LIKE :flot')->setParameter('flot', '%' . $filters['lot'] . '%');
        }
        if ($filters['serial'] !== '') {
            $qb->andWhere('d.serial LIKE :fserial')->setParameter('fserial', '%' . $filters['serial'] . '%');
        }
        if ($filters['bin'] !== '') {
            $qb->andWhere('loc.code LIKE :fbin')->setParameter('fbin', '%' . $filters['bin'] . '%');
        }

        /** @var list<InventoryDetail> $rows */
        $rows = $qb
            ->orderBy('w.name', 'ASC')
            ->addOrderBy('d.status', 'ASC')
            ->addOrderBy('loc.sortKey', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();

        // The reconciliation, per warehouse: what the detail says is available against what
        // product_inventory holds. Computed over ALL warehouses, not the filtered rows — a filter is
        // a way of looking at the data, never a way of changing what the totals mean.
        //
        // This must be the SAME equation InventoryDetailRecalcCommand (app:inventory-depth:detail-
        // check) checks, not `quantity` compared to available detail directly. That command's own
        // docblock explains why the direct comparison is wrong: `quantity` is the import's baseline
        // and this app never writes it again — everything that happens after import (receiving,
        // transfers, quarantine, write-offs, sales) is accumulated on separate columns instead. A
        // dimensional product that has only ever been received through this app carries `quantity`
        // 0 and `received_quantity` equal to the whole available total, so comparing `quantity`
        // alone flags every such product as drifted when nothing is wrong — which is exactly the
        // false "NO — run app:inventory-depth:detail-check" this page used to show for every
        // dimensional product in this database, none of which the command itself disagrees with.
        $details = $this->em->getRepository(InventoryDetail::class);
        $reconciliation = [];
        foreach ($this->em->getRepository(ProductInventory::class)->findBy(['product' => $product]) as $inventory) {
            $warehouse = $inventory->getWarehouse();
            if (!$warehouse instanceof Warehouse) {
                continue;
            }

            // Summed exactly, then rounded once at the end. Rounded to what this product's
            // unit allows -- 'each' to 1, 'kg' to 0.001 -- falling back to the store setting when
            // it has no unit. Rounding both sides to the same scale is also what lets them be
            // compared as strings: '0' and '0.0000' are the same figure and must not read as drift.
            $detailAvailable = QuantityScale::round($details->availableTotal($product, $warehouse), $product);
            $held = QuantityScale::round($details->heldAvailableTotal($product, $warehouse, $inventory), $product);

            $reconciliation[] = [
                'warehouse' => $warehouse,
                'held' => $held,
                'detailAvailable' => $detailAvailable,
                // Quarantine and write-off are independently accumulated (not derived from these
                // sums), so the combined equation above can mask one drifting against the other by
                // the same amount in opposite directions — the same reason the command checks them
                // separately too.
                'agrees' => $held === $detailAvailable
                    && QuantityScale::compare($inventory->getQuarantineQuantity(), $details->quarantineTotal($product, $warehouse)) === 0
                    && QuantityScale::compare($inventory->getWriteOffQuantity(), $details->writeOffTotal($product, $warehouse)) === 0,
                'available' => $inventory->getAvailableQuantity(),
            ];
        }

        return $this->render('@InventoryDepth/stock_by_product.html.twig', [
            'product' => $product,
            'rows' => $rows,
            'reconciliation' => $reconciliation,
            'warehouses' => $this->activeWarehouses(),
            'statuses' => InventoryDetail::statuses(),
            'filters' => $filters,
            'vendorNames' => $this->vendorNames($product),
            'availability' => $this->availabilityAndValue($product),
            'reorderRows' => $this->reorderStatusRows($product),
            'salesSnapshot' => $this->salesSnapshot($product, 30),
            'lotSerial' => $this->lotSerialBreakdown($product, $now),
            'activityTypes' => self::ACTIVITY_TYPES,
            'activity' => $activity,
            'activityRows' => $this->recentActivity($product, $activity, 20),
            'notes' => $this->productNotesForDisplay($product),
            'now' => $now,
        ]);
    }

    /**
     * The Product Inventory Hub's own notes log — dated, authored entries, shaped exactly like
     * {@see CompanyController::addNote()}. Supersedes the plain `remarks` textarea v1 shipped with
     * (docs/plans/2026-09-15-product-inventory-hub.md decision #2 named this the natural next step
     * once someone actually wanted dated attribution on product notes specifically). `remarks`
     * itself is untouched — still the Product Details grid's own sortable/filterable column and the
     * product form's own field; this is a second, independent place to note something.
     */
    #[Route('/stock/product/{id}/note', name: 'admin_bundle_inventory_depth_stock_by_product_note_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addNote(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $product = $this->productOr404($id);
        $text = TextInput::nullableStringMax($request->request->get('note'), AbstractPartyNote::MAX_LENGTH) ?? '';
        $payload = null;

        if ($text !== '') {
            $note = (new ProductNote())
                ->setProduct($product)
                ->setUserName($this->adminDisplayName())
                ->setText($text);
            $this->em->persist($note);
            $this->em->flush();
            $payload = [
                'ok' => true,
                'id' => $note->getId(),
                'date' => $this->productNoteDate($note),
                'author' => $note->getUserName(),
                'note' => $note->getText(),
                'message' => 'Note was added successfully.',
            ];
        } else {
            $this->addFlash('error', 'Note text is required.');
        }

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse($payload ?? ['ok' => false, 'message' => 'Note text is required.'], $payload ? 200 : 400);
        }

        if ($payload) {
            $this->addFlash('success', 'Note was added successfully.');
        }

        return $this->redirectToRoute('admin_bundle_inventory_depth_stock_by_product', ['id' => $id]);
    }

    #[Route('/stock/product/{id}/note/update', name: 'admin_bundle_inventory_depth_stock_by_product_note_update', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateNote(int $id, Request $request): Response
    {
        $this->denyIfInactive();

        $note = $this->productNoteFromRequest($id, $request);
        $text = TextInput::nullableStringMax($request->request->get('note'), AbstractPartyNote::MAX_LENGTH) ?? '';

        if (!$note instanceof ProductNote || $text === '') {
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['ok' => false, 'message' => 'Note text is required.'], 400);
            }

            $this->addFlash('error', 'Note text is required.');

            return $this->redirectToRoute('admin_bundle_inventory_depth_stock_by_product', ['id' => $id]);
        }

        // createdAt is deliberately untouched: an edit corrects the wording of a note, it does not
        // make it a new one.
        $note->setText($text);
        $this->em->flush();

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'ok' => true,
                'id' => $note->getId(),
                'date' => $this->productNoteDate($note),
                'author' => $note->getUserName(),
                'note' => $note->getText(),
                'message' => 'Note was updated successfully.',
            ]);
        }

        $this->addFlash('success', 'Note was updated successfully.');

        return $this->redirectToRoute('admin_bundle_inventory_depth_stock_by_product', ['id' => $id]);
    }

    #[Route('/stock/product/{id}/note/delete', name: 'admin_bundle_inventory_depth_stock_by_product_note_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteNote(int $id, Request $request): JsonResponse
    {
        $this->denyIfInactive();

        $note = $this->productNoteFromRequest($id, $request);
        if (!$note instanceof ProductNote) {
            return new JsonResponse(['ok' => false, 'message' => 'Note could not be found.'], Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($note);
        $this->em->flush();

        return new JsonResponse(['ok' => true, 'message' => 'Note was deleted successfully.']);
    }

    private function productNoteFromRequest(int $productId, Request $request): ?ProductNote
    {
        $noteId = (int) $request->request->get('id', 0);
        if ($noteId <= 0) {
            return null;
        }

        $note = $this->em->find(ProductNote::class, $noteId);

        return $note instanceof ProductNote && $note->getProduct()->getId() === $productId ? $note : null;
    }

    /** The one place a note's timestamp is turned into display text, so the list and the JSON agree. */
    private function productNoteDate(ProductNote $note): string
    {
        return $note->getCreatedAt()->format('M j, Y g:i A');
    }

    /** @return list<array{id:int,date:string,author:string,note:string}> */
    private function productNotesForDisplay(ProductCore $product): array
    {
        $notes = $this->em->getRepository(ProductNote::class)->findBy(
            ['product' => $product],
            ['createdAt' => 'DESC', 'id' => 'DESC'],
        );

        return array_map(fn (ProductNote $note): array => [
            'id' => (int) $note->getId(),
            'date' => $this->productNoteDate($note),
            'author' => (string) $note->getUserName(),
            'note' => $note->getText(),
        ], $notes);
    }

    private function adminDisplayName(): string
    {
        $user = $this->getUser();
        if (!$user instanceof AdminUser) {
            return 'System';
        }

        $name = trim($user->getFirstName() . ' ' . $user->getLastName());

        return $name !== '' ? $name : $user->getEmail();
    }

    /** What is in this bin right now, across every product. The counterpart view, and what a cycle count is run from. */
    #[Route('/stock/location', name: 'admin_bundle_inventory_depth_stock_by_location', methods: ['GET'])]
    public function byLocation(Request $request): Response
    {
        $this->denyIfInactive();

        $filters = $this->filtersFromRequest($request, ['warehouse', 'bin', 'status', 'product']);

        $qb = $this->em->getRepository(InventoryDetail::class)->createQueryBuilder('d')
            ->leftJoin('d.location', 'loc')->addSelect('loc')
            ->leftJoin('d.lot', 'lot')->addSelect('lot')
            ->innerJoin('d.warehouse', 'w')->addSelect('w')
            ->innerJoin('d.product', 'p')->addSelect('p')
            ->where('d.quantity > 0');

        if ($filters['warehouse'] !== '' && ctype_digit($filters['warehouse'])) {
            $qb->andWhere('w.id = :fw')->setParameter('fw', (int) $filters['warehouse']);
        }
        if ($filters['bin'] !== '') {
            $qb->andWhere('loc.code LIKE :fbin')->setParameter('fbin', '%' . $filters['bin'] . '%');
        }
        if ($filters['status'] !== '') {
            $qb->andWhere('d.status = :fs')->setParameter('fs', $filters['status']);
        }
        if ($filters['product'] !== '') {
            $qb->andWhere('p.sku LIKE :fp OR p.name LIKE :fp')->setParameter('fp', '%' . $filters['product'] . '%');
        }

        $paging = $this->paging($request, 'bin', 'asc');
        $total = (int) (clone $qb)->select('COUNT(d.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $pageCount = max(1, (int) ceil($total / $paging['limit']));
        $page = min($paging['page'], $pageCount);

        // Whitelisted via match — the raw parameter is never interpolated.
        $orderExpr = match ($paging['sort']) {
            'product' => 'p.sku',
            'quantity' => 'd.quantity',
            'status' => 'd.status',
            default => 'loc.sortKey',
        };

        /** @var list<InventoryDetail> $rows */
        $rows = $qb
            ->orderBy($orderExpr, $paging['dir'])
            ->addOrderBy('d.id', 'ASC')
            ->setFirstResult(($page - 1) * $paging['limit'])
            ->setMaxResults($paging['limit'])
            ->getQuery()
            ->getResult();

        return $this->render('@InventoryDepth/stock_by_location.html.twig', [
            'rows' => $rows,
            'warehouses' => $this->activeWarehouses(),
            'statuses' => InventoryDetail::statuses(),
            'filters' => $filters,
            'total' => $total,
            'page' => $page,
            'limit' => $paging['limit'],
            'pages' => $pageCount,
            'currentSort' => $paging['sort'],
            'currentDir' => strtolower($paging['dir']),
        ]);
    }

    /** Where is serial X now, and everything that ever happened to it. */
    #[Route('/stock/serial', name: 'admin_bundle_inventory_depth_serial_lookup', methods: ['GET'])]
    public function serialLookup(Request $request): Response
    {
        $this->denyIfInactive();

        $serial = trim((string) $request->query->get('serial', ''));

        $current = null;
        $history = [];

        if ($serial !== '') {
            // The one row with stock on it, if any — `uniq_live_serial` is what guarantees there is
            // at most one, and the query says so rather than assuming it.
            $current = $this->em->getRepository(InventoryDetail::class)->createQueryBuilder('d')
                ->where('d.serial = :serial')->setParameter('serial', $serial)
                ->andWhere('d.quantity > 0')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            $history = $this->em->getRepository(InventoryMovement::class)->historyForSerial($serial);
        }

        return $this->render('@InventoryDepth/serial_lookup.html.twig', [
            'serial' => $serial,
            'current' => $current,
            'history' => $history,
        ]);
    }

    /**
     * Identity card: which vendor(s) this product has actually been bought from — usually one or
     * two, so a plain comma list folded into the summary card is enough; no separate panel.
     * ProcurementBundle's own answer, via the tagged-provider seam — see
     * App\Contract\Inventory\ProductVendorProviderInterface's docblock for why this bundle never
     * imports PurchaseOrderLine directly.
     *
     * @return list<string>
     */
    private function vendorNames(ProductCore $product, int $limit = 3): array
    {
        return $this->vendors->vendorNames($product, $limit);
    }

    /**
     * Sidebar panel 2: on-hand, available and value, summed across every warehouse — the number
     * everything else on the page explains or breaks down further (plan's own framing).
     *
     * @return array{onHand: int, available: int, held: int, value: ?float}
     */
    private function availabilityAndValue(ProductCore $product): array
    {
        $onHand = 0;
        $available = 0;
        $held = 0;

        /** @var list<ProductInventory> $inventories */
        $inventories = $this->em->getRepository(ProductInventory::class)->findBy(['product' => $product]);
        foreach ($inventories as $inventory) {
            // "On hand" is the physical count — `quantity` (the client's own figure) plus
            // `receivedQuantity` (this app's own additive delta since #550/#564/#572), per the
            // invariant StockMovementService's own docblock states: SUM(available detail) ==
            // quantity + received. Never `getAvailableQuantity()` alone, which is that total minus
            // every sell-side hold — the number this panel's own "held/committed" line explains.
            $onHand += $inventory->getQuantity() + $inventory->getReceivedQuantity();
            $available += $inventory->getAvailableQuantity();
            $held += $inventory->getCartHoldQuantity() + $inventory->getSalesHoldQuantity()
                + $inventory->getPendingQuantity() + $inventory->getApprovedQuantity();
        }

        $costPrice = $product->getCostPrice();

        return [
            'onHand' => $onHand,
            'available' => $available,
            'held' => $held,
            'value' => $costPrice !== null ? (float) $costPrice * $onHand : null,
        ];
    }

    /**
     * Sidebar panel 3: one row per warehouse that actually has a reorder rule for this product — a
     * product with none anywhere skips the panel entirely rather than showing an empty table.
     *
     * @return list<array{warehouse: Warehouse, assessment: \InventoryDepthBundle\Reorder\ReorderAssessment}>
     */
    private function reorderStatusRows(ProductCore $product): array
    {
        /** @var ProductReorderRuleRepository $reorderRules */
        $reorderRules = $this->em->getRepository(ProductReorderRule::class);
        $inventoryRepo = $this->em->getRepository(ProductInventory::class);

        $rows = [];
        foreach ($this->activeWarehouses() as $warehouse) {
            $rule = $reorderRules->forPair($product, $warehouse);
            if (!$rule instanceof ProductReorderRule) {
                continue;
            }

            $inventory = $inventoryRepo->findOneBy(['product' => $product, 'warehouse' => $warehouse]);
            $rows[] = [
                'warehouse' => $warehouse,
                'assessment' => $this->reorderPointRule->assess($rule, $inventory),
            ];
        }

        return $rows;
    }

    /**
     * Sidebar panel 4: units sold in the last `$days` days, off `InvoiceLine` — an invoice, not an
     * order, is the point something is actually billed, so this is what "sold" means here.
     *
     * @return array{days: int, units: float}
     */
    private function salesSnapshot(ProductCore $product, int $days): array
    {
        $since = (new \DateTimeImmutable())->modify("-{$days} days")->format('Y-m-d');

        $units = (float) $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(il.quantity), 0)')
            ->from(InvoiceLine::class, 'il')
            ->innerJoin('il.invoice', 'i')
            ->andWhere('il.product = :product')->setParameter('product', $product)
            ->andWhere('i.documentDate >= :since')->setParameter('since', $since)
            // Without this the panel counted every invoice line in the window whatever state its
            // invoice was in, so an abandoned draft and a cancelled order both read as demand on a
            // figure labelled "Units sold" — and this page exists to be reordered from. The rule is
            // Invoice::countsTowardInvoicedQuantity()'s, asked of many rows rather than one, so
            // "sold" here means exactly what "invoiced" means everywhere else in the application.
            ->andWhere('i.status IN (:sold)')->setParameter('sold', Invoice::invoicedQuantityStatuses())
            ->getQuery()
            ->getSingleScalarResult();

        return ['days' => $days, 'units' => $units];
    }

    /**
     * Main column panel 2: lots or serials currently on hand — gated on the product's own tracking
     * policy, INBOUND capture (what identifies a unit already on the shelf), not
     * `ShipmentService::tracksOutbound()`'s outbound gate, which would wrongly hide a lot/serial an
     * inbound-only tracked product actually has in stock.
     *
     * @return null|array{type: 'lot', rows: list<array{lot: InventoryLot, quantity: int, expired: bool}>}|array{type: 'serial', rows: list<array{serial: string, warehouse: Warehouse}>}
     */
    private function lotSerialBreakdown(ProductCore $product, \DateTimeImmutable $now): ?array
    {
        $policy = $product->getTrackingPolicy();
        if (!$policy instanceof TrackingPolicy || $policy->isInert()) {
            return null;
        }

        // The same "still a placeholder" set TrackingWorklistController filters on — a lot or serial
        // equal to one of these is not a real identity somebody captured, it is the substitute a
        // writer stamped when it had none, with a promise (`expect_resolution`) to come back later.
        // Reused rather than re-derived, so this panel and the worklist can never disagree about
        // what counts as "still pending".
        $sentinels = $this->trackingPolicies->sentinelValues();

        if ($policy->tracksLotsInbound() || $policy->tracksLotsOutbound()) {
            /** @var InventoryLotRepository $lotRepo */
            $lotRepo = $this->em->getRepository(InventoryLot::class);
            /** @var list<InventoryLot> $lots */
            $lots = $lotRepo->withAvailableStock($product);

            $quantities = [];
            if ($lots !== []) {
                /** @var list<array{lotId: int|string, total: int|string}> $sums */
                $sums = $this->em->createQueryBuilder()
                    ->select('IDENTITY(d.lot) AS lotId', 'SUM(d.quantity) AS total')
                    ->from(InventoryDetail::class, 'd')
                    ->andWhere('d.lot IN (:lots)')->setParameter('lots', $lots)
                    ->andWhere('d.status = :status')->setParameter('status', InventoryDetail::STATUS_AVAILABLE)
                    ->groupBy('d.lot')
                    ->getQuery()
                    ->getArrayResult();
                foreach ($sums as $sum) {
                    $quantities[(int) $sum['lotId']] = (int) $sum['total'];
                }
            }

            return [
                'type' => 'lot',
                'pendingCount' => $this->pendingIdentificationCount($product),
                'rows' => array_map(static fn (InventoryLot $lot): array => [
                    'lot' => $lot,
                    'quantity' => $quantities[$lot->getId()] ?? 0,
                    'expired' => $lot->isExpired($now),
                    'pending' => in_array($lot->getCode(), $sentinels, true),
                ], $lots),
            ];
        }

        if ($policy->tracksSerialsInbound() || $policy->tracksSerialsOutbound()) {
            /** @var \InventoryDepthBundle\Repository\InventoryDetailRepository $detailRepo */
            $detailRepo = $this->em->getRepository(InventoryDetail::class);

            $rows = [];
            foreach ($this->activeWarehouses() as $warehouse) {
                foreach ($detailRepo->availableSerialsFor($product, $warehouse) as $serial) {
                    $rows[] = ['serial' => $serial, 'warehouse' => $warehouse, 'pending' => in_array($serial, $sentinels, true)];
                }
            }

            return ['type' => 'serial', 'pendingCount' => $this->pendingIdentificationCount($product), 'rows' => $rows];
        }

        return null;
    }

    /**
     * Units of this product still carrying a placeholder identity or flagged
     * `expect_resolution` — the union TrackingWorklistController itself queries, scoped to one
     * product. Counted separately from the lot/serial rows above because a NULL-identity
     * placeholder (a policy with a blank sentinel) has no code or serial to show a row for at
     * all — `availableSerialsFor()` excludes it outright — so this is the only way this panel can
     * say "some of this stock still needs identifying" instead of just silently under-counting.
     */
    private function pendingIdentificationCount(ProductCore $product): int
    {
        $sentinels = $this->trackingPolicies->sentinelValues();

        return (int) $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(d.quantity), 0)')
            ->from(InventoryDetail::class, 'd')
            ->leftJoin('d.lot', 'l')
            ->andWhere('d.product = :product')->setParameter('product', $product)
            ->andWhere('d.quantity <> 0')
            ->andWhere('d.expectResolution = true OR l.code IN (:sentinels) OR d.serial IN (:sentinels)')
            ->setParameter('sentinels', $sentinels)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Main column panel 3: the unified Recent Activity feed — each source's own last-20 rows, mapped
     * into one common shape, merged, sorted by date, then optionally narrowed by `$typeFilter` (the
     * SAME merged query, filtered after the fact — no second query path to keep in sync with the
     * merge logic, per the plan's own reasoning for the type filter).
     *
     * @return list<array{date: string, type: string, label: string, quantity: string, url: ?string}>
     */
    private function recentActivity(ProductCore $product, string $typeFilter, int $limit): array
    {
        $rows = [
            ...$this->orderActivity($product),
            // Purchases: ProcurementBundle's own answer, via the tagged-provider seam — see
            // App\Contract\Inventory\ProductActivityProviderInterface's docblock for why this bundle
            // never imports PurchaseOrderLine/GoodsReceiptLine directly.
            ...$this->activityFeed->activityFor($product, $limit),
            ...$this->shipmentActivity($product),
            ...$this->creditMemoActivity($product),
            ...$this->movementActivity($product),
        ];

        usort($rows, static fn (array $a, array $b): int => $b['date'] <=> $a['date']);

        if (in_array($typeFilter, self::ACTIVITY_TYPES, true)) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['type'] === $typeFilter));
        }

        return array_slice($rows, 0, $limit);
    }

    /** Orders AND invoices for this product — one merged "Order" tag, per the plan's own grouping. */
    private function orderActivity(ProductCore $product): array
    {
        /** @var list<SalesOrderLine> $orderLines */
        $orderLines = $this->em->createQueryBuilder()
            ->select('l', 'o')
            ->from(SalesOrderLine::class, 'l')
            ->innerJoin('l.order', 'o')->addSelect('o')
            ->andWhere('l.product = :product')->setParameter('product', $product)
            ->orderBy('o.documentDate', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        $rows = array_map(fn (SalesOrderLine $line): array => [
            'date' => $line->getOrder()->getDocumentDate(),
            'type' => 'order',
            'label' => 'Order ' . $line->getOrder()->getOrderNumber(),
            'quantity' => $line->getQuantity(),
            'url' => $this->generateUrl('admin_order_detail', ['id' => $line->getOrder()->getId()]),
        ], $orderLines);

        /** @var list<InvoiceLine> $invoiceLines */
        $invoiceLines = $this->em->createQueryBuilder()
            ->select('l', 'i')
            ->from(InvoiceLine::class, 'l')
            ->innerJoin('l.invoice', 'i')->addSelect('i')
            ->andWhere('l.product = :product')->setParameter('product', $product)
            ->orderBy('i.documentDate', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        foreach ($invoiceLines as $line) {
            /** @var InvoiceLine $line */
            $invoice = $line->getInvoice();
            $rows[] = [
                'date' => $invoice->getDocumentDate(),
                'type' => 'order',
                'label' => 'Invoice ' . $invoice->getDocumentNumber(),
                'quantity' => $line->getQuantity(),
                'url' => $this->generateUrl('admin_invoice_detail', ['id' => $invoice->getId()]),
            ];
        }

        return $rows;
    }

    /** Shipments for this product. */
    private function shipmentActivity(ProductCore $product): array
    {
        /** @var list<ShipmentLine> $lines */
        $lines = $this->em->createQueryBuilder()
            ->select('l', 's')
            ->from(ShipmentLine::class, 'l')
            ->innerJoin('l.shipment', 's')->addSelect('s')
            ->andWhere('l.product = :product')->setParameter('product', $product)
            ->orderBy('s.shippedAt', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        return array_map(fn (ShipmentLine $line): array => [
            'date' => $line->getShipment()->getShippedAt()->format('Y-m-d'),
            'type' => 'shipment',
            'label' => 'Shipment ' . $line->getShipment()->getShipmentNumber(),
            'quantity' => $line->getQuantity(),
            'url' => $this->generateUrl('admin_bundle_inventory_depth_shipment_show', ['id' => $line->getShipment()->getId()]),
        ], $lines);
    }

    /** Credit memos for this product. */
    private function creditMemoActivity(ProductCore $product): array
    {
        /** @var list<CreditMemoLine> $lines */
        $lines = $this->em->createQueryBuilder()
            ->select('l', 'm')
            ->from(CreditMemoLine::class, 'l')
            ->innerJoin('l.creditMemo', 'm')->addSelect('m')
            ->andWhere('l.product = :product')->setParameter('product', $product)
            ->orderBy('m.documentDate', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        return array_map(fn (CreditMemoLine $line): array => [
            'date' => $line->getCreditMemo()->getDocumentDate(),
            'type' => 'creditmemo',
            'label' => 'Credit Memo ' . $line->getCreditMemo()->getDocumentNumber(),
            'quantity' => $line->getQuantity(),
            'url' => $this->generateUrl('admin_credit_memo_detail', ['id' => $line->getCreditMemo()->getId()]),
        ], $lines);
    }

    /** Inventory movements/transfers for this product — no per-row detail page, so the row links to the filtered list. */
    private function movementActivity(ProductCore $product): array
    {
        /** @var list<InventoryMovement> $movements */
        $movements = $this->em->getRepository(InventoryMovement::class)
            ->filteredQueryBuilder(['productId' => (string) $product->getId()])
            ->orderBy('g.occurredAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        return array_map(fn (InventoryMovement $movement): array => [
            'date' => $movement->getGroup()->getOccurredAt()->format('Y-m-d'),
            'type' => 'movement',
            'label' => $movement->getGroup()->getType() . ' (' . $movement->getQuantity() . ')',
            'quantity' => (string) $movement->getQuantity(),
            'url' => $this->generateUrl('admin_bundle_inventory_depth_movements', ['filters' => ['productId' => (string) $product->getId()]]),
        ], $movements);
    }
}
