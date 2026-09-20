<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Controller\Admin;

use App\Service\QuantityScale;
use App\EventSubscriber\BundleBucketAvailabilityGate;
use App\Repository\BundleStatusRepository;
use App\Service\Inventory\InventoryModeResolver;
use App\Service\Product\ProductPicker;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\ProductReorderRule;
use InventoryDepthBundle\Reorder\ReorderAssessment;
use InventoryDepthBundle\Reorder\ReorderPointRule;
use InventoryDepthBundle\Repository\ProductReorderRuleRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Reorder levels, and the screen that shows what has fallen to one (#597).
 *
 * One screen, not two. Setting a level and reading the consequence are the same job done a minute
 * apart, and splitting them would mean an admin who spots a wrong level has to go somewhere else to
 * correct it — which is the shape #590's audit kept finding and filing.
 *
 * ## Why this bundle and not procurement
 *
 * A reorder level is a fact about a shelf, not about a document. It is set per (product, warehouse),
 * it is read from inventory figures, and it stays meaningful with no vendor, no purchase order and
 * no procurement bundle installed at all — the screen simply reports that nothing is on its way,
 * which with procurement gone is true. Putting it in ProcurementBundle would make an inventory
 * setting disappear when the purchasing module was switched off.
 *
 * The link OUT to raising a purchase order goes the other way and is guarded: rendered only when
 * ProcurementBundle is Active AND its route is actually registered, because a deleted bundle takes
 * its routes with it and `path()` on a missing route is a 500 on a page that has nothing to do with
 * procurement.
 *
 * ## Why the rule is evaluated in PHP rather than in DQL
 *
 * Availability is eleven terms, three of which are switched in and out of the sum by bundle status
 * (`App\EventSubscriber\BundleBucketAvailabilityGate`). Restating that in DQL to sort by shortfall
 * in the database would be a SECOND definition of availability living in a bundle, and it would be
 * wrong the first time core changed a term — silently, because a wrong reorder flag looks exactly
 * like a right one.
 *
 * So the candidate set comes from `inventory_reorder_rule`, which is bounded by the rows somebody
 * has deliberately set a level on rather than by the catalogue, and the arithmetic runs on entities
 * the ORM has loaded and the gate has stamped. Filtering by warehouse and by product happens in
 * SQL first; the projection, the sort and the page slice happen here.
 *
 * The page is still cut server-side and rendered through `admin/_pagination.html.twig`, so the
 * screen keeps the list convention: `no-paginate` on the card, no JavaScript pager.
 */
#[Route('/admin/bundles/inventory-depth/low-stock')]
final class ReorderController extends AbstractInventoryDepthController
{
    /** The three views of the list. Anything else reads as the default. */
    private const SHOW_SHORT = 'short';
    private const SHOW_COVERED = 'covered';
    private const SHOW_BELOW = 'below';
    private const SHOW_ALL = 'all';

    public function __construct(
        EntityManagerInterface $em,
        BundleStatusRepository $bundleStatusRepo,
        InventoryModeResolver $inventoryModes,
        private readonly ReorderPointRule $reorderRule,
        private readonly RouterInterface $router,
        private readonly ProductPicker $products,
    ) {
        parent::__construct($em, $bundleStatusRepo, $inventoryModes);
    }

    #[Route('', name: 'admin_bundle_inventory_depth_low_stock', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyIfInactive();

        $filters = $this->filtersFromRequest($request, ['product', 'warehouse', 'show']);
        $show = \in_array($filters['show'], [self::SHOW_SHORT, self::SHOW_COVERED, self::SHOW_BELOW, self::SHOW_ALL], true)
            ? $filters['show']
            : self::SHOW_SHORT;
        $filters['show'] = $show;

        $rules = $this->rules()->managed(
            $filters['warehouse'] !== '' && ctype_digit($filters['warehouse']) ? (int) $filters['warehouse'] : null,
            $filters['product'],
        );

        $inventory = $this->rules()->inventoryFor($this->em, $rules);

        /** @var list<ReorderAssessment> $assessed */
        $assessed = [];
        foreach ($rules as $rule) {
            $key = $rule->getProduct()->getId() . ':' . $rule->getWarehouse()->getId();
            $assessed[] = $this->reorderRule->assess($rule, $inventory[$key] ?? null);
        }

        // Counted over every managed row that survived the warehouse/product filters, BEFORE the
        // view filter — so the tab you are not looking at can still say how many rows are on it.
        // A filter is a way of looking at the data, never a way of changing what the totals mean.
        $counts = [
            self::SHOW_SHORT => 0,
            self::SHOW_COVERED => 0,
            self::SHOW_BELOW => 0,
            self::SHOW_ALL => \count($assessed),
        ];
        foreach ($assessed as $one) {
            if ($one->short()) {
                ++$counts[self::SHOW_SHORT];
            }
            if ($one->covered()) {
                ++$counts[self::SHOW_COVERED];
            }
            if ($one->belowPoint()) {
                ++$counts[self::SHOW_BELOW];
            }
        }

        $rows = array_values(array_filter($assessed, static fn (ReorderAssessment $one): bool => match ($show) {
            self::SHOW_COVERED => $one->covered(),
            self::SHOW_BELOW => $one->belowPoint(),
            self::SHOW_ALL => true,
            default => $one->short(),
        }));

        $paging = $this->paging($request, 'shortfall', 'desc');
        $this->sort($rows, $paging['sort'], $paging['dir']);

        $total = \count($rows);
        $pageCount = max(1, (int) ceil($total / $paging['limit']));
        $page = min($paging['page'], $pageCount);
        $rows = \array_slice($rows, ($page - 1) * $paging['limit'], $paging['limit']);

        $editId = $request->query->getInt('edit', 0);
        $editing = $editId > 0 ? $this->em->find(ProductReorderRule::class, $editId) : null;

        return $this->render('@InventoryDepth/low_stock.html.twig', [
            'rows' => $rows,
            'counts' => $counts,
            'editing' => $editing instanceof ProductReorderRule ? $editing : null,
            'warehouses' => $this->activeWarehouses(),
            // The create form's product field (#8) — the same three tiers every other screen in the
            // app uses, so nobody has to know a database id to set a level.
            'productOptions' => $this->products->options(),
            'productsRemote' => $this->products->isRemote(),
            'filters' => $filters,
            'total' => $total,
            'page' => $page,
            'limit' => $paging['limit'],
            'pages' => $pageCount,
            'currentSort' => $paging['sort'],
            'currentDir' => strtolower($paging['dir']),
            // Whether `product_inventory.incoming_quantity` is still being maintained at all. The
            // screen says so out loud rather than showing a column of zeroes that looks like "no
            // purchase orders" when it means "nobody is counting".
            'incomingMaintained' => $this->bundleStatusRepo->isActive(BundleBucketAvailabilityGate::WRITING_BUNDLE),
            'purchaseOrderRoute' => $this->purchaseOrderRoute(),
        ]);
    }

    /**
     * Sets or changes one level. A plain form POST — this bundle ships no JavaScript.
     *
     * The pair is write-once: an existing row is edited, never duplicated, and a create for a pair
     * that already has a level is sent to that row rather than refused into a UNIQUE violation.
     * Which is the same defect #590 found in the lots form with the arrow reversed — there, an edit
     * silently forked; here, a duplicate would be a 500.
     */
    #[Route('/save', name: 'admin_bundle_inventory_depth_reorder_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->denyIfInactive();

        $id = $request->request->getInt('id', 0);
        $rule = $id > 0 ? $this->em->find(ProductReorderRule::class, $id) : null;

        // A named id that resolves to nothing is a stale Edit link. Falling through to create would
        // set a level on whatever the form's product fields happened to hold.
        if ($id > 0 && !$rule instanceof ProductReorderRule) {
            $this->addFlash('error', 'That reorder level no longer exists. Nothing was saved.');

            return $this->redirectToRoute('admin_bundle_inventory_depth_low_stock');
        }

        $point = trim((string) $request->request->get('reorder_point', ''));
        if ($point === '' || !ctype_digit($point)) {
            // Refused rather than defaulted. A blank level is not 0: 0 means "tell me when there is
            // nothing left", and inventing it for somebody who left the box empty is exactly the
            // "400 products on the screen on day one" #597 rules out.
            $this->addFlash('error', 'A reorder level has to be a whole number of units, zero or more. Nothing was saved.');

            return $this->redirectToRoute('admin_bundle_inventory_depth_low_stock', $id > 0 ? ['edit' => $id] : []);
        }

        // Checked before anything is built or persisted, same as the reorder level above — and
        // refused, not silently dropped (#777). optionalQuantity() used to coerce a negative or
        // garbage value to null exactly like a blank box, which hid a typo behind a field that then
        // read as "nothing entered" instead of "entered wrong" — a reorder quantity or safety stock
        // of -7 has no more physical meaning than a reorder level of -7 already correctly refuses.
        $reorderQuantity = $this->optionalQuantity($request, 'reorder_quantity');
        if ($reorderQuantity === false) {
            $this->addFlash('error', 'A reorder quantity has to be a number, zero or more — or left blank. Nothing was saved.');

            return $this->redirectToRoute('admin_bundle_inventory_depth_low_stock', $id > 0 ? ['edit' => $id] : []);
        }

        $safetyStockQuantity = $this->optionalQuantity($request, 'safety_stock_quantity');
        if ($safetyStockQuantity === false) {
            $this->addFlash('error', 'A safety stock quantity has to be a number, zero or more — or left blank. Nothing was saved.');

            return $this->redirectToRoute('admin_bundle_inventory_depth_low_stock', $id > 0 ? ['edit' => $id] : []);
        }

        if (!$rule instanceof ProductReorderRule) {
            $productId = $this->productIdFrom($request->request->all());
            if ($productId <= 0) {
                // Said out loud rather than 404'd, for the reason the blank level above is: these
                // screens have to work with scripting off, so the server states every rule the
                // markup claims instead of relying on the browser to have enforced it.
                $this->addFlash('error', 'A reorder level is set for a product — choose one. Nothing was saved.');

                return $this->redirectToRoute('admin_bundle_inventory_depth_low_stock');
            }

            $product = $this->productOr404($productId);
            $warehouse = $this->warehouseOr404($request->request->getInt('warehouse_id', 0));

            $existing = $this->rules()->forPair($product, $warehouse);
            if ($existing instanceof ProductReorderRule) {
                $this->addFlash('error', sprintf(
                    '%s already has a reorder level at %s (inventory_reorder_rule row %d). Edit that row rather than adding a second one — one pair, one level.',
                    $product->getSku() ?: $product->getName(),
                    $warehouse->getName(),
                    $existing->getId(),
                ));

                return $this->redirectToRoute('admin_bundle_inventory_depth_low_stock', ['edit' => $existing->getId()]);
            }

            $rule = (new ProductReorderRule())->setProduct($product)->setWarehouse($warehouse);
            $this->em->persist($rule);
        }

        $rule
            ->setReorderPoint((int) $point)
            ->setReorderQuantity($reorderQuantity)
            ->setSafetyStockQuantity($safetyStockQuantity);

        $this->em->flush();

        $this->addFlash('success', sprintf(
            'Reorder level for %s at %s is %d (inventory_reorder_rule row %d %s).',
            $rule->getProduct()->getSku() ?: $rule->getProduct()->getName(),
            $rule->getWarehouse()->getName(),
            $rule->getReorderPoint(),
            $rule->getId(),
            $id > 0 ? 'updated' : 'created',
        ));

        return $this->redirectToRoute('admin_bundle_inventory_depth_low_stock');
    }

    /**
     * Stops managing a pair. The row goes; nothing about the stock is touched.
     *
     * POST and a real form, never a link — a destructive action on a GET is reachable by a crawler
     * and by a prefetch, and the row's button has to work with JavaScript off.
     */
    #[Route('/{id}/delete', name: 'admin_bundle_inventory_depth_reorder_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id): Response
    {
        $this->denyIfInactive();

        $rule = $this->em->find(ProductReorderRule::class, $id);

        if (!$rule instanceof ProductReorderRule) {
            $this->addFlash('error', 'That reorder level no longer exists. Nothing was deleted.');

            return $this->redirectToRoute('admin_bundle_inventory_depth_low_stock');
        }

        $label = $rule->getLabel();

        $this->em->remove($rule);
        $this->em->flush();

        $this->addFlash('success', sprintf(
            '%s — stopped managing (inventory_reorder_rule row %d deleted). No stock figure changed; the pair is simply on no reorder screen now.',
            $label,
            $id,
        ));

        return $this->redirectToRoute('admin_bundle_inventory_depth_low_stock');
    }

    /**
     * An empty box is NULL, not 0.
     *
     * Both optional columns distinguish the two: a NULL reorder quantity means "no standing answer,
     * suggest the shortfall", and a NULL safety stock means "no second band". A 0 in either would be
     * a decision nobody made.
     */
    /**
     * A posted reorder_quantity/safety_stock_quantity. Blank is a real answer — both fields are
     * optional — but anything else has to be a genuine quantity, zero or more; `false` tells the
     * caller to refuse the whole save rather than silently treat a typo as "not set" (#777).
     *
     * Decimal-aware (`is_numeric()`/`QuantityScale`, not `ctype_digit()`): both columns are
     * `type: 'quantity'`, the same decimal column type the rest of this bundle's reorder math
     * already speaks natively since the 2026-09-17 decimal migration — an integer-only check here
     * was stale from before that, silently dropping a legitimate "7.5" the same way it dropped "-7".
     */
    private function optionalQuantity(Request $request, string $field): string|false|null
    {
        $raw = trim((string) $request->request->get($field, ''));
        if ($raw === '') {
            return null;
        }

        if (!is_numeric($raw) || QuantityScale::compare($raw, '0') < 0) {
            return false;
        }

        return QuantityScale::canonical($raw);
    }

    /**
     * Sorts the assessed rows.
     *
     * Whitelisted through match, like every other list screen here — the difference being that the
     * raw parameter never reaches SQL at all, because these comparisons are on computed figures.
     *
     * @param list<ReorderAssessment> $rows
     */
    private function sort(array &$rows, string $sort, string $dir): void
    {
        $sign = strtoupper($dir) === 'ASC' ? 1 : -1;

        usort($rows, static function (ReorderAssessment $a, ReorderAssessment $b) use ($sort, $sign): int {
            $compared = match ($sort) {
                'product' => strcasecmp(
                    $a->rule->getProduct()->getSku() ?: $a->rule->getProduct()->getName(),
                    $b->rule->getProduct()->getSku() ?: $b->rule->getProduct()->getName(),
                ),
                'warehouse' => strcasecmp($a->rule->getWarehouse()->getName(), $b->rule->getWarehouse()->getName()),
                'available' => QuantityScale::compare($a->available, $b->available),
                'incoming' => QuantityScale::compare($a->incoming, $b->incoming),
                'transferIncoming' => QuantityScale::compare($a->transferIncoming, $b->transferIncoming),
                'projected' => QuantityScale::compare($a->projected(), $b->projected()),
                'point' => QuantityScale::compare($a->point(), $b->point()),
                default => QuantityScale::compare($a->shortfall(), $b->shortfall()),
            };

            // A stable, meaningful tiebreak: rows equal on the sorted figure read in a fixed order
            // rather than whatever the query happened to return, so paging through them cannot show
            // the same row twice or skip one.
            return $compared !== 0
                ? $sign * $compared
                : ($a->rule->getId() ?? 0) <=> ($b->rule->getId() ?? 0);
        });
    }

    /**
     * The route to raise a purchase order, or null when there is none to link to.
     *
     * Two questions, both necessary. ProcurementBundle Inactive means its screens 404, so a link
     * would be an invitation to a dead end; ProcurementBundle DELETED means the route is not in the
     * collection at all, and `path()` on it would throw — turning a missing optional bundle into a
     * 500 on an inventory screen.
     */
    private function purchaseOrderRoute(): ?string
    {
        if (!$this->bundleStatusRepo->isActive(BundleBucketAvailabilityGate::WRITING_BUNDLE)) {
            return null;
        }

        $route = 'admin_bundle_procurement_purchase_order_new';

        return $this->router->getRouteCollection()->get($route) !== null ? $route : null;
    }

    private function rules(): ProductReorderRuleRepository
    {
        /** @var ProductReorderRuleRepository $repo */
        $repo = $this->em->getRepository(ProductReorderRule::class);

        return $repo;
    }
}
