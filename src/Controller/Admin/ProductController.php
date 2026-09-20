<?php

namespace App\Controller\Admin;

use App\Contract\Fee\FeeFieldProviderInterface;
use App\Entity\AdminColumnPreference;
use App\Entity\AdminUser;
use App\Entity\FulfillmentRegion;
use App\Entity\Warehouse;
use App\Entity\PriceList;
use App\Entity\ProductCategory;
use App\Entity\ProductCore;
use App\Entity\ProductImage;
use App\Entity\ProductInventory;
use App\Entity\ProductPricing;
use App\Entity\TrackingPolicy;
use App\Entity\UnitOfMeasure;
use App\Entity\Company;
use App\Enum\ProductStatus;
use App\Repository\AdminColumnPreferenceRepository;
use App\Repository\BundleStatusRepository;
use App\Repository\ProductImageRepository;
use App\Repository\TrackingPolicyRepository;
use App\Repository\UnitOfMeasureRepository;
use App\Service\AppSettings;
use App\Service\CustomFieldRenderer;
use App\Service\Inventory\CoreInventoryTotalCountService;
use App\Service\Inventory\InventoryGridColumns;
use App\Service\Inventory\InventoryModeResolver;
use App\Service\Pricing\PriceNumber;
use App\Service\ProductPrivacyLinker;
use App\Service\Uom\ProductAvailableUnitService;
use App\Service\Uom\ProductBaseUnitService;
use App\Service\Uom\UnitOfMeasureRefusal;
use App\Service\SuggestedPriceCalculator;
use App\Service\WarehouseFulfillmentRegionService;
use App\Validation\Constraint\ValidProductRequest;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validation;

#[Route('/admin')]
final class ProductController extends AbstractAdminController
{
    // Keeps IN(:productIds) bind counts under SQLite's ~999 variable limit.
    private const PRICE_QUERY_CHUNK_SIZE = 500;

    // Suggested Price: native ProductCore columns (SUGGESTED_PRICE_CORE_ADAPTATION_PLAN.md phase 2) —
    // unconditional core behavior now, no bundle toggle involved.
    private const SUGGESTED_PRICE_TYPES = ['Number', 'Markup$', 'Markup%'];

    public function __construct(
        #[AutowireIterator('app.fee_field_provider')]
        private readonly iterable $feeFieldProviders,
        private readonly CustomFieldRenderer $customFieldRenderer,
        private readonly ProductImageRepository $productImageRepository,
        private readonly BundleStatusRepository $bundleStatusRepo,
        private readonly SuggestedPriceCalculator $suggestedPriceCalculator,
        private readonly WarehouseFulfillmentRegionService $warehouses,
        private readonly InventoryModeResolver $inventoryMode,
        private readonly InventoryGridColumns $gridColumns,
        private readonly CoreInventoryTotalCountService $totalCounts,
        private readonly TrackingPolicyRepository $trackingPolicies,
        private readonly UnitOfMeasureRepository $unitsOfMeasure,
        private readonly ProductBaseUnitService $baseUnits,
        private readonly ProductAvailableUnitService $availableUnits,
        private readonly ProductPrivacyLinker $productPrivacyLinker,
        #[Autowire(service: 'doctrine.debug_data_holder')]
        private readonly ?DebugDataHolder $debugData = null,
    ) {}

    private function normalizeMoneyFilter(string $raw): ?string
    {
        $normalized = preg_replace('/[^0-9.\\-]/', '', $raw) ?? '';
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        return number_format((float) $normalized, 2, '.', '');
    }

    /** The view key under which Product Detail column choices are stored (issue #120). */
    private const PRODUCT_DETAIL_VIEW = 'product_detail';

    /** What the Source column shows for a product whose provenance was never recorded. */
    private const SYNC_SOURCE_UNKNOWN_LABEL = '-';

    /**
     * The toggleable columns of the Product Detail grid, in display order: key => [label, group].
     * "actions" is always shown and deliberately not listed here. Keys match the data-sort-field /
     * filter keys already used by the table.
     */
    private const PRODUCT_DETAIL_COLUMNS = [
        'id' => ['ID', 'core'],
        'image' => ['Image', 'core'],
        'name' => ['Product name', 'core'],
        'sku' => ['SKU', 'core'],
        'category' => ['Category', 'core'],
        'type' => ['Type', 'core'],
        'unit' => ['U/M', 'core'],
        'weight' => ['Weight', 'core'],
        'visible' => ['Visible', 'core'],
        'status' => ['Status', 'core'],
        'sales_tax_code' => ['Tax Class', 'core'],
        'shipping_class' => ['Shipping Class', 'core'],
        'sync_source' => ['Source', 'core'],
        'remarks' => ['Remarks', 'catalog'],
        'short' => ['Short description', 'catalog'],
        'long' => ['Long description', 'catalog'],
        'privacy' => ['Private', 'catalog'],
        'custom_fields' => ['Custom Fields', 'catalog'],
        'cost' => ['Cost price', 'pricing'],
        'original' => ['Original price', 'pricing'],
        'price' => ['Sale price', 'pricing'],
        'deposit' => ['Deposit', 'pricing'],
    ];

    #[Route('/product/detail/index', name: 'admin_product_detail_index', methods: ['GET'])]
    public function details(EntityManagerInterface $entityManager, Request $request, AdminColumnPreferenceRepository $columnPrefRepo): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = $request->query->getInt('limit', 100);
        /** @var array<string, mixed> $rawFilters */
        $rawFilters = $request->query->all('filters');
        if (!is_array($rawFilters) || $rawFilters === []) {
            $rawFilters = $request->request->all('filters');
        }
        $filters = [
            'id' => trim((string) ($rawFilters['id'] ?? '')),
            'name' => trim((string) ($rawFilters['name'] ?? '')),
            'sku' => trim((string) ($rawFilters['sku'] ?? '')),
            'type' => trim((string) ($rawFilters['type'] ?? '')),
            'unit' => trim((string) ($rawFilters['unit'] ?? '')),
            'weight' => trim((string) ($rawFilters['weight'] ?? '')),
            'visible' => trim((string) ($rawFilters['visible'] ?? '')),
            'status' => trim((string) ($rawFilters['status'] ?? '')),
            'category' => trim((string) ($rawFilters['category'] ?? '')),
            'cost' => trim((string) ($rawFilters['cost'] ?? '')),
            'original' => trim((string) ($rawFilters['original'] ?? '')),
            'price' => trim((string) ($rawFilters['price'] ?? '')),
            'deposit' => trim((string) ($rawFilters['deposit'] ?? '')),
            'shipping_class' => trim((string) ($rawFilters['shipping_class'] ?? '')),
            'sales_tax_code' => trim((string) ($rawFilters['sales_tax_code'] ?? '')),
            'sync_source' => trim((string) ($rawFilters['sync_source'] ?? '')),
            'remarks' => trim((string) ($rawFilters['remarks'] ?? '')),
            'short' => trim((string) ($rawFilters['short'] ?? '')),
            'long' => trim((string) ($rawFilters['long'] ?? '')),
            'privacy' => trim((string) ($rawFilters['privacy'] ?? '')),
        ];

        $repo = $entityManager->getRepository(ProductCore::class);
        $qb = $repo->createQueryBuilder('p')
            ->where('p.deleted = false');

        $sort = trim((string) $request->query->get('sort', 'id'));
        $dir = strtolower(trim((string) $request->query->get('dir', 'asc'))) === 'desc' ? 'DESC' : 'ASC';
        $orderExpr = 'p.id';
        $needsCategoryJoin = false;

        $idFilter = $filters['id'];
        if ($idFilter !== '' && ctype_digit($idFilter)) {
            $qb->andWhere('p.id = :fid')->setParameter('fid', (int) $idFilter);
        }

        $nameFilter = $filters['name'];
        if ($nameFilter !== '') {
            $qb->andWhere('p.name LIKE :fname')->setParameter('fname', '%' . $nameFilter . '%');
        }

        $skuFilter = $filters['sku'];
        if ($skuFilter !== '') {
            $qb->andWhere('p.sku LIKE :fsku')->setParameter('fsku', '%' . $skuFilter . '%');
        }

        $typeFilter = $filters['type'];
        if ($typeFilter !== '') {
            $qb->andWhere('p.type LIKE :ftype')->setParameter('ftype', '%' . $typeFilter . '%');
        }

        $unitFilter = $filters['unit'];
        if ($unitFilter !== '') {
            $qb->andWhere('p.unit LIKE :funit')->setParameter('funit', '%' . $unitFilter . '%');
        }

        $weightFilter = $filters['weight'];
        if ($weightFilter !== '') {
            $qb->andWhere('p.weight LIKE :fweight')->setParameter('fweight', '%' . $weightFilter . '%');
        }

        $visibleFilter = strtolower($filters['visible']);
        if ($visibleFilter !== '') {
            if (in_array($visibleFilter, ['1', 'true', 'yes', 'y'], true)) {
                $qb->andWhere('p.visible = true');
            } elseif (in_array($visibleFilter, ['0', 'false', 'no', 'n'], true)) {
                $qb->andWhere('p.visible = false');
            }
        }

        $statusFilter = $filters['status'];
        if ($statusFilter !== '') {
            // A status the enum names is matched exactly; anything else still falls through to the
            // substring match, which is how an admin finds the rows carrying a value from before
            // ProductStatus existed. The list came from a hand-kept ['Active', 'Inactive'] until
            // #9 added Draft, which is precisely the kind of list that goes stale in silence.
            if (in_array($statusFilter, ProductStatus::values(), true)) {
                $qb->andWhere('p.status = :fstatus')->setParameter('fstatus', $statusFilter);
            } else {
                $qb->andWhere('p.status LIKE :fstatus')->setParameter('fstatus', '%' . $statusFilter . '%');
            }
        }

        $categoryFilter = $filters['category'];
        if ($categoryFilter !== '') {
            $needsCategoryJoin = true;
            $qb->andWhere('cat.id = :fcatId')->setParameter('fcatId', (int) $categoryFilter);
        }

        foreach (['cost' => 'costPrice', 'original' => 'originalPrice', 'deposit' => 'deposit'] as $filterKey => $field) {
            $value = $filters[$filterKey];
            if ($value === '') {
                continue;
            }

            $money = $this->normalizeMoneyFilter($value);
            if ($money !== null) {
                $qb->andWhere(sprintf('p.%s = :f%s', $field, $filterKey))
                    ->setParameter('f'.$filterKey, $money);
            }
        }

        $priceFilter = $filters['price'];
        if ($priceFilter !== '') {
            $money = $this->normalizeMoneyFilter($priceFilter);
            if ($money !== null) {
                $qb->andWhere('p.defaultPrice = :fprice')->setParameter('fprice', $money);
            } else {
                $qb->andWhere('p.defaultPrice LIKE :fpriceLike')->setParameter('fpriceLike', '%' . $priceFilter . '%');
            }
        }

        $remarksFilter = $filters['remarks'];
        if ($remarksFilter !== '') {
            $qb->andWhere('p.remarks LIKE :fremarks')->setParameter('fremarks', '%' . $remarksFilter . '%');
        }

        $shortFilter = $filters['short'];
        if ($shortFilter !== '') {
            $qb->andWhere('p.shortDescription LIKE :fshort')->setParameter('fshort', '%' . $shortFilter . '%');
        }

        $longFilter = $filters['long'];
        if ($longFilter !== '') {
            $qb->andWhere('p.longDescription LIKE :flong')->setParameter('flong', '%' . $longFilter . '%');
        }

        $privacyFilter = strtolower($filters['privacy']);
        if ($privacyFilter !== '') {
            if (str_contains($privacyFilter, 'priv')) {
                $qb->andWhere('SIZE(p.privateCompanies) > 0');
            } elseif (str_contains($privacyFilter, 'pub')) {
                $qb->andWhere('SIZE(p.privateCompanies) = 0');
            }
        }

        $shippingClassFilter = $filters['shipping_class'];
        if ($shippingClassFilter !== '') {
            $qb->andWhere('p.shippingClass = :fshipping_class')->setParameter('fshipping_class', $shippingClassFilter);
        }

        $salesTaxCodeFilter = $filters['sales_tax_code'];
        if ($salesTaxCodeFilter !== '') {
            $qb->andWhere('p.salesTaxCode LIKE :fsales_tax_code')->setParameter('fsales_tax_code', '%' . $salesTaxCodeFilter . '%');
        }

        $syncSourceFilter = $filters['sync_source'];
        if ($syncSourceFilter !== '') {
            // "-" is what the grid prints for a product with no recorded provenance, so typing it
            // into the filter has to find exactly those rows rather than LIKE-matching a literal
            // dash that no stored value ever contains.
            if ($syncSourceFilter === self::SYNC_SOURCE_UNKNOWN_LABEL) {
                $qb->andWhere('p.syncSource IS NULL');
            } else {
                $qb->andWhere('p.syncSource LIKE :fsync_source')->setParameter('fsync_source', '%' . $syncSourceFilter . '%');
            }
        }

        switch ($sort) {
            case 'id': $orderExpr = 'p.id'; break;
            case 'name': $orderExpr = 'p.name'; break;
            case 'sku': $orderExpr = 'p.sku'; break;
            case 'type': $orderExpr = 'p.type'; break;
            case 'unit': $orderExpr = 'p.unit'; break;
            case 'weight': $orderExpr = 'p.weight'; break;
            case 'visible': $orderExpr = 'p.visible'; break;
            case 'status': $orderExpr = 'p.status'; break;
            case 'category': $needsCategoryJoin = true; $orderExpr = 'cat.name'; break;
            case 'shipping_class': $orderExpr = 'p.shippingClass'; break;
            case 'sales_tax_code': $orderExpr = 'p.salesTaxCode'; break;
            case 'sync_source': $orderExpr = 'p.syncSource'; break;
            case 'cost': $orderExpr = 'p.costPrice'; break;
            case 'original': $orderExpr = 'p.originalPrice'; break;
            case 'price': $orderExpr = 'p.defaultPrice'; break;
            case 'deposit': $orderExpr = 'p.deposit'; break;
            case 'remarks': $orderExpr = 'p.remarks'; break;
            case 'short': $orderExpr = 'p.shortDescription'; break;
            case 'long': $orderExpr = 'p.longDescription'; break;
            case 'privacy': $orderExpr = 'SIZE(p.privateCompanies)'; break;
            default: $orderExpr = 'p.id'; break;
        }

        if ($needsCategoryJoin) {
            $qb->leftJoin('p.category', 'cat');
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

        $rows = array_map(
            fn (ProductCore $product): array => $this->productToRow($product, $entityManager),
            $products
        );

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'html' => $this->renderView('admin/product/_detail_rows.html.twig', [
                    'products' => $rows,
                ]),
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => (int) ceil($total / $limit),
            ]);
        }

        $allColumnKeys = array_keys(self::PRODUCT_DETAIL_COLUMNS);
        $admin = $this->getUser();
        $saved = $admin instanceof AdminUser
            ? $columnPrefRepo->resolveVisibleColumns($admin, self::PRODUCT_DETAIL_VIEW)
            : null;
        // No stored preference => show everything. Never let a stored (or empty) selection hide the
        // whole table.
        $visibleColumns = $saved === null ? $allColumnKeys : array_values(array_intersect($allColumnKeys, $saved));
        if ($visibleColumns === []) {
            $visibleColumns = $allColumnKeys;
        }

        return $this->render('admin/product/detail.html.twig', [
            'products' => $rows,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'filters' => $filters,
            'currentSort' => $sort,
            'currentDir' => strtolower($dir),
            'categories' => $this->categoryRowsFromDatabase($entityManager),
            'columnRegistry' => self::PRODUCT_DETAIL_COLUMNS,
            'visibleColumns' => $visibleColumns,
            'columnGroupCounts' => $this->columnGroupCounts($visibleColumns),
            'canOverrideColumnsForAll' => $this->isGranted('ROLE_TECH_SUPPORT'),
        ]);
    }

    /**
     * How many of the currently visible columns fall in each of the grouped-header row's sections
     * (core / pricing / catalog), so its <th colspan> can shrink to match — otherwise hiding a column
     * leaves that row claiming more column tracks than actually exist below it, and every group header
     * to its right drifts out of alignment with the real columns underneath.
     *
     * @param list<string> $visibleColumns
     * @return array<string, int>
     */
    private function columnGroupCounts(array $visibleColumns): array
    {
        $counts = ['core' => 0, 'pricing' => 0, 'catalog' => 0];
        foreach ($visibleColumns as $key) {
            $group = self::PRODUCT_DETAIL_COLUMNS[$key][1] ?? null;
            if ($group !== null && isset($counts[$group])) {
                $counts[$group]++;
            }
        }

        return $counts;
    }

    #[Route('/product/detail/columns', name: 'admin_product_detail_columns_save', methods: ['POST'])]
    public function saveDetailColumns(Request $request, EntityManagerInterface $entityManager, AdminColumnPreferenceRepository $columnPrefRepo): Response
    {
        /** @var AdminUser $admin */
        $admin = $this->getUser();
        $submitted = $request->request->all('columns');
        $valid = array_values(array_intersect(array_keys(self::PRODUCT_DETAIL_COLUMNS), is_array($submitted) ? $submitted : []));

        $applyToAll = $request->request->getBoolean('apply_all');
        if ($applyToAll) {
            // Only Tech Support may set the site-wide default; it does not overwrite anyone ELSE's
            // personal selection (issue #120). But the acting admin themselves is one of "all users" —
            // if they still have their own stale personal row, resolveVisibleColumns() would keep
            // preferring it over the default they just set, making this look like it silently reverted
            // to the old columns when they check their own view right after. Clear it so they see the
            // default they just applied, same as everyone else without a personal override.
            $this->denyAccessUnlessGranted('ROLE_TECH_SUPPORT');
            $pref = $columnPrefRepo->findGlobal(self::PRODUCT_DETAIL_VIEW)
                ?? (new AdminColumnPreference())->setViewKey(self::PRODUCT_DETAIL_VIEW);
            $ownPersonal = $columnPrefRepo->findForAdmin($admin, self::PRODUCT_DETAIL_VIEW);
            if ($ownPersonal !== null) {
                $entityManager->remove($ownPersonal);
            }
        } else {
            $pref = $columnPrefRepo->findForAdmin($admin, self::PRODUCT_DETAIL_VIEW)
                ?? (new AdminColumnPreference())->setAdmin($admin)->setViewKey(self::PRODUCT_DETAIL_VIEW);
        }

        $pref->setColumns($valid)->touch();
        $entityManager->persist($pref);
        $entityManager->flush();

        $this->addFlash('success', $applyToAll ? 'Default columns updated for all users.' : 'Your column choices were saved.');

        return $this->redirectToRoute('admin_product_detail_index');
    }

    #[Route('/product/price/index', name: 'admin_product_price_index', methods: ['GET'])]
    public function prices(EntityManagerInterface $entityManager, Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $limit = $request->query->getInt('limit', 100);
        /** @var array<string, mixed> $rawFilters */
        $rawFilters = $request->query->all('filters');
        if (!is_array($rawFilters) || $rawFilters === []) {
            $rawFilters = $request->request->all('filters');
        }
        $filters = [
            'id' => trim((string) ($rawFilters['id'] ?? '')),
            'sku' => trim((string) ($rawFilters['sku'] ?? '')),
            'name' => trim((string) ($rawFilters['name'] ?? '')),
            'category' => trim((string) ($rawFilters['category'] ?? '')),
            'price' => trim((string) ($rawFilters['price'] ?? '')),
            'default_price' => trim((string) ($rawFilters['default_price'] ?? '')),
        ];

        $repo = $entityManager->getRepository(ProductCore::class);
        $qb = $repo->createQueryBuilder('p')
            ->where('p.deleted = false');

        $needsCategoryJoin = false;

        $idFilter = $filters['id'];
        if ($idFilter !== '' && ctype_digit($idFilter)) {
            $qb->andWhere('p.id = :fid')->setParameter('fid', (int) $idFilter);
        }

        $skuFilter = $filters['sku'];
        if ($skuFilter !== '') {
            $qb->andWhere('p.sku LIKE :fsku')->setParameter('fsku', '%' . $skuFilter . '%');
        }

        $nameFilter = $filters['name'];
        if ($nameFilter !== '') {
            $qb->andWhere('p.name LIKE :fname')->setParameter('fname', '%' . $nameFilter . '%');
        }

        $categoryFilter = $filters['category'];
        if ($categoryFilter !== '') {
            $needsCategoryJoin = true;
            $qb->andWhere('cat.id = :fcatId')->setParameter('fcatId', (int) $categoryFilter);
        }

        $priceFilterRaw = $filters['default_price'];
        if ($priceFilterRaw !== '') {
            $money = $this->normalizeMoneyFilter($priceFilterRaw);
            if ($money !== null) {
                $qb->andWhere('p.defaultPrice = :fprice')->setParameter('fprice', $money);
            }
        }

        // Product price (original price from Product Details).
        $productPriceFilterRaw = $filters['price'];
        if ($productPriceFilterRaw !== '') {
            $money = $this->normalizeMoneyFilter($productPriceFilterRaw);
            if ($money !== null) {
                $qb->andWhere('p.originalPrice = :pp')->setParameter('pp', $money);
            }
        }

        $sort = trim((string) $request->query->get('sort', $request->request->get('sort', 'id')));
        $dirRaw = (string) $request->query->get('dir', $request->request->get('dir', 'asc'));
        $dir = strtolower(trim($dirRaw)) === 'desc' ? 'DESC' : 'ASC';

        $dynamicPricingSort = $this->pricingMatrixDynamicSortMeta($sort);

        if ($sort === 'category') {
            $needsCategoryJoin = true;
        }

        $orderExpr = match ($sort) {
            'id' => 'p.id',
            'name' => 'p.name',
            'sku' => 'p.sku',
            'category' => 'cat.name',
            'default_price' => 'p.defaultPrice',
            'price' => 'p.originalPrice',
            'suggested_type' => 'p.suggestedPriceType',
            'suggested_value' => 'p.suggestedPriceValue',
            default => 'p.id',
        };

        if ($needsCategoryJoin) {
            $qb->leftJoin('p.category', 'cat');
        }

        $totalQuery = clone $qb;
        $total = (int) $totalQuery->select('COUNT(p.id)')->getQuery()->getSingleScalarResult();

        $productsQb = $qb->select('p');
        if ($dynamicPricingSort === null) {
            $productsQb
                ->orderBy($orderExpr, $dir)
                ->addOrderBy('p.id', 'ASC')
                ->setFirstResult(($page - 1) * $limit)
                ->setMaxResults($limit);
        } else {
            $productsQb
                ->orderBy('p.id', 'ASC');
        }

        $products = $productsQb->getQuery()->getResult();

        // "Product price" column: use the first ProductPricing row (by id) for each product on this page.
        // Fetch in chunks to stay under the SQL bound-parameter limit (SQLite caps this at ~999).
        $firstPricingByProductId = [];
        $productIds = array_map(static fn (ProductCore $p): ?int => $p->getId(), $products);
        if ($productIds !== []) {
            $pricingRows = [];
            foreach (array_chunk($productIds, self::PRICE_QUERY_CHUNK_SIZE) as $idChunk) {
                $pricingRows = array_merge($pricingRows, $entityManager->getRepository(ProductPricing::class)->createQueryBuilder('pp')
                    ->leftJoin('pp.product', 'p')
                    ->where('p.id IN (:productIds)')
                    ->setParameter('productIds', $idChunk)
                    ->orderBy('p.id', 'ASC')
                    ->addOrderBy('pp.id', 'ASC')
                    ->getQuery()
                    ->getResult());
            }

            foreach ($pricingRows as $pp) {
                if (!$pp instanceof ProductPricing) {
                    continue;
                }
                $pid = $pp->getProduct()?->getId();
                if ($pid === null) {
                    continue;
                }
                if (!array_key_exists($pid, $firstPricingByProductId)) {
                    $firstPricingByProductId[$pid] = $pp->getPrice() ?? '';
                }
            }
        }

        $allActivePriceLists = $entityManager->getRepository(PriceList::class)->findBy(['status' => 'Active'], ['id' => 'ASC']);
        $allActiveIds = array_values(array_filter(array_map(
            static fn ($pl): ?int => $pl instanceof PriceList ? $pl->getId() : null,
            $allActivePriceLists
        )));

        // Selected ids represent which price-list columns should be shown.
        // Default: show none until the user selects price groups.
        $selectedIds = $this->selectedPriceListIdsFromRequest($request, $allActiveIds);

        $priceLists = $selectedIds !== []
            ? array_values(array_filter(
                $allActivePriceLists,
                static fn ($pl): bool => $pl instanceof PriceList && $pl->getId() !== null && in_array((int) $pl->getId(), $selectedIds, true)
            ))
            : [];

        $priceListIds = array_values(array_filter(array_map(
            static fn ($pl): ?int => $pl instanceof PriceList ? $pl->getId() : null,
            $priceLists
        )));

        /** @var array<int, array<int, ProductPricing>> $pricingByProductAndList */
        $pricingByProductAndList = [];
        if ($productIds !== [] && $priceListIds !== []) {
            $pricingRows = [];
            foreach (array_chunk($productIds, self::PRICE_QUERY_CHUNK_SIZE) as $idChunk) {
                $pricingRows = array_merge($pricingRows, $entityManager->getRepository(ProductPricing::class)->createQueryBuilder('pp')
                    ->leftJoin('pp.product', 'p')
                    ->leftJoin('pp.priceList', 'pl')
                    ->where('p.id IN (:productIds)')
                    ->andWhere('pl.id IN (:lists)')
                    ->setParameter('productIds', $idChunk)
                    ->setParameter('lists', $priceListIds)
                    ->getQuery()
                    ->getResult());
            }

            foreach ($pricingRows as $row) {
                if (!$row instanceof ProductPricing) {
                    continue;
                }
                $pid = $row->getProduct()?->getId();
                $lid = $row->getPriceList()?->getId();
                if ($pid === null || $lid === null) {
                    continue;
                }
                $pricingByProductAndList[$pid][$lid] = $row;
            }
        }

        $rows = array_map(
            fn (ProductCore $product): array => $this->productToPriceRow($product, $entityManager, $priceLists, $pricingByProductAndList, $firstPricingByProductId),
            $products
        );

        if ($dynamicPricingSort !== null) {
            $rows = $this->sortPricingMatrixRows($rows, $dynamicPricingSort['priceListId'], $dynamicPricingSort['field'], strtolower($dir));
            $pageCount = max(1, (int) ceil($total / max($limit, 1)));
            $page = max(1, min($page, $pageCount));
            $rows = array_slice($rows, ($page - 1) * $limit, $limit);
        }

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'html' => $this->renderView('admin/product/_price_rows.html.twig', [
                    'products' => $rows,
                    'priceLists' => $priceLists,
                ]),
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => (int) ceil($total / $limit),
            ]);
        }

        return $this->render('admin/product/prices.html.twig', [
            'products' => $rows,
            'priceLists' => $priceLists,
            'allPriceLists' => $allActivePriceLists,
            'selectedPriceListIds' => $selectedIds,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'filters' => $filters,
            'currentSort' => $sort,
            'currentDir' => strtolower($dir),
            'categories' => $this->categoryRowsFromDatabase($entityManager),
        ]);
    }

    /** @return array{field: string, priceListId: int|null}|null */
    private function pricingMatrixDynamicSortMeta(string $sort): ?array
    {
        // Suggested Price's Effective is computed (base price + rule), like a price list's
        // Effective, not a raw ProductCore column — it needs the same PHP-side sort pass, keyed
        // by null instead of a price list id since there's no PriceList entity behind it.
        if ($sort === 'suggested_effective') {
            return [
                'field' => 'effective',
                'priceListId' => null,
            ];
        }

        if (!preg_match('/^pl_(type|value|effective)_(\d+)$/', $sort, $matches)) {
            return null;
        }

        $field = (string) ($matches[1] ?? '');
        $priceListId = (int) ($matches[2] ?? 0);
        if ($priceListId <= 0) {
            return null;
        }

        return [
            'field' => $field,
            'priceListId' => $priceListId,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function sortPricingMatrixRows(array $rows, ?int $priceListId, string $field, string $dir): array
    {
        usort($rows, static function (array $left, array $right) use ($priceListId, $field, $dir): int {
            if ($priceListId === null) {
                $leftValue = trim((string) ($left['suggestedEffective'] ?? ''));
                $rightValue = trim((string) ($right['suggestedEffective'] ?? ''));
            } else {
                $leftMeta = $left['pricingMetaByListId'][(string) $priceListId] ?? $left['pricingMetaByListId'][$priceListId] ?? [];
                $rightMeta = $right['pricingMetaByListId'][(string) $priceListId] ?? $right['pricingMetaByListId'][$priceListId] ?? [];

                $leftValue = trim((string) ($leftMeta[$field] ?? ''));
                $rightValue = trim((string) ($rightMeta[$field] ?? ''));
            }
            $leftValue = $leftValue === '-' ? '' : preg_replace('/\s+/u', ' ', $leftValue);
            $rightValue = $rightValue === '-' ? '' : preg_replace('/\s+/u', ' ', $rightValue);
            $leftValue = $leftValue ?? '';
            $rightValue = $rightValue ?? '';

            $leftBlank = $leftValue === '';
            $rightBlank = $rightValue === '';
            if ($leftBlank !== $rightBlank) {
                return $leftBlank ? 1 : -1;
            }

            if ($field === 'value' || $field === 'effective') {
                $leftNumber = is_numeric($leftValue) ? (float) $leftValue : null;
                $rightNumber = is_numeric($rightValue) ? (float) $rightValue : null;
                $comparison = ($leftNumber ?? 0.0) <=> ($rightNumber ?? 0.0);
            } else {
                $comparison = strcasecmp($leftValue, $rightValue);
            }

            if ($comparison === 0) {
                $comparison = ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
            }

            return $dir === 'desc' ? -$comparison : $comparison;
        });

        return $rows;
    }

    /** @param list<int> $activePriceListIds @return list<int> */
    private function selectedPriceListIdsFromRequest(Request $request, array $activePriceListIds): array
    {
        $raw = trim((string) $request->query->get('lists', ''));
        if ($raw === '' || $raw === '0') {
            return [];
        }

        $parts = array_filter(array_map('trim', explode(',', $raw)), static fn (string $v): bool => $v !== '');
        $ids = [];
        foreach ($parts as $p) {
            if (!ctype_digit($p)) {
                continue;
            }
            $id = (int) $p;
            if ($id > 0 && in_array($id, $activePriceListIds, true)) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    #[Route('/product/price/update', name: 'admin_product_price_update', methods: ['POST'])]
    public function updatePrice(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $productId = $request->request->getInt('product_id', 0);
        $priceListId = $request->request->getInt('price_list_id', 0);
        $raw = trim((string) $request->request->get('price', ''));
        $ruleType = trim((string) $request->request->get('rule_type', ''));
        $ruleValueRaw = trim((string) $request->request->get('rule_value', ''));

        if ($productId <= 0 || $priceListId <= 0) {
            return new JsonResponse(['ok' => false, 'message' => 'Invalid request.'], Response::HTTP_BAD_REQUEST);
        }

        $product = $entityManager->find(ProductCore::class, $productId);
        $priceList = $entityManager->find(PriceList::class, $priceListId);
        if (!$product instanceof ProductCore || !$priceList instanceof PriceList) {
            return new JsonResponse(['ok' => false, 'message' => 'Not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($ruleType === 'No Price' || $ruleType === 'Hide') {
            $pricing = $entityManager->getRepository(ProductPricing::class)->findOneBy([
                'product' => $product,
                'priceList' => $priceList,
            ]) ?? (new ProductPricing())->setProduct($product)->setPriceList($priceList);

            $pricing->setPrice('0.00')->setCurrency($priceList->getCurrency())->setRuleType($ruleType)->setRuleValue(null);
            $entityManager->persist($pricing);
            $entityManager->flush();

            return new JsonResponse(['ok' => true, 'price' => '0.00', 'ruleType' => $ruleType, 'message' => 'Saved.']);
        }

        if ($raw === '') {
            $existing = $entityManager->getRepository(ProductPricing::class)->findOneBy([
                'product' => $product,
                'priceList' => $priceList,
            ]);
            if ($existing instanceof ProductPricing) {
                $entityManager->remove($existing);
                $entityManager->flush();
            }
            return new JsonResponse(['ok' => true, 'price' => '', 'message' => 'Cleared.']);
        }

        $normalized = str_replace(',', '', $raw);
        // Negative is no longer rejected (#458). The grid posts the effective price it has just
        // computed in the browser, and with the zero floor gone from both sides that figure is
        // legitimately negative whenever a discount exceeds the base — keeping the old guard here
        // would 422 the very saves this change exists to allow, before any of the code below ran.
        //
        // Exponent notation IS rejected, though (#464): `1e3` passes is_numeric() and would be
        // stored as 1000.00, which no admin typing three characters into a price field intended.
        if (!PriceNumber::isPrice($normalized)) {
            return new JsonResponse(['ok' => false, 'message' => 'Price must be a valid number.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // The rule is a calculator, and the calculator owns the answer (#458). Whatever the client
        // put in the `price` field is only a starting point for the one case the rule cannot speak
        // to at all — a product with no base price and no usable rule — because a stale or
        // hand-crafted figure in that field is not evidence of anything. Everything the rule *can*
        // answer, it answers: an applicable rule gives its own result, and a rule that resolves to
        // nothing (blank type, absent/non-numeric value, or a discount with no base to discount)
        // falls back to the base price rather than to the number that happened to be posted.
        //
        // The two steps ask only whether the field holds a number, never whether it holds a
        // positive one: a base of 0 or below is a base like any other, and the rules below compute
        // from it. Same chain, and the same absence of a sign test, as
        // SuggestedPriceCalculator::getBasePrice() and the grid's own JavaScript (#464).
        $basePrice = trim((string) ($product->getDefaultPrice() ?? ''));
        if (!PriceNumber::isPrice($basePrice)) {
            $basePrice = trim((string) ($product->getOriginalPrice() ?? ''));
        }
        $baseNumber = PriceNumber::isPrice($basePrice) ? (float) $basePrice : null;

        $effective = null;
        if (in_array($ruleType, ['Discount%', 'Discount$', 'Number'], true) && $ruleValueRaw !== '') {
            $normalizedRuleValue = str_replace(',', '', $ruleValueRaw);
            // A negative Discount%/Discount$ is a deliberate premium (customer pays MORE than base),
            // not an error, and a discount larger than the base is a deliberate negative price — only
            // non-numeric input is rejected here — and exponent notation counts as non-numeric
            // (#464). Nothing below floors the result: the sign the admin asked for is the sign that
            // gets stored.
            if (PriceNumber::isPrice($normalizedRuleValue)) {
                $ruleValueNumber = (float) $normalizedRuleValue;
                if ($ruleType === 'Number') {
                    $effective = $ruleValueNumber;
                } elseif ($baseNumber !== null) {
                    if ($ruleType === 'Discount%') {
                        $effective = $baseNumber * (1 - ($ruleValueNumber / 100));
                    } elseif ($ruleType === 'Discount$') {
                        $effective = $baseNumber - $ruleValueNumber;
                    }
                }
            }
        }

        if ($effective !== null && is_finite($effective)) {
            $price = number_format($effective, 2, '.', '');
        } elseif ($baseNumber !== null) {
            $price = number_format($baseNumber, 2, '.', '');
        } else {
            $price = number_format((float) $normalized, 2, '.', '');
        }

        $pricing = $entityManager->getRepository(ProductPricing::class)->findOneBy([
            'product' => $product,
            'priceList' => $priceList,
        ]) ?? (new ProductPricing())->setProduct($product)->setPriceList($priceList);

        $pricing->setPrice($price)->setCurrency($priceList->getCurrency());

        if ($ruleType !== '' && in_array($ruleType, ['Discount%', 'Discount$', 'Number'], true)) {
            $pricing->setRuleType($ruleType);
        }

        if ($ruleValueRaw === '') {
            $pricing->setRuleValue(null);
        } else {
            $normalizedRuleValue = str_replace(',', '', $ruleValueRaw);
            // Negative rule values are valid (a premium rather than a discount) — see the comment above.
            if (PriceNumber::isPrice($normalizedRuleValue)) {
                $pricing->setRuleValue(number_format((float) $normalizedRuleValue, 2, '.', ''));
            }
        }

        $entityManager->persist($pricing);
        $entityManager->flush();

        return new JsonResponse(['ok' => true, 'price' => $price, 'message' => 'Saved.']);
    }

    #[Route('/product/price/bulk-apply', name: 'admin_product_price_bulk_apply', methods: ['POST'])]
    public function bulkApplyPriceRule(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        if (!$request->isXmlHttpRequest()) {
            return new JsonResponse(['ok' => false, 'message' => 'Invalid request.'], Response::HTTP_BAD_REQUEST);
        }

        $priceListId = $request->request->getInt('price_list_id', 0);
        $field = strtolower(trim((string) $request->request->get('field', 'both')));
        $type = trim((string) $request->request->get('type', ''));
        $valueRaw = trim((string) $request->request->get('value', ''));

        if ($priceListId <= 0) {
            return new JsonResponse(['ok' => false, 'message' => 'Invalid price list.'], Response::HTTP_BAD_REQUEST);
        }
        if (!in_array($field, ['type', 'value', 'both'], true)) {
            return new JsonResponse(['ok' => false, 'message' => 'Invalid field.'], Response::HTTP_BAD_REQUEST);
        }

        if ($field === 'type' || $field === 'both') {
            if (!in_array($type, ['Discount%', 'Discount$', 'Number', 'No Price', 'Hide'], true)) {
                return new JsonResponse(['ok' => false, 'message' => 'Invalid type.'], Response::HTTP_BAD_REQUEST);
            }
        }

        $isNonNumericRule = ($field === 'type' || $field === 'both') && ($type === 'No Price' || $type === 'Hide');
        $clearing = (!$isNonNumericRule && ($field === 'value' || $field === 'both')) ? ($valueRaw === '') : false;
        $normalizedValue = $clearing ? '' : str_replace(',', '', $valueRaw);
        // Negative is valid for Discount%/Discount$ (a premium rather than a discount) — only reject
        // non-numeric input, and exponent notation counts as non-numeric: `1e3` is not a rule value
        // (#464). Everything below this point now resolves a price exactly as updatePrice() does —
        // same base chain, same absence of any sign test, same fallback to the base price when the
        // rule resolves to nothing — so the two endpoints store the same figure for the same product.
        if (!$isNonNumericRule && $normalizedValue !== '' && !PriceNumber::isPrice($normalizedValue)) {
            return new JsonResponse(['ok' => false, 'message' => 'Value must be a valid number.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $valueNum = $normalizedValue === '' ? 0.0 : (float) $normalizedValue;

        $priceList = $entityManager->find(PriceList::class, $priceListId);
        if (!$priceList instanceof PriceList) {
            return new JsonResponse(['ok' => false, 'message' => 'Price list not found.'], Response::HTTP_NOT_FOUND);
        }

        $batchSize = 250;
        $updated = 0;
        $processed = 0;
        // Rows whose rule columns were written but whose price could not be resolved at all: no
        // usable base price AND no computable rule. See the write below — the stored price is left
        // as it was, and this count is what says so out loud instead of it being a silent no-op.
        $unresolved = 0;

        // $entityManager->clear() below has been here since before #768 was filed and does real
        // work (measured: removing it raises this loop's memory footprint on a large catalog by
        // several tens of MB) — the issue's claim that it was missing does not match this file.
        // Two belt-and-suspenders additions alongside it, in the same spirit, neither one alone
        // sufficient: resetting DebugDataHolder, which keeps every query/params for the life of the
        // process whenever the kernel is in debug mode, independent of the identity map (see
        // App\Command\Demo\DemoVolumeCheckpoint, which hit this first); and the id keyset cursor a
        // little further down, replacing OFFSET pagination. The bulk of what's left after both is
        // Doctrine's own fixed per-entity overhead (hydration, change-tracking, proxies) times
        // catalog size, compounded by PHP's memory manager not returning freed heap to the OS
        // mid-request — not something a batch-loop change removes. A truly huge "apply to all
        // pages, no filters" run staying under a small memory_limit needs the operation split
        // across multiple worker processes (e.g. queued via Messenger), which is a bigger change
        // than this one.
        //
        // "Apply to all pages" must only touch products matching the grid's active filters — the
        // caller (prices.html.twig) forwards the current URL's filters[...] params onto this POST for
        // exactly that reason. Re-select the matching set server-side rather than trusting any
        // client-supplied id list, the same way prices() re-runs the query rather than trusting the
        // page's rendered rows.
        /** @var array<string, mixed> $rawFilters */
        $rawFilters = $request->request->all('filters');
        if (!is_array($rawFilters) || $rawFilters === []) {
            $rawFilters = $request->query->all('filters');
        }
        $filters = [
            'id' => trim((string) ($rawFilters['id'] ?? '')),
            'sku' => trim((string) ($rawFilters['sku'] ?? '')),
            'name' => trim((string) ($rawFilters['name'] ?? '')),
            'category' => trim((string) ($rawFilters['category'] ?? '')),
            'price' => trim((string) ($rawFilters['price'] ?? '')),
            'default_price' => trim((string) ($rawFilters['default_price'] ?? '')),
        ];

        $repo = $entityManager->getRepository(ProductCore::class);
        $qb = $repo->createQueryBuilder('p')
            ->where('p.deleted = false');

        $idFilter = $filters['id'];
        if ($idFilter !== '' && ctype_digit($idFilter)) {
            $qb->andWhere('p.id = :fid')->setParameter('fid', (int) $idFilter);
        }

        $skuFilter = $filters['sku'];
        if ($skuFilter !== '') {
            $qb->andWhere('p.sku LIKE :fsku')->setParameter('fsku', '%' . $skuFilter . '%');
        }

        $nameFilter = $filters['name'];
        if ($nameFilter !== '') {
            $qb->andWhere('p.name LIKE :fname')->setParameter('fname', '%' . $nameFilter . '%');
        }

        $categoryFilter = $filters['category'];
        if ($categoryFilter !== '') {
            $qb->leftJoin('p.category', 'cat');
            $qb->andWhere('cat.id = :fcatId')->setParameter('fcatId', (int) $categoryFilter);
        }

        $defaultPriceFilterRaw = $filters['default_price'];
        if ($defaultPriceFilterRaw !== '') {
            $money = $this->normalizeMoneyFilter($defaultPriceFilterRaw);
            if ($money !== null) {
                $qb->andWhere('p.defaultPrice = :fprice')->setParameter('fprice', $money);
            }
        }

        // Product price (original price from Product Details).
        $productPriceFilterRaw = $filters['price'];
        if ($productPriceFilterRaw !== '') {
            $money = $this->normalizeMoneyFilter($productPriceFilterRaw);
            if ($money !== null) {
                $qb->andWhere('p.originalPrice = :pp')->setParameter('pp', $money);
            }
        }

        // Base price: default price if present, otherwise originalPrice (import/product edit).

        // Accepted and echoed back in the debug payload below for parity with the grid's own
        // request, but not used to order the batch fetch: this endpoint applies the same rule to
        // every matching row regardless of which order it visits them in, and an OFFSET tied to a
        // non-id column would defeat the id keyset cursor just below it (a duplicate-value or
        // shifting-order column can skip or repeat rows across pages that a plain id cursor can't).
        $sort = trim((string) $request->request->get('sort', $request->query->get('sort', 'id')));
        $dirRaw = (string) $request->request->get('dir', $request->query->get('dir', 'asc'));
        $dir = strtolower(trim($dirRaw)) === 'desc' ? 'DESC' : 'ASC';

        $baseQb = clone $qb;
        $baseQb->select('p.id AS id, p.defaultPrice AS defaultPrice, p.originalPrice AS originalPrice')
            ->orderBy('p.id', 'ASC');

        // A keyset cursor (p.id > :cursor), not OFFSET/LIMIT: Doctrine's ORM query cache key bakes
        // firstResult/maxResults into its hash (Query::getHash()), so a growing OFFSET across pages
        // means each page compiles and permanently caches its OWN parsed query — the in-memory
        // ArrayAdapter used whenever no query_cache_driver is configured (i.e. outside prod) never
        // evicts, so that grew without bound across a large catalog's page count regardless of
        // $entityManager->clear() (#768) since it lives in the query cache, not the identity map. A
        // fixed LIMIT with a moving WHERE-clause parameter compiles to the exact same cached query
        // every page.
        $cursor = 0;

        while (true) {
            $batchQb = clone $baseQb;
            $rows = $batchQb
                ->andWhere('p.id > :cursor')
                ->setParameter('cursor', $cursor)
                ->setMaxResults($batchSize)
                ->getQuery()
                ->getArrayResult();

            if ($rows === []) {
                break;
            }

            $cursor = max(array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $rows));

            $productIds = array_values(array_filter(array_map(
                static fn (array $r): ?int => isset($r['id']) ? (int) $r['id'] : null,
                $rows
            ), static fn (?int $v): bool => $v !== null && $v > 0));

            if ($productIds === []) {
                break;
            }
            $processed += count($productIds);

            $existing = $entityManager->getRepository(ProductPricing::class)->createQueryBuilder('pp')
                ->leftJoin('pp.product', 'p')
                ->where('pp.priceList = :pl')
                ->andWhere('p.id IN (:ids)')
                ->setParameter('pl', $entityManager->getReference(PriceList::class, $priceListId))
                ->setParameter('ids', $productIds)
                ->getQuery()
                ->getResult();

            $existingByProductId = [];
            foreach ($existing as $pp) {
                if (!$pp instanceof ProductPricing) {
                    continue;
                }
                $pid = $pp->getProduct()?->getId();
                if ($pid !== null) {
                    $existingByProductId[(int) $pid] = $pp;
                }
            }

            if ($isNonNumericRule) {
                foreach ($rows as $r) {
                    $pid = (int) ($r['id'] ?? 0);
                    if ($pid <= 0) {
                        continue;
                    }

                    $pricing = $existingByProductId[$pid] ?? null;
                    if (!$pricing instanceof ProductPricing) {
                        $pricing = (new ProductPricing())
                            ->setProduct($entityManager->getReference(ProductCore::class, $pid))
                            ->setPriceList($entityManager->getReference(PriceList::class, $priceListId));
                        $entityManager->persist($pricing);
                    }

                    $pricing
                        ->setCurrency($priceList->getCurrency())
                        ->setRuleType($type)
                        ->setRuleValue(null)
                        ->setPrice('0.00');
                    $updated++;
                }

                $entityManager->flush();
                $entityManager->clear();
                $this->debugData?->reset();
                continue;
            }

            if ($clearing) {
                foreach ($existingByProductId as $pp) {
                    if ($pp instanceof ProductPricing) {
                        $entityManager->remove($pp);
                        $updated++;
                    }
                }

                $entityManager->flush();
                $entityManager->clear();
                $this->debugData?->reset();
                continue;
            }

            foreach ($rows as $r) {
                $pid = (int) ($r['id'] ?? 0);
                if ($pid <= 0) {
                    continue;
                }

                $base = $r['defaultPrice'] ?? null;
                $baseStr = is_string($base) ? trim($base) : (is_numeric($base) ? (string) $base : '');
                $baseNum = PriceNumber::isPrice($baseStr) ? (float) $baseStr : null;
                // The base is default_price when that field holds a number, otherwise original_price
                // when that one does, otherwise there is no base at all. Neither step asks whether
                // the number is POSITIVE — a base of 0 or below is a base like any other, and the
                // rules further down compute from it. Same chain, and the same absence of a sign
                // test, as updatePrice(), SuggestedPriceCalculator::getBasePrice() and the grid's
                // own JavaScript (#464 items 1 and 2).
                //
                // This used to fall back on `<= 0`, so a product priced at 0 silently borrowed its
                // original_price and a bulk apply stored a different figure than saving the very
                // same row by hand did. Note the substitute is accepted on the same terms: an
                // original_price of 0 or -5 is a base, which is the half of the fix that keeps a
                // zero or negative default_price from simply being replaced one step later.
                if ($baseNum === null) {
                    $original = $r['originalPrice'] ?? null;
                    $originalStr = is_string($original) ? trim($original) : (is_numeric($original) ? (string) $original : '');
                    $baseNum = PriceNumber::isPrice($originalStr) ? (float) $originalStr : null;
                }

                $effectiveNum = null;

                $existingPricing = $existingByProductId[$pid] ?? null;
                $existingRuleType = $existingPricing instanceof ProductPricing ? ($existingPricing->getRuleType() ?? '') : '';
                $existingRuleValueRaw = $existingPricing instanceof ProductPricing ? ($existingPricing->getRuleValue() ?? '') : '';
                $existingRuleValueNum = PriceNumber::isPrice($existingRuleValueRaw) ? (float) $existingRuleValueRaw : null;

                $typeToApply = $existingRuleType !== '' ? $existingRuleType : 'Discount%';
                $valueToApply = $existingRuleValueNum;

                if ($field === 'type') {
                    $typeToApply = $type;
                    // Keep existing value. If it doesn't exist (older rows), derive a Discount% from effective+base so the rule stays stable.
                    if ($valueToApply === null) {
                        $existingEffectiveStr = $existingPricing instanceof ProductPricing ? $existingPricing->getPrice() : '';
                        $existingEffectiveNum = PriceNumber::isPrice($existingEffectiveStr) ? (float) $existingEffectiveStr : null;
                        // Zero, not sign, is what this guard is about. Every other `> 0` in this
                        // method is gone (#464), but a base of exactly 0.0 is the one base no
                        // percentage can be read out of: PHP 8 raises DivisionByZeroError rather
                        // than yielding INF, and there is no percentage of nothing that gives a
                        // non-zero price anyway. A NEGATIVE base divides perfectly well and yields a
                        // perfectly good percentage, so it is allowed through. `!== 0.0` also
                        // catches -0.0, since -0.0 == 0.0.
                        if ($baseNum !== null && $baseNum !== 0.0 && $existingEffectiveNum !== null) {
                            $valueToApply = (1 - ($existingEffectiveNum / $baseNum)) * 100;
                        } else {
                            // No existing rule/value/effective yet; fall back to the grid's default rule value.
                            $valueToApply = 10.0;
                        }
                    }
                } elseif ($field === 'value') {
                    $valueToApply = $valueNum;
                } else { // both
                    $typeToApply = $type;
                    $valueToApply = $valueNum;
                }

                if ($valueToApply === null) {
                    continue;
                }

                // No sign test on the base here either, exactly as in updatePrice(): a discount off a
                // base of 0 is 0, a discount off -5 is a number, and both are answers the rule can
                // give. Requiring a positive base used to abandon the computation instead, which is
                // how a bulk apply and a single save ended up storing different prices for the same
                // product (#464 item 1).
                if ($typeToApply === 'Number') {
                    $effectiveNum = $valueToApply;
                } elseif ($baseNum !== null) {
                    if ($typeToApply === 'Discount%') {
                        $effectiveNum = $baseNum * (1 - ($valueToApply / 100));
                    } elseif ($typeToApply === 'Discount$') {
                        $effectiveNum = $baseNum - $valueToApply;
                    }
                }

                $pricing = $existingByProductId[$pid] ?? null;
                if (!$pricing instanceof ProductPricing) {
                    $pricing = (new ProductPricing())
                        ->setProduct($entityManager->getReference(ProductCore::class, $pid))
                        ->setPriceList($entityManager->getReference(PriceList::class, $priceListId))
                        ->setCurrency($entityManager->getReference(PriceList::class, $priceListId)->getCurrency());
                    $entityManager->persist($pricing);
                }

                // Always persist the rule columns for every product, even if the effective can't be computed
                // (e.g. Discount% with no base price). This ensures pagination doesn't "reset" Type/Value.
                $pricing->setRuleType($typeToApply);
                $pricing->setRuleValue(number_format($valueToApply, 2, '.', ''));

                // The same three-step resolution updatePrice() performs, in the same order: an
                // applicable rule answers, a rule that resolves to nothing falls back to the BASE
                // price, and only a row with neither has nothing to write (#464 item 5). A negative
                // IS a computed answer and is stored as one — the sign is decided by the rule the
                // admin chose (#458) — and an unrecognised rule type resolves to nothing and so
                // lands on the base like any other non-rule (#464 item 7).
                //
                // The third step is the one place bulk apply cannot copy updatePrice(), which ends
                // on "keep whatever the client posted in the price field". A bulk request carries no
                // per-product posted price, so there is no such figure here. What updatePrice()'s
                // last resort actually preserves is the number already sitting on the row — that
                // hidden input is seeded from the stored effective price — so the faithful analogue
                // is to leave the stored price standing, which is what happens by not calling
                // setPrice(). The alternative, writing 0.00, would invent a price no admin asked for
                // and would zero out the real customer price of every product that has no base at
                // all. Rows that end here are counted and reported as `unresolved` so the outcome is
                // visible in the response rather than being the silent no-op it was before.
                if ($typeToApply === 'No Price' || $typeToApply === 'Hide') {
                    // Only reachable through field=value, which posts no type and therefore inherits
                    // whatever the row already carried. Neither is a rule that resolves to a price;
                    // they are an explicit absence of one, and updatePrice() answers both with 0.00.
                    // Without this arm they would fall through to the base fallback below and a
                    // "No Price" row would quietly become a priced one.
                    $pricing->setPrice('0.00');
                } elseif ($effectiveNum !== null && is_finite($effectiveNum)) {
                    $pricing->setPrice(number_format($effectiveNum, 2, '.', ''));
                } elseif ($baseNum !== null) {
                    $pricing->setPrice(number_format($baseNum, 2, '.', ''));
                } else {
                    $unresolved++;
                }

                $pricing->setCurrency($entityManager->getReference(PriceList::class, $priceListId)->getCurrency());
                $updated++;
            }

            $entityManager->flush();
            $entityManager->clear();
            $this->debugData?->reset();
        }

        return new JsonResponse([
            'ok' => true,
            'updated' => $updated,
            'processed' => $processed,
            'unresolved' => $unresolved,
            'message' => 'Bulk apply complete.',
            'debug' => [
                'price_list_id' => $priceListId,
                'field' => $field,
                'type' => $type,
                'value_raw' => $valueRaw,
                'filters' => $filters,
                'sort' => $sort,
                'dir' => $dir,
            ],
        ]);
    }

    #[Route('/product/core-price/update', name: 'admin_product_core_price_update', methods: ['POST'])]
    public function updateCorePrice(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $productId = $request->request->getInt('product_id', 0);
        $field = trim((string) $request->request->get('field', ''));
        $raw = trim((string) $request->request->get('price', ''));

        if ($productId <= 0 || $field !== 'default_price') {
            return new JsonResponse(['ok' => false, 'message' => 'Invalid request.'], Response::HTTP_BAD_REQUEST);
        }

        $product = $entityManager->find(ProductCore::class, $productId);
        if (!$product instanceof ProductCore) {
            return new JsonResponse(['ok' => false, 'message' => 'Not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($raw === '') {
            $product->setDefaultPrice(null)->touch();
            $entityManager->flush();
            return new JsonResponse(['ok' => true, 'price' => '', 'message' => 'Cleared.']);
        }

        $normalized = str_replace(',', '', $raw);
        if (!is_numeric($normalized) || (float) $normalized < 0) {
            return new JsonResponse(['ok' => false, 'message' => 'Price must be a valid non-negative number.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $price = number_format((float) $normalized, 2, '.', '');
        $product->setDefaultPrice($price)->touch();
        $entityManager->flush();

        return new JsonResponse(['ok' => true, 'price' => $price, 'message' => 'Saved.']);
    }

    #[Route('/product/suggested-price/update', name: 'admin_product_suggested_price_update', methods: ['POST'])]
    public function updateSuggestedPrice(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $productId = $request->request->getInt('product_id', 0);
        $type = trim((string) $request->request->get('type', ''));
        $valueRaw = trim((string) $request->request->get('value', ''));

        if ($productId <= 0) {
            return new JsonResponse(['ok' => false, 'message' => 'Invalid request.'], Response::HTTP_BAD_REQUEST);
        }
        if ($type !== '' && !in_array($type, self::SUGGESTED_PRICE_TYPES, true)) {
            return new JsonResponse(['ok' => false, 'message' => 'Invalid type.'], Response::HTTP_BAD_REQUEST);
        }

        $product = $entityManager->find(ProductCore::class, $productId);
        if (!$product instanceof ProductCore) {
            return new JsonResponse(['ok' => false, 'message' => 'Not found.'], Response::HTTP_NOT_FOUND);
        }

        // A negative Markup$/Markup% is a deliberate below-cost/loss-leader price, not an error — a
        // markup is already relative to the base price, so negative just means "under base" instead
        // of "over base". Only reject genuinely non-numeric input, same principle as the Discount%/
        // Discount$/Number price-list rule fields (updatePrice() above). Exponent notation is not
        // numeric for this purpose: `1e3` would become a markup of 1000.00 (#464).
        $normalizedValue = str_replace(',', '', $valueRaw);
        if ($normalizedValue !== '' && !PriceNumber::isPrice($normalizedValue)) {
            return new JsonResponse(['ok' => false, 'message' => 'Value must be a valid number.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $storedValue = $normalizedValue === '' ? '' : number_format((float) $normalizedValue, 2, '.', '');

        $product->setSuggestedPriceType($type === '' ? null : $type);
        $product->setSuggestedPriceValue($storedValue === '' ? null : $storedValue);
        $entityManager->flush();

        $effective = $this->suggestedPriceCalculator->getEffectivePrice($product) ?? '';

        return new JsonResponse(['ok' => true, 'type' => $type, 'value' => $storedValue, 'effective' => $effective, 'message' => 'Saved.']);
    }

    #[Route('/product/suggested-price/bulk-apply', name: 'admin_product_suggested_price_bulk_apply', methods: ['POST'])]
    public function bulkApplySuggestedPrice(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        if (!$request->isXmlHttpRequest()) {
            return new JsonResponse(['ok' => false, 'message' => 'Invalid request.'], Response::HTTP_BAD_REQUEST);
        }

        // Which column the caller is applying. [A] on Type must not overwrite Value and vice versa
        // (#529) — this endpoint used to write both unconditionally, so one click flattened two
        // columns across every product. Defaults to 'both', which is what it always did, so a caller
        // that does not send the field keeps its old behaviour.
        $field = strtolower(trim((string) $request->request->get('field', 'both')));
        if (!in_array($field, ['type', 'value', 'both'], true)) {
            return new JsonResponse(['ok' => false, 'message' => 'Invalid field.'], Response::HTTP_BAD_REQUEST);
        }

        $type = trim((string) $request->request->get('type', ''));
        $valueRaw = trim((string) $request->request->get('value', ''));

        if ($field !== 'value' && $type !== '' && !in_array($type, self::SUGGESTED_PRICE_TYPES, true)) {
            return new JsonResponse(['ok' => false, 'message' => 'Invalid type.'], Response::HTTP_BAD_REQUEST);
        }

        // A negative Markup$/Markup% is a deliberate below-cost/loss-leader price, not an error — only
        // reject genuinely non-numeric input — exponent notation included — same as
        // updateSuggestedPrice() above (#464).
        $normalizedValue = str_replace(',', '', $valueRaw);
        if ($normalizedValue !== '' && !PriceNumber::isPrice($normalizedValue)) {
            return new JsonResponse(['ok' => false, 'message' => 'Value must be a valid number.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $storedValue = $normalizedValue === '' ? '' : number_format((float) $normalizedValue, 2, '.', '');

        // "Apply to all pages" must only touch products matching the grid's active filters — same
        // reasoning as bulkApplyPriceRule() above: re-select the matching set server-side rather than
        // trusting any client-supplied id list.
        /** @var array<string, mixed> $rawFilters */
        $rawFilters = $request->request->all('filters');
        if (!is_array($rawFilters) || $rawFilters === []) {
            $rawFilters = $request->query->all('filters');
        }
        $filters = [
            'id' => trim((string) ($rawFilters['id'] ?? '')),
            'sku' => trim((string) ($rawFilters['sku'] ?? '')),
            'name' => trim((string) ($rawFilters['name'] ?? '')),
            'category' => trim((string) ($rawFilters['category'] ?? '')),
            'price' => trim((string) ($rawFilters['price'] ?? '')),
            'default_price' => trim((string) ($rawFilters['default_price'] ?? '')),
        ];

        $repo = $entityManager->getRepository(ProductCore::class);
        $qb = $repo->createQueryBuilder('p')
            ->where('p.deleted = false');

        $idFilter = $filters['id'];
        if ($idFilter !== '' && ctype_digit($idFilter)) {
            $qb->andWhere('p.id = :fid')->setParameter('fid', (int) $idFilter);
        }

        $skuFilter = $filters['sku'];
        if ($skuFilter !== '') {
            $qb->andWhere('p.sku LIKE :fsku')->setParameter('fsku', '%' . $skuFilter . '%');
        }

        $nameFilter = $filters['name'];
        if ($nameFilter !== '') {
            $qb->andWhere('p.name LIKE :fname')->setParameter('fname', '%' . $nameFilter . '%');
        }

        $categoryFilter = $filters['category'];
        if ($categoryFilter !== '') {
            $qb->leftJoin('p.category', 'cat');
            $qb->andWhere('cat.id = :fcatId')->setParameter('fcatId', (int) $categoryFilter);
        }

        $defaultPriceFilterRaw = $filters['default_price'];
        if ($defaultPriceFilterRaw !== '') {
            $money = $this->normalizeMoneyFilter($defaultPriceFilterRaw);
            if ($money !== null) {
                $qb->andWhere('p.defaultPrice = :fprice')->setParameter('fprice', $money);
            }
        }

        $productPriceFilterRaw = $filters['price'];
        if ($productPriceFilterRaw !== '') {
            $money = $this->normalizeMoneyFilter($productPriceFilterRaw);
            if ($money !== null) {
                $qb->andWhere('p.originalPrice = :pp')->setParameter('pp', $money);
            }
        }

        $qb->orderBy('p.id', 'ASC');

        $batchSize = 250;
        $updated = 0;
        $processed = 0;
        // Keyset cursor, not OFFSET/LIMIT — see the matching comment in bulkApplyPriceRule() above
        // (#768): a growing OFFSET compiles a distinct, permanently-cached query per page.
        $cursor = 0;

        while (true) {
            $batchQb = clone $qb;
            $products = $batchQb
                ->andWhere('p.id > :cursor')
                ->setParameter('cursor', $cursor)
                ->setMaxResults($batchSize)
                ->getQuery()
                ->getResult();

            if ($products === []) {
                break;
            }

            foreach ($products as $product) {
                if (!$product instanceof ProductCore) {
                    continue;
                }
                if ($field !== 'value') {
                    $product->setSuggestedPriceType($type === '' ? null : $type);
                }
                if ($field !== 'type') {
                    $product->setSuggestedPriceValue($storedValue === '' ? null : $storedValue);
                }
                $cursor = max($cursor, (int) $product->getId());
                $updated++;
            }
            $processed += count($products);

            $entityManager->flush();
            $entityManager->clear();
            $this->debugData?->reset();
        }

        return new JsonResponse([
            'ok' => true,
            'updated' => $updated,
            'processed' => $processed,
            'message' => 'Bulk apply complete.',
        ]);
    }

    /**
     * @param list<PriceList> $priceLists
     * @param array<int, array<int, ProductPricing>> $pricingByProductAndList
     * @return array<string, mixed>
     */
    private function productToPriceRow(ProductCore $product, EntityManagerInterface $entityManager, array $priceLists, array $pricingByProductAndList, array $firstPricingByProductId): array
    {
        $row = $this->productToRow($product, $entityManager);
        $row['defaultPriceRaw'] = $product->getDefaultPrice() ?? '';
        // "Product Price" base for calculations: comes from import / product edit.
        // Use originalPrice as the raw numeric base value.
        $row['priceRaw'] = $product->getOriginalPrice() ?? '';
        $pid = $product->getId() ?? 0;

        $row['suggestedType'] = $product->getSuggestedPriceType() ?? '';
        $row['suggestedValue'] = $product->getSuggestedPriceValue() ?? '';
        $row['suggestedEffective'] = $this->suggestedPriceCalculator->getEffectivePrice($product) ?? '';

        $prices = [];
        $pricingMeta = [];
        $base = $product->getDefaultPrice();
        if ($base === null || $base === '') {
            $base = $row['priceRaw'] !== '' ? (string) $row['priceRaw'] : null;
        }
        $baseNum = ($base !== null && $base !== '' && is_numeric($base)) ? (float) $base : null;
        foreach ($priceLists as $pl) {
            if (!$pl instanceof PriceList || $pl->getId() === null) {
                continue;
            }
            $lid = (int) $pl->getId();
            $pricing = $pricingByProductAndList[$pid][$lid] ?? null;
            $effective = $pricing instanceof ProductPricing ? $pricing->getPrice() : '';
            $prices[(string) $lid] = $effective;

            $effectiveNum = ($effective !== '' && is_numeric($effective)) ? (float) $effective : null;

            $type = $pricing instanceof ProductPricing ? ($pricing->getRuleType() ?? '') : '';
            $value = $pricing instanceof ProductPricing ? ($pricing->getRuleValue() ?? '') : '';

            if ($type === 'No Price') {
                $pricingMeta[(string) $lid] = [
                    'type' => 'No Price',
                    'value' => '',
                    'effective' => 'Unavailable',
                    'effectiveRaw' => '0.00',
                ];

                continue;
            }

            if ($type === 'Hide') {
                $pricingMeta[(string) $lid] = [
                    'type' => 'Hide',
                    'value' => '',
                    'effective' => 'Hidden',
                    'effectiveRaw' => '0.00',
                ];

                continue;
            }

            if ($type === '' || !in_array($type, ['Discount%', 'Discount$', 'Number'], true)) {
                // Backward compatibility for existing rows: derive a stable default view.
                $type = 'Discount%';
                $value = '';
                if ($effectiveNum !== null) {
                    if ($baseNum !== null && $baseNum > 0) {
                        $pct = (1 - ($effectiveNum / $baseNum)) * 100;
                        $value = number_format($pct, 2, '.', '');
                    } else {
                        $value = '0.00';
                    }
                }
            }

            // Compute "effective" from rule + base, so it stays correct on page load.
            // Base selection: Default Price if present, else Product Price (row['priceRaw']).
            $effectiveDisplay = '';
            $ruleValueNum = ($value !== '' && is_numeric($value)) ? (float) $value : null;
            if ($type === 'Number' && $ruleValueNum !== null) {
                $effectiveDisplay = number_format($ruleValueNum, 2, '.', '');
            } elseif ($ruleValueNum !== null && $baseNum !== null && $baseNum > 0) {
                $computed = null;
                if ($type === 'Discount%') {
                    $computed = $baseNum * (1 - ($ruleValueNum / 100));
                } elseif ($type === 'Discount$') {
                    $computed = $baseNum - $ruleValueNum;
                }
                if ($computed !== null) {
                    // No floor. This is the grid's own display recompute, and it was written in May
                    // with a `$computed < 0 ? 0` clamp — before #458 removed the zero floor from the
                    // write paths and #462 removed it from the customer-facing resolvers. Those two
                    // changes reached every path that decides or serves a price; this one only
                    // decides what the admin is shown, so nothing pointed at it and it kept
                    // flooring. The result was a product whose price is stored as -50.00 and
                    // charged as -50.00 rendering as $0.00 on the one screen an admin uses to check
                    // prices — the display disagreeing with both the database and the customer.
                    $effectiveDisplay = number_format($computed, 2, '.', '');
                }
            }

            // If no rule exists yet, fall back to stored effective (if any), otherwise apply UI defaults.
            if ($effectiveDisplay === '' && $effective !== '' && is_numeric($effective)) {
                $effectiveDisplay = number_format((float) $effective, 2, '.', '');
            }
            if ($effectiveDisplay === '' && $baseNum !== null && $baseNum > 0) {
                $type = 'Discount%';
                $value = $value !== '' ? $value : '10.00';
                $effectiveDisplay = number_format($baseNum * 0.9, 2, '.', '');
            }
            if ($effectiveDisplay === '' && ($baseNum === null || $baseNum <= 0)) {
                $type = 'Discount%';
                $value = $value !== '' ? $value : '0.00';
                $effectiveDisplay = '';
            }

            $pricingMeta[(string) $lid] = [
                'type' => $type,
                'value' => $value,
                'effective' => $effectiveDisplay,
            ];
        }

        $row['pricesByListId'] = $prices;
        $row['pricingMetaByListId'] = $pricingMeta;

        return $row;
    }

    #[Route('/product/inventory/create', name: 'admin_product_inventory_create', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $entityManager, AppSettings $appSettings): Response
    {
        // Stamped at construction rather than just before persist(): this is the only route that
        // creates a product from the admin form, so anything it saves is by definition hand-made.
        // No import path can reach here, and nothing below overwrites syncSource — the form has no
        // field for it. See issue #477.
        $product = (new ProductCore())->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);

        if ($request->isMethod('POST')) {
            $errors = $this->validateProductRequest($request, $entityManager);
            if ($errors !== []) {
                $this->addFlash('error', implode(' ', $errors));

                return $this->render('admin/product/form.html.twig', [
                    'mode' => 'Create',
                    'product' => $this->productFormRowFromRequest($request),
                    'categories' => $this->categoryRowsFromDatabase($entityManager),
                    'priceLists' => $this->priceListRowsFromDatabase($entityManager),
                    'companies' => $this->companyOptions($entityManager),
                    'fulfillmentRegions' => $this->fulfillmentRegionNames($entityManager, $appSettings),
                    'bucketRows' => $this->bucketRowsByFulfillmentRegion($product, $entityManager),
                    'bucketColumns' => $this->defaultBucketColumns(),
                    'feeFieldFragments' => [],
                    'customFieldFragment' => '',
                ] + $this->trackingPolicyViewVars($product), new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $privateCompanyIds = $this->applyProductRequest($product, $request, $entityManager);
            if ($product->getName() !== '' && $product->getSku() !== '') {
                $entityManager->persist($product);
                $entityManager->flush();
                $this->productPrivacyLinker->sync($product, $privateCompanyIds, $entityManager);
                $this->syncProductPricing($product, $request, $entityManager);
                $this->syncProductInventory($product, $request, $entityManager, $appSettings);
                foreach ($this->feeFieldProviders as $provider) {
                    if ($provider instanceof FeeFieldProviderInterface && $this->bundleStatusRepo->isActiveForInstance($provider)) {
                        $provider->saveProductFields($product, $request);
                    }
                }
                $this->customFieldRenderer->saveFromRequest('product', $product, $request);
                $entityManager->flush();
                $this->addFlash('success', sprintf('Product "%s" was created successfully.', $product->getName()));
            } else {
                $this->addFlash('error', 'Product name and SKU are required.');
            }

            $redirectRoute = $request->query->get('redirect');
            if ($redirectRoute && $this->container->get('router')->getRouteCollection()->get($redirectRoute)) {
                return $this->redirectToRoute($redirectRoute);
            }

            return $this->redirectToRoute('admin_product_detail_index');
        }

        $feeFieldFragments = [];
        foreach ($this->feeFieldProviders as $provider) {
            if ($provider instanceof FeeFieldProviderInterface && $this->bundleStatusRepo->isActiveForInstance($provider)) {
                $feeFieldFragments[] = $provider->renderProductFields($product);
            }
        }

        return $this->render('admin/product/form.html.twig', [
            'mode' => 'Create',
            'product' => $this->emptyProductRow(),
            'categories' => $this->categoryRowsFromDatabase($entityManager),
            'priceLists' => $this->priceListRowsFromDatabase($entityManager),
            'companies' => $this->companyOptions($entityManager),
            'fulfillmentRegions' => $this->fulfillmentRegionNames($entityManager, $appSettings),
            'bucketRows' => $this->bucketRowsByFulfillmentRegion($product, $entityManager),
            'bucketColumns' => $this->defaultBucketColumns(),
            'feeFieldFragments' => $feeFieldFragments,
            'customFieldFragment' => $this->customFieldRenderer->renderFields('product', null, 'add'),
        ] + $this->trackingPolicyViewVars($product));
    }

    #[Route('/product/inventory/update/{id}', name: 'admin_product_inventory_update', methods: ['GET', 'POST'])]
    public function update(int $id, Request $request, EntityManagerInterface $entityManager, AppSettings $appSettings): Response
    {
        $product = $entityManager->find(ProductCore::class, $id);
        if (!$product instanceof ProductCore) {
            $this->addFlash('error', 'Product could not be found.');
            return $this->redirectToRoute('admin_product_detail_index');
        }

        if ($request->isMethod('POST')) {
            $errors = $this->validateProductRequest($request, $entityManager, $product);
            if ($errors !== []) {
                $this->addFlash('error', implode(' ', $errors));

                $feeFieldFragments = [];
                foreach ($this->feeFieldProviders as $provider) {
                    if ($provider instanceof FeeFieldProviderInterface && $this->bundleStatusRepo->isActiveForInstance($provider)) {
                        $feeFieldFragments[] = $provider->renderProductFields($product);
                    }
                }

                return $this->render('admin/product/form.html.twig', [
                    'mode' => 'Update',
                    'product' => $this->productFormRowFromRequest($request, $product),
                    'categories' => $this->categoryRowsFromDatabase($entityManager),
                    'priceLists' => $this->priceListRowsFromDatabase($entityManager),
                    'companies' => $this->companyOptions($entityManager),
                    'fulfillmentRegions' => $this->fulfillmentRegionNames($entityManager, $appSettings),
                    'bucketRows' => $this->bucketRowsByFulfillmentRegion($product, $entityManager),
                    'bucketColumns' => $this->defaultBucketColumns(),
                    'feeFieldFragments' => $feeFieldFragments,
                    'customFieldFragment' => $this->customFieldRenderer->renderFields('product', $product, 'edit'),
                ] + $this->inventoryModeViewVars($product), new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            $privateCompanyIds = $this->applyProductRequest($product, $request, $entityManager);
            if ($product->getName() !== '' && $product->getSku() !== '') {
                $product->touch();
                $this->syncProductPricing($product, $request, $entityManager);
                // Before syncProductInventory(), which returns without reading a box the form did
                // not render once the product is dimensional. The other order would let one save
                // both switch the mode on AND write a typed quantity over the opening balance.
                $this->applyInventoryModeFromRequest($product, $request);
                $this->syncProductInventory($product, $request, $entityManager, $appSettings);
                foreach ($this->feeFieldProviders as $provider) {
                    if ($provider instanceof FeeFieldProviderInterface && $this->bundleStatusRepo->isActiveForInstance($provider)) {
                        $provider->saveProductFields($product, $request);
                    }
                }
                $this->customFieldRenderer->saveFromRequest('product', $product, $request);
                $entityManager->flush();
                $this->productPrivacyLinker->sync($product, $privateCompanyIds, $entityManager);
                $this->addFlash('success', sprintf('Product "%s" was updated successfully.', $product->getName()));
            } else {
                $this->addFlash('error', 'Product name and SKU are required.');
            }

            $redirectRoute = $request->query->get('redirect');
            if ($redirectRoute && $this->container->get('router')->getRouteCollection()->get($redirectRoute)) {
                return $this->redirectToRoute($redirectRoute);
            }

            return $this->redirectToRoute('admin_product_detail_index');
        }

        $feeFieldFragments = [];
        foreach ($this->feeFieldProviders as $provider) {
            if ($provider instanceof FeeFieldProviderInterface && $this->bundleStatusRepo->isActiveForInstance($provider)) {
                $feeFieldFragments[] = $provider->renderProductFields($product);
            }
        }

        return $this->render('admin/product/form.html.twig', [
            'mode' => 'Update',
            'product' => $this->productToFormRow($product, $entityManager),
            'categories' => $this->categoryRowsFromDatabase($entityManager),
            'priceLists' => $this->priceListRowsFromDatabase($entityManager),
            'companies' => $this->companyOptions($entityManager),
            'fulfillmentRegions' => $this->fulfillmentRegionNames($entityManager, $appSettings),
            'bucketRows' => $this->bucketRowsByFulfillmentRegion($product, $entityManager),
            'bucketColumns' => $this->defaultBucketColumns(),
            'feeFieldFragments' => $feeFieldFragments,
            'customFieldFragment' => $this->customFieldRenderer->renderFields('product', $product, 'edit'),
        ] + $this->inventoryModeViewVars($product));
    }

    #[Route('/product/delete/{id}', name: 'admin_product_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $product = $entityManager->find(ProductCore::class, $id);
        if ($product instanceof ProductCore) {
            $name = $product->getName();
            $filenames = [];
            foreach ($product->getImages() as $img) {
                if ($img instanceof ProductImage) {
                    $filename = $img->getFilename();
                    if ($filename !== '') {
                        $filenames[] = $filename;
                    }
                }
            }
            $entityManager->remove($product);

            try {
                $entityManager->flush();
            } catch (ForeignKeyConstraintViolationException) {
                return new JsonResponse([
                    'ok' => false,
                    'message' => sprintf('Product "%s" is still referenced elsewhere (for example, an order, estimate or credit memo) and cannot be deleted. Mark it as deleted instead.', $name),
                ], Response::HTTP_CONFLICT);
            }

            foreach ($filenames as $filename) {
                $this->removeImageFile($filename);
            }

            return new JsonResponse(['ok' => true, 'message' => sprintf('Product "%s" was deleted successfully.', $name)]);
        }

        return new JsonResponse(['ok' => false, 'message' => 'Product could not be found.'], Response::HTTP_NOT_FOUND);
    }

    #[Route('/product', name: 'admin_product_redirect', methods: ['GET'])]
    public function redirectToDetails(): RedirectResponse
    {
        return $this->redirectToRoute('admin_product_detail_index');
    }

    /** @return list<array<string, mixed>> */
    private function productRowsFromDatabase(EntityManagerInterface $entityManager): array
    {
        return array_map(
            fn (ProductCore $product): array => $this->productToRow($product, $entityManager),
            $entityManager->getRepository(ProductCore::class)->findBy(['deleted' => false], ['id' => 'ASC'])
        );
    }

    /** @return array<string, mixed> */
    private function productToRow(
        ProductCore $product,
        EntityManagerInterface $entityManager,
        string $invMainName = 'Main',
        string $invOverflowName = 'Overflow',
        string $invSupplierName = 'Supplier Direct'
    ): array
    {
        $inventory = $this->inventoryMap($product, $entityManager);
        $privateCompanyNames = [];
        foreach ($product->getPrivateCompanies() as $company) {
            if ($company instanceof Company) {
                $privateCompanyNames[] = $company->getName();
            }
        }

        return [
            'id' => (string) $product->getId(),
            'name' => $product->getName(),
            'sku' => $product->getSku(),
            'type' => $product->getType() ?? '-',
            'unit' => $product->getUnit() ?? '-',
            'weight' => $product->getWeight() ?? '-',
            'visible' => $product->isVisible() ? 'Yes' : 'No',
            'status' => $product->getStatus(),
            'category' => $product->getCategory() !== null ? $this->categoryPath($product->getCategory()) : '-',
            'cost' => $this->money($product->getCostPrice()),
            'original' => $this->money($product->getOriginalPrice()),
            // Product Detail grid: show Default Price only; otherwise blank.
            'price' => $this->money($product->getDefaultPrice()),
            'deposit' => $this->money($product->getDeposit()),
            'inventoryByFulfillmentRegion' => $this->inventoryByFulfillmentRegionRow($inventory),
            'salesTaxCode' => $product->getSalesTaxCode() ?? '-',
            'shippingClass' => $product->getShippingClass() ?? '-',
            'syncSource' => $product->getSyncSource() ?? self::SYNC_SOURCE_UNKNOWN_LABEL,
            'remarks' => $product->getRemarks() ?? '',
            'short' => $product->getShortDescription() ?? '',
            'long' => $product->getLongDescription() ?? '',
            'privacy' => $product->isPrivate() ? 'Private' : 'Public',
            'privateCompanies' => $privateCompanyNames === [] ? '' : implode(', ', $privateCompanyNames),
            'image' => $product->getPrimaryImageFilename() ?? '',
            'customFields' => $this->customFieldRenderer->renderListingFragment($product),
        ];
    }

    /** @return array<string, mixed> */
    private function productToFormRow(ProductCore $product, EntityManagerInterface $entityManager): array
    {
        $pricing = $this->firstPricing($product, $entityManager);
        $companyIds = [];
        foreach ($product->getPrivateCompanies() as $company) {
            if ($company instanceof Company && $company->getId() !== null) {
                $companyIds[] = (string) $company->getId();
            }
        }

        return [
            'id' => (string) ($product->getId() ?? ''),
            'name' => $product->getName(),
            'sku' => $product->getSku(),
            'type' => $product->getType() ?? '',
            'unit' => $product->getUnit() ?? '',
            'weight' => $product->getWeight() ?? '',
            'visible' => $product->isVisible() ? 'Yes' : 'No',
            'featured' => $product->isFeatured() ? 'Yes' : 'No',
            'status' => $product->getStatus(),
            'categoryId' => (string) ($product->getCategory()?->getId() ?? ''),
            'costRaw' => $product->getCostPrice() ?? '',
            'originalRaw' => $product->getOriginalPrice() ?? '',
            'priceRaw' => $pricing?->getPrice() ?? '',
            'depositRaw' => $product->getDeposit() ?? '',
            'privateCompanyIds' => $companyIds,
            'deletedRaw' => $product->isDeleted() ? 'Yes' : 'No',
            'salesTaxCode' => $this->normalizeSalesTaxCode($product->getSalesTaxCode() ?? ''),
            'shippingClass' => $product->getShippingClass() ?? '',
            'remarks' => $product->getRemarks() ?? '',
            'short' => $product->getShortDescription() ?? '',
            'long' => $product->getLongDescription() ?? '',
            'priceListId' => (string) ($pricing?->getPriceList()?->getId() ?? ''),
            'inventory' => $this->inventoryMap($product, $entityManager),
            'image' => $product->getPrimaryImageFilename() ?? '',
            'images' => $this->productImageRows($product),
        ];
    }

    /** @return list<array{id: int, filename: string, primary: bool}> */
    private function productImageRows(ProductCore $product): array
    {
        return array_map(
            static fn (ProductImage $image): array => [
                'id' => $image->getId(),
                'filename' => $image->getFilename(),
                'primary' => $image->isPrimaryImage(),
            ],
            $this->productImageRepository->findByProduct($product)
        );
    }

    /** @return array<string, mixed> */
    private function emptyProductRow(): array
    {
        return [
            'id' => '',
            'name' => '',
            'sku' => '',
            'type' => '',
            'unit' => '',
            'weight' => '',
            'visible' => 'Yes',
            'featured' => 'No',
            'status' => ProductStatus::Active->value,
            'categoryId' => '',
            'costRaw' => '',
            'originalRaw' => '',
            'priceRaw' => '',
            'depositRaw' => '',
            'privateCompanyIds' => [],
            'deletedRaw' => 'No',
            'salesTaxCode' => '',
            'shippingClass' => '',
            'remarks' => '',
            'short' => '',
            'long' => '',
            'priceListId' => '',
            'inventory' => [],
            'image' => '',
            'images' => [],
        ];
    }

    /** @return list<string> */
    /**
     * Ported to a symfony/validator constraint for #308, following ValidCustomFieldRequest's #310
     * pattern: ValidProductRequestValidator runs the same checks this method used to run by hand —
     * image MIME/size, name/SKU presence, SKU uniqueness, category existence, and the
     * non-negative-numeric fields — so a future fix is inherited by create() and update() without
     * either changing anything.
     */
    private function validateProductRequest(Request $request, EntityManagerInterface $entityManager, ?ProductCore $existingProduct = null): array
    {
        $name = trim((string) $request->request->get('name', ''));

        $violations = Validation::createValidator()->validate($name, new ValidProductRequest(
            sku: trim((string) $request->request->get('sku', '')),
            images: $this->uploadedImageFiles($request),
            categoryId: trim((string) $request->request->get('category_id', '')),
            entityManager: $entityManager,
            existingProductId: $existingProduct?->getId(),
            weight: trim((string) $request->request->get('weight', '')),
            costPrice: trim((string) $request->request->get('cost_price', '')),
            originalPrice: trim((string) $request->request->get('original_price', '')),
            salePrice: trim((string) $request->request->get('sale_price', '')),
            deposit: trim((string) $request->request->get('deposit', '')),
        ));

        $errors = array_map(static fn ($violation): string => (string) $violation->getMessage(), iterator_to_array($violations));

        // Status is checked here rather than inside the constraint because the answer depends on
        // the product's CURRENT value, which the constraint is not given: a post that repeats a
        // status ProductStatus does not know is "leave this legacy row alone" and is allowed,
        // while a post that tries to WRITE an unknown status is refused out loud. Refusing it is
        // the point — the value used to be written verbatim, so 'active' and 'Draf' both became
        // rows that no `= 'Active'` comparison matched and no screen explained.
        $postedStatus = trim((string) $request->request->get('status', ''));
        if (
            $postedStatus !== ''
            && $postedStatus !== ($existingProduct?->getStatus() ?? ProductStatus::Active->value)
            && ProductStatus::tryFrom($postedStatus) === null
        ) {
            $errors[] = sprintf(
                '"%s" is not a product status this application knows. Choose one of: %s.',
                $postedStatus,
                implode(', ', ProductStatus::values()),
            );
        }

        return $errors;
    }

    /** @return array<string, mixed> */
    private function productFormRowFromRequest(Request $request, ?ProductCore $existingProduct = null): array
    {
        $row = $this->emptyProductRow();
        $row['id'] = $existingProduct?->getId() ? (string) $existingProduct->getId() : '';
        $row['name'] = trim((string) $request->request->get('name', ''));
        $row['sku'] = trim((string) $request->request->get('sku', ''));
        $row['type'] = trim((string) $request->request->get('type', ''));
        // NOT from the request: the label is derived from the declaration and the form has not
        // emitted a `unit` field since #601. Reading the post here re-rendered a rejected form with
        // an empty U/M, so the "unmapped"/"pre-selected" note beside the select — both of which
        // quote this label — vanished on exactly the screen an admin was trying to correct.
        $row['unit'] = $existingProduct?->getUnit() ?? '';
        $row['weight'] = trim((string) $request->request->get('weight', ''));
        $row['costRaw'] = trim((string) $request->request->get('cost_price', ''));
        $row['originalRaw'] = trim((string) $request->request->get('original_price', ''));
        $row['priceRaw'] = trim((string) $request->request->get('sale_price', ''));
        $row['depositRaw'] = trim((string) $request->request->get('deposit', ''));
        $row['status'] = (string) $request->request->get('status', 'Active');
        $row['visible'] = (string) $request->request->get('visible', 'Yes');
        $row['featured'] = $request->request->get('featured') === '1' ? 'Yes' : 'No';
        $row['deletedRaw'] = $request->request->get('deleted', 'No') === 'Yes' ? 'Yes' : 'No';
        $privateCompanies = $request->request->all('private_companies');
        $privateCompanies = is_array($privateCompanies) ? $privateCompanies : [];
        $row['privateCompanyIds'] = array_values(array_filter(
            array_map(fn ($v): string => trim((string) $v), $privateCompanies),
            fn (string $v): bool => $v !== ''
        ));
        $row['categoryId'] = trim((string) $request->request->get('category_id', ''));
        $row['salesTaxCode'] = $this->normalizeSalesTaxCode(trim((string) $request->request->get('sales_tax_code', '')));
        $row['shippingClass'] = trim((string) $request->request->get('shipping_class', ''));
        $row['remarks'] = (string) $request->request->get('remarks', '');
        $row['short'] = (string) $request->request->get('short_description', '');
        $row['long'] = (string) $request->request->get('long_description', '');
        $row['priceListId'] = trim((string) $request->request->get('price_list_id', ''));
        $row['inventory'] = [];
        foreach ($request->request->all('inventory') as $location => $value) {
            $row['inventory'][(string) $location] = max(0, (int) $value);
        }
        $row['image'] = $existingProduct?->getPrimaryImageFilename() ?? '';
        $row['images'] = $existingProduct instanceof ProductCore ? $this->productImageRows($existingProduct) : [];

        return $row;
    }

    /** @return list<UploadedFile> */
    private function uploadedImageFiles(Request $request): array
    {
        $files = $request->files->get('images', []);
        if ($files instanceof UploadedFile) {
            $files = [$files];
        }
        if (!is_array($files)) {
            return [];
        }

        return array_values(array_filter($files, static fn ($file): bool => $file instanceof UploadedFile));
    }

    /**
     * Writes the status the form posted, through ProductCore's verbs (there is no setStatus()).
     *
     * Three shapes of post reach here, and only one of them changes anything:
     *
     *  - a status ProductStatus names -> the matching verb runs;
     *  - a status equal to what the product already carries -> nothing is written, which is what
     *    lets somebody edit the name of a row whose status predates this enum without being made
     *    to reclassify it first;
     *  - anything else -> already refused by validateProductRequest() before we get here, so this
     *    leaves the value alone rather than falling back to Active. The old code defaulted a
     *    missing or unreadable post to 'Active' and that is the silent activation this change
     *    exists to stop: a Draft product carries an unresolved unit, and letting stock move at an
     *    unknown unit is the one thing queue item 9 says must not happen.
     */
    private function applyStatusFromRequest(ProductCore $product, Request $request): void
    {
        $posted = trim((string) $request->request->get('status', ''));
        if ($posted === '' || $posted === $product->getStatus()) {
            return;
        }

        $choice = ProductStatus::tryFrom($posted);
        if ($choice === null) {
            return;
        }

        $product->applyStatusChoice($choice);
    }

    /** @return list<int> valid, existing company IDs requested as "private" — sync to the DB separately via ProductPrivacyLinker::sync() */
    private function applyProductRequest(ProductCore $product, Request $request, EntityManagerInterface $entityManager): array
    {
        $this->applyProductImages($product, $request, $entityManager);
        $this->applyTrackingPolicyFromRequest($product, $request);
        $this->applyBaseUnitFromRequest($product, $request);
        $this->applyAvailableUnitsFromRequest($product, $request);
        $this->applyStatusFromRequest($product, $request);

        $category = $entityManager->find(ProductCategory::class, (int) $request->request->get('category_id'));
        $shortDescription = trim((string) $request->request->get('short_description', ''));
        $longDescription = trim((string) $request->request->get('long_description', ''));

        $product
            ->setName(trim((string) $request->request->get('name', '')))
            ->setSku(trim((string) $request->request->get('sku', '')))
            ->setType($this->nullableText($request->request->get('type')))
            // `unit` is NOT read from the request any more: the Base Unit select declares it and
            // ProductBaseUnitService::assign() writes the label, so the two cannot disagree. A
            // posted `unit` is ignored rather than trusted — the form no longer emits one, and an
            // old bookmark or a replayed post must not be able to set a label the declaration
            // contradicts.
            ->setWeight($this->nullableText($request->request->get('weight')))
            ->setCostPrice($this->nullableMoney($request->request->get('cost_price')))
            ->setOriginalPrice($this->nullableMoney($request->request->get('original_price')))
            ->setDeposit($this->nullableMoney($request->request->get('deposit')))
            ->setCategory($category instanceof ProductCategory ? $category : null)
            ->setVisible($request->request->get('visible', 'Yes') === 'Yes')
            ->setFeatured($request->request->get('featured') === '1')
            ->setDeleted($request->request->get('deleted', 'No') === 'Yes')
            ->setSalesTaxCode($this->nullableText($this->normalizeSalesTaxCode((string) $request->request->get('sales_tax_code'))))
            ->setShippingClass($this->nullableText($request->request->get('shipping_class')))
            ->setRemarks($this->nullableText($request->request->get('remarks')))
            ->setShortDescription($shortDescription !== '' ? $shortDescription : null)
            ->setLongDescription($longDescription !== '' ? $longDescription : null)
            ->setDescription($longDescription !== '' ? $longDescription : ($shortDescription !== '' ? $shortDescription : null));

        $rawCompanyIds = $request->request->all('private_companies');
        $rawCompanyIds = is_array($rawCompanyIds) ? $rawCompanyIds : [];

        $validCompanyIds = [];
        foreach ($rawCompanyIds as $id) {
            $id = trim((string) $id);
            if ($id === '' || !ctype_digit($id)) {
                continue;
            }
            if ($entityManager->find(Company::class, (int) $id) instanceof Company) {
                $validCompanyIds[] = (int) $id;
            }
        }

        $product->setPrivate($validCompanyIds !== []);

        return $validCompanyIds;
    }

    private function applyProductImages(ProductCore $product, Request $request, EntityManagerInterface $entityManager): void
    {
        $removeIds = [];
        foreach ($request->request->all('remove_image_ids') as $rawId) {
            if (ctype_digit((string) $rawId)) {
                $removeIds[(int) $rawId] = true;
            }
        }

        if ($removeIds !== []) {
            foreach ($product->getImages()->toArray() as $img) {
                if ($img instanceof ProductImage && $img->getId() !== null && isset($removeIds[$img->getId()])) {
                    $filename = $img->getFilename();
                    if ($filename !== '') {
                        $this->removeImageFile($filename);
                    }
                    $product->removeImage($img);
                    $entityManager->remove($img);
                }
            }
        }

        $nextSortOrder = 0;
        foreach ($product->getImages() as $img) {
            if ($img instanceof ProductImage) {
                $nextSortOrder = max($nextSortOrder, $img->getSortOrder() + 1);
            }
        }

        foreach ($this->uploadedImageFiles($request) as $imageFile) {
            $filename = bin2hex(random_bytes(10)) . '.' . ($imageFile->guessExtension() ?? 'jpg');
            $imageFile->move($this->uploadDir(), $filename);

            $productImage = (new ProductImage())
                ->setFilename($filename)
                ->setSortOrder($nextSortOrder++)
                ->setPrimaryImage(false);
            $product->addImage($productImage);
        }

        $orderedImageIds = [];
        foreach ($request->request->all('image_order') as $rawId) {
            if (ctype_digit((string) $rawId) && !isset($removeIds[(int) $rawId])) {
                $orderedImageIds[] = (int) $rawId;
            }
        }
        if ($orderedImageIds !== []) {
            $this->productImageRepository->reorder($product, $orderedImageIds);
        }

        $primaryImageId = trim((string) $request->request->get('primary_image_id', ''));
        if ($primaryImageId !== '' && ctype_digit($primaryImageId) && !isset($removeIds[(int) $primaryImageId])) {
            $this->productImageRepository->setPrimary($product, (int) $primaryImageId);
        }

        $hasPrimary = false;
        foreach ($product->getImages() as $img) {
            if ($img instanceof ProductImage && $img->isPrimaryImage()) {
                $hasPrimary = true;
                break;
            }
        }

        if (!$hasPrimary) {
            $first = null;
            foreach ($product->getImages() as $img) {
                if ($img instanceof ProductImage && ($first === null || $img->getSortOrder() < $first->getSortOrder())) {
                    $first = $img;
                }
            }
            $first?->setPrimaryImage(true);
        }
    }

    private function normalizeSalesTaxCode(string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            return '';
        }

        // Legacy values (older app / pre-short-code data) → canonical short codes E/G/S
        return match (strtoupper($code)) {
            '0', 'EXEMPT', 'E' => 'E',
            '5', 'GST', 'G' => 'G',
            'L', 'PST', 'S' => 'S',
            default => $code,
        };
    }

    /** @return list<array{id: string, name: string}> */
    private function companyOptions(EntityManagerInterface $entityManager): array
    {
        $companies = $entityManager->getRepository(Company::class)->findBy([], ['name' => 'ASC']);
        $rows = [];
        foreach ($companies as $company) {
            if ($company instanceof Company && $company->getId() !== null) {
                $rows[] = ['id' => (string) $company->getId(), 'label' => $company->getName(), 'name' => $company->getName()];
            }
        }
        return $rows;
    }

    private function syncProductPricing(ProductCore $product, Request $request, EntityManagerInterface $entityManager): void
    {
        $priceList = $entityManager->find(PriceList::class, (int) $request->request->get('price_list_id'));
        if (!$priceList instanceof PriceList) {
            $priceList = $entityManager->getRepository(PriceList::class)->findOneBy([], ['id' => 'ASC']);
        }

        if (!$priceList instanceof PriceList) {
            foreach ($entityManager->getRepository(ProductPricing::class)->findBy(['product' => $product]) as $oldPricing) {
                $entityManager->remove($oldPricing);
            }

            return;
        }

        $pricing = $entityManager->getRepository(ProductPricing::class)->findOneBy([
            'product' => $product,
            'priceList' => $priceList,
        ]) ?? (new ProductPricing())->setProduct($product)->setPriceList($priceList);

        $pricing
            ->setPrice($this->nullableMoney($request->request->get('sale_price')) ?? '0.00')
            ->setCurrency($priceList->getCurrency());

        $entityManager->persist($pricing);

        foreach ($entityManager->getRepository(ProductPricing::class)->findBy(['product' => $product]) as $oldPricing) {
            if ($oldPricing instanceof ProductPricing && $oldPricing->getPriceList()->getId() !== $priceList->getId()) {
                $entityManager->remove($oldPricing);
            }
        }
    }

    /**
     * The three things the product form needs to know about #550, and nothing else.
     *
     * All three are false/null when no inventory-depth bundle is installed or it is switched
     * Inactive, which makes the whole block disappear from the form and leaves every quantity box
     * exactly as it was.
     *
     * @return array{dimensionalInventoryAvailable: bool, isDimensionalProduct: bool, dimensionalBreakdownUrl: ?string}
     */
    private function inventoryModeViewVars(ProductCore $product): array
    {
        $isDimensional = $this->inventoryMode->isDimensional($product);

        return [
            'dimensionalInventoryAvailable' => $this->inventoryMode->isDimensionalAvailable(),
            'isDimensionalProduct' => $isDimensional,
            'dimensionalBreakdownUrl' => $isDimensional ? $this->inventoryMode->breakdownUrl($product) : null,
        ] + $this->trackingPolicyViewVars($product);
    }

    /**
     * The tracking policy select on the product form (#573).
     *
     * Rendered on every product, not gated behind an inventory-depth bundle the way the mode toggle
     * is: what identity a unit carries is a statement about the product, and `tracking_policy` is a
     * core table. What reads the declaration is the depth layer, but the declaration itself outlives
     * it.
     *
     * @return array{trackingPolicies: list<TrackingPolicy>, trackingPolicyId: int, trackingPolicyDefaultName: string}
     */
    private function trackingPolicyViewVars(ProductCore $product): array
    {
        return [
            'trackingPolicies' => $this->trackingPolicies->allByName(),
            'trackingPolicyId' => $product->getTrackingPolicy()?->getId() ?? 0,
            'trackingPolicyDefaultName' => TrackingPolicy::DEFAULT_NAME,
        ] + $this->baseUnitViewVars($product);
    }

    /**
     * The Unit of Measure select on the product form (#601, phase 1 #643).
     *
     * `baseUnitBlockers` is what makes the refusal legible rather than mysterious: it lists the
     * `table.column` rows already counted in this product's unit, and the form shows them beside a
     * disabled select. An admin who cannot change a unit is told which figures are holding it.
     *
     * ## Two ids, because they answer different questions
     *
     * `baseUnitId` is what the product DECLARES — 0 when it declares nothing. `baseUnitSelectedId`
     * is what the select should open on, which for an undeclared product is whatever its legacy
     * free-text label resolves to: `Each` and `EA` name the same unit, and the form used to call
     * the first of them "not one of the units above" because the #601 backfill matched codes only.
     *
     * They are kept apart rather than collapsed because the template says something different in
     * each case — "not declared yet, saving declares it" against a pre-selection, "unmapped"
     * against a label like `12/Case` that resolves to nothing — and a single id could not tell
     * those apart.
     *
     * Resolving is not declaring: nothing is written here. The pre-selection rides out on the next
     * save the admin makes, through ProductBaseUnitService::assign(), which is a request a person
     * made rather than a write this render decided on.
     *
     * @return array{unitsOfMeasure: list<UnitOfMeasure>, baseUnitId: int, baseUnitSelectedId: int, baseUnitBlockers: array<string, int>}
     */
    private function baseUnitViewVars(ProductCore $product): array
    {
        $declared = $product->getBaseUnit();
        $selected = $declared ?? $this->baseUnits->resolveLegacyLabel($product->getUnit());

        // Candidates for the available-units list: the product's family, minus the base unit,
        // which is available by definition and is never a row in the join table.
        $familyUnits = [];
        foreach ($this->availableUnits->familyUnitsFor($product) as $unit) {
            if ((int) $unit->getId() !== (int) ($declared?->getId() ?? 0)) {
                $familyUnits[] = $unit;
            }
        }

        $availableUnitIds = [];
        foreach ($this->availableUnits->choicesFor($product) as $unit) {
            $availableUnitIds[] = (int) $unit->getId();
        }

        return [
            'unitsOfMeasure' => $this->unitsOfMeasure->allByCode(),
            'baseUnitId' => $declared?->getId() ?? 0,
            'baseUnitSelectedId' => $selected?->getId() ?? 0,
            // Only asked for a product that has one: an unset base unit is free to set, whatever
            // stock exists, and asking would be an entity-map-wide count on every product render.
            'baseUnitBlockers' => $declared === null ? [] : $this->baseUnits->blockers($product),
            // #659. The family is DERIVED from the base unit, never held beside it: one fact, one
            // place, which is the whole point of the issue that adds this block.
            'unitFamily' => $this->availableUnits->familyOf($product),
            'familyUnits' => $familyUnits,
            'availableUnitIds' => $availableUnitIds,
            'defaultUnitId' => $product->getDefaultUnit()?->getId() ?? 0,
        ];
    }

    /**
     * Applies the base unit select, if the form offered one.
     *
     * Routed through ProductBaseUnitService rather than written here, because changing a base unit
     * once figures are denominated in it restates every one of them silently — the service refuses
     * it, and the refusal surfaces as a flash while the rest of the form saves normally. A form that
     * never rendered the field submits nothing and this leaves the unit alone, the same rule
     * applyTrackingPolicyFromRequest() follows.
     */
    private function applyBaseUnitFromRequest(ProductCore $product, Request $request): void
    {
        if (!$request->request->has('unit_id')) {
            return;
        }

        $id = $request->request->getInt('unit_id', 0);
        $unit = $id > 0 ? $this->unitsOfMeasure->find($id) : null;

        try {
            $this->baseUnits->assign($product, $unit instanceof UnitOfMeasure ? $unit : null);
        } catch (UnitOfMeasureRefusal $refusal) {
            $this->addFlash('error', $refusal->getMessage());
        }
    }

    /**
     * Applies the available-units tick boxes and the default-unit select, if the form offered them
     * (#659).
     *
     * Gated on a hidden marker rather than on the tick boxes themselves, because an unticked
     * checkbox posts NOTHING: "the admin cleared the list" and "this form never had the block on it"
     * arrive identically otherwise, and reading the second as the first would blank a product's
     * units from the import screen or an old bookmark. The marker is emitted by the one template
     * that renders the block.
     *
     * Routed through ProductAvailableUnitService for the reason applyBaseUnitFromRequest() is: the
     * rules — same family as the base unit, default among the available units — live there, and the
     * refusal surfaces as a flash while the rest of the form saves normally.
     */
    private function applyAvailableUnitsFromRequest(ProductCore $product, Request $request): void
    {
        if (!$request->request->has('available_units_submitted')) {
            return;
        }

        $ids = [];
        foreach ((array) $request->request->all('available_unit_ids') as $raw) {
            $ids[] = is_scalar($raw) ? (int) $raw : 0;
        }

        $default = $request->request->getInt('default_unit_id', 0);

        try {
            $this->availableUnits->apply($product, $ids, $default > 0 ? $default : null);
        } catch (UnitOfMeasureRefusal $refusal) {
            $this->addFlash('error', $refusal->getMessage());
        }
    }

    /**
     * Applies the tracking policy select, if the form offered one.
     *
     * A form that never rendered the field submits nothing, and this leaves the product's policy
     * alone rather than reading the absence as "None" and silently untracking it — the same rule
     * applyInventoryModeFromRequest() follows, and for the same reason.
     */
    private function applyTrackingPolicyFromRequest(ProductCore $product, Request $request): void
    {
        if (!$request->request->has('tracking_policy_id')) {
            return;
        }

        $id = $request->request->getInt('tracking_policy_id', 0);
        $policy = $id > 0 ? $this->trackingPolicies->find($id) : null;

        $product->setTrackingPolicy($policy instanceof TrackingPolicy ? $policy : null);
    }

    /**
     * Applies the mode checkbox, if the form offered one.
     *
     * Routed through InventoryModeResolver rather than written here, because the two directions do
     * not cost the same: switching ON needs an opening balance movement, and only the depth layer
     * can write one. Switching OFF needs nothing — the total is already in `product_inventory`.
     * Neither direction changes the number.
     *
     * A form that never rendered the checkbox (no bundle, or bundle Inactive) submits nothing here
     * and this returns without touching the product, rather than reading a missing checkbox as
     * "unticked" and silently switching a dimensional product back to simple.
     */
    private function applyInventoryModeFromRequest(ProductCore $product, Request $request): void
    {
        if (!$this->inventoryMode->isDimensionalAvailable()) {
            return;
        }

        $wanted = $request->request->getBoolean('inventory_mode_dimensional')
            ? ProductCore::INVENTORY_MODE_DIMENSIONAL
            : ProductCore::INVENTORY_MODE_SIMPLE;

        if ($wanted === $product->getInventoryMode()) {
            return;
        }

        $this->inventoryMode->switchMode($product, $wanted, $this->getUser()?->getUserIdentifier());

        $this->addFlash('info', $wanted === ProductCore::INVENTORY_MODE_DIMENSIONAL
            ? sprintf('"%s" now tracks stock by bin, lot and serial. Its current quantity was carried in as an opening balance with the bin and lot unspecified.', $product->getName())
            : sprintf('"%s" is back on a typed quantity. Its total is unchanged and its existing breakdown has been kept.', $product->getName()));
    }

    private function syncProductInventory(ProductCore $product, Request $request, EntityManagerInterface $entityManager, AppSettings $appSettings): void
    {
        // #550: a dimensional product's quantity is maintained by its bin/lot/serial breakdown, in
        // the same transaction as the movement that changes it. The form renders a link instead of
        // the boxes for such a product, so there is nothing to read here — and writing anyway would
        // set a total the next movement would recompute away. Never true for a simple product, and
        // never true at all with no depth bundle installed.
        if ($this->inventoryMode->isDimensional($product)) {
            return;
        }

        $inventory = $request->request->all('inventory');
        // The form's boxes are labelled with REGION names, which is what an admin thinks in; the
        // stock behind each one lands in the warehouse serving that region (#546).
        //
        // This used to be warehouseForRegionNameOrCreate(), which created the missing warehouse
        // rather than drop the stock. It cannot any more (queue item 61): a per-region stock box
        // knows a region NAME and nothing whatever about where that region's building is, and a
        // warehouse invented here would carry an invented province that every purchase order
        // raised against it would then compute tax from. So the stock has nowhere to go — and that
        // is said out loud, naming the region and the screen that fixes it, rather than being
        // written to a row nobody can trust.
        foreach ($this->fulfillmentRegionNames($entityManager, $appSettings) as $regionName) {
            $warehouse = $this->warehouses->warehouseForRegionName($regionName);
            if (!$warehouse instanceof Warehouse) {
                // Only when something was actually going to be stored. A zero typed into a region
                // with no warehouse loses nothing, and a flash on every product save of every
                // unanswered region would train an admin to ignore the one that matters.
                if (max(0, (int) ($inventory[$regionName] ?? 0)) > 0) {
                    $this->addFlash('error', sprintf(
                        'Stock for the "%s" region was not saved: no warehouse serves it yet, and stock is counted in a '
                            . 'building. Add one under Config > Warehouses — it needs a province, which is what purchase '
                            . 'orders and vendor bills raised against it compute tax from — then re-enter the quantity.',
                        $regionName,
                    ));
                }

                continue;
            }

            // The one gate every "set inventory's starting count" caller goes through
            // (CoreInventoryTotalCountService), same as the inline grid box's Starting field — no
            // buckets cleared, because this box is not a reconciliation event. Before this, this
            // method wrote product_inventory.quantity by hand and left no inventory_detail row
            // behind it, so a product whose stock only ever arrived through this box read as zero
            // the moment a transfer or adjustment checked for it — the exact bug the service exists
            // to close, reopened on the one call site the original unification missed.
            $this->totalCounts->setCount($product, $warehouse, max(0, (int) ($inventory[$regionName] ?? 0)));
        }
    }

    /** @return list<string> */
    private function fulfillmentRegionNames(EntityManagerInterface $entityManager, AppSettings $appSettings): array
    {
        $names = [];
        $rows = $entityManager->getRepository(FulfillmentRegion::class)->findBy(['status' => 'Active'], ['name' => 'ASC']);
        foreach ($rows as $row) {
            if ($row instanceof FulfillmentRegion) {
                $name = trim($row->getName());
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        if ($names !== []) {
            return $this->prioritizeMainFulfillmentRegion(array_values(array_unique($names)));
        }

        $raw = (string) ($appSettings->get('fulfillment_regions', '') ?? '');
        $raw = str_replace([",", ";"], "\n", $raw);
        $parts = preg_split('/\\R+/', $raw) ?: [];

        $fallback = [];
        foreach ($parts as $p) {
            $name = trim((string) $p);
            if ($name !== '') {
                $fallback[] = $name;
            }
        }

        $fallback = array_values(array_unique($fallback));
        return $this->prioritizeMainFulfillmentRegion($fallback !== [] ? $fallback : ['Main', 'Overflow', 'Supplier Direct']);
    }

    /** @param list<string> $names
     *  @return list<string>
     */
    private function prioritizeMainFulfillmentRegion(array $names): array
    {
        usort($names, static function (string $left, string $right): int {
            $leftIsMain = strcasecmp(trim($left), 'Main') === 0;
            $rightIsMain = strcasecmp(trim($right), 'Main') === 0;

            if ($leftIsMain && !$rightIsMain) {
                return -1;
            }

            if (!$leftIsMain && $rightIsMain) {
                return 1;
            }

            return strcasecmp($left, $right);
        });

        return array_values($names);
    }

    private function firstPricing(ProductCore $product, EntityManagerInterface $entityManager): ?ProductPricing
    {
        return $entityManager->getRepository(ProductPricing::class)->findOneBy(['product' => $product], ['id' => 'ASC']);
    }

    /**
     * Stock keyed by REGION name, because that is what the form's boxes are labelled with — the
     * rows themselves are keyed by warehouse, so each one is read back through the region it
     * serves (#546). An unpaired warehouse falls back to its own name rather than vanishing.
     *
     * @return array<string, int>
     */
    private function inventoryMap(ProductCore $product, EntityManagerInterface $entityManager): array
    {
        $regionNamesByWarehouseId = $this->warehouses->regionNamesByWarehouseId();

        $map = [];
        $rows = $entityManager->getRepository(ProductInventory::class)->findBy(['product' => $product]);
        foreach ($rows as $row) {
            if (!$row instanceof ProductInventory) {
                continue;
            }

            $warehouse = $row->getWarehouse();
            if (!$warehouse instanceof Warehouse) {
                continue;
            }

            $map[$regionNamesByWarehouseId[$warehouse->getId()] ?? $warehouse->getName()] = $row->getQuantity();
        }

        return $map;
    }

    /**
     * The same bucket columns `/admin/inventory` shows, one row per Fulfillment Region, for this
     * one product — so an admin editing Starting here sees exactly what the grid would tell them
     * about everything else touching this row instead of a single number floating with no context.
     *
     * Read-only by construction: this method returns figures, never anything with a `name`
     * attribute a form could submit, because Starting is the only thing this screen is for
     * declaring — every other bucket is written by a real movement or document, elsewhere, and
     * showing it here as anything but a fact would invite exactly the "which screen actually owns
     * this number" confusion #546/#36 already exist to end for the grid itself.
     *
     * A region with no `ProductInventory` row yet — every region, on Create — reads as
     * InventoryGridColumns::emptyCells(): every bucket zero, Available zero, same as a freshly
     * seeded row would.
     *
     * @return list<array{region: string, cells: array<string, array{value: int|bool|null, sign: int, counts: bool}>, available: string}>
     */
    private function bucketRowsByFulfillmentRegion(ProductCore $product, EntityManagerInterface $entityManager): array
    {
        $regions = $entityManager->getRepository(FulfillmentRegion::class)->findBy(['status' => 'Active'], ['name' => 'ASC']);
        $emptyCells = $this->gridColumns->emptyCells();

        $rows = [];
        foreach ($regions as $region) {
            if (!$region instanceof FulfillmentRegion) {
                continue;
            }
            $name = trim($region->getName());
            if ($name === '') {
                continue;
            }

            $warehouse = $this->warehouses->warehouseForRegion($region);
            $inventoryRow = ($warehouse instanceof Warehouse && $product->getId() !== null)
                ? $entityManager->getRepository(ProductInventory::class)->findOneBy(['product' => $product, 'warehouse' => $warehouse])
                : null;

            $rows[] = [
                'region' => $name,
                'cells' => $inventoryRow instanceof ProductInventory ? $this->gridColumns->cellsFor($inventoryRow) : $emptyCells,
                'available' => $inventoryRow instanceof ProductInventory ? $inventoryRow->getAvailableQuantity() : '0.0000',
            ];
        }

        return $rows;
    }

    /**
     * The column headings for {@see self::bucketRowsByFulfillmentRegion()} — the same default set
     * `/admin/inventory` opens on, in the same order, so a number here and the same number on the
     * grid are never one column apart.
     *
     * @return array<string, array{label: string, kind: string}>
     */
    private function defaultBucketColumns(): array
    {
        $registry = $this->gridColumns->all();

        $columns = [];
        foreach ($this->gridColumns->defaultKeys() as $key) {
            $columns[$key] = ['label' => $registry[$key]['label'], 'kind' => $registry[$key]['kind']];
        }

        return $columns;
    }

    /** @param array<string, int> $inventory */
    private function inventoryByFulfillmentRegionRow(array $inventory): array
    {
        $out = [];
        foreach ($inventory as $name => $qty) {
            $out[(string) $name] = (string) ($qty ?? 0);
        }

        return $out;
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function nullableMoney(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return number_format((float) str_replace(',', '', $value), 2, '.', '');
    }

    private function money(?string $value): string
    {
        return $value !== null && $value !== '' ? '$'.number_format((float) $value, 2) : '-';
    }

    private function uploadDir(): string
    {
        return $this->getParameter('kernel.project_dir') . '/public/uploads/products';
    }

    private function removeImageFile(string $filename): void
    {
        $path = $this->uploadDir() . '/' . $filename;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
