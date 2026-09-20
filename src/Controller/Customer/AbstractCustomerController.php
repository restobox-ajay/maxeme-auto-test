<?php

namespace App\Controller\Customer;

use App\Contract\Fee\FeeLine;
use App\Entity\Cart;
use App\Entity\CartItem;
use App\Entity\CustomerUser;
use App\Entity\Company;
use App\Entity\CompanyAddress;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\CustomFieldDefinition;
use App\Entity\CustomFieldValueProduct;
use App\Entity\FulfillmentRegion;
use App\Entity\PriceList;
use App\Entity\ProductCategory;
use App\Entity\ProductCore;
use App\Entity\ProductImage;
use App\Entity\ProductInventory;
use App\Entity\ProductPricing;
use App\Enum\ProductStatus;
use App\Event\CartSyncedEvent;
use App\Service\AppSettings;
use App\Service\CartService;
use App\Service\CompanyFulfillmentRegionService;
use App\Service\WarehouseFulfillmentRegionService;
use App\Service\Pricing\CustomerPricingResolver;
use App\Service\Pricing\CustomerPricingScope;
use App\Service\Pricing\ProductVisibilityGuard;
use App\Service\SuggestedPriceCalculator;
use App\Service\TireNumberSearchMatcher;
use App\Service\TireSizeExtractor;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

abstract class AbstractCustomerController extends AbstractController
{
    // Region the customer is browsing/pricing against — written by resolveBrowsingRegion()
    // (catalog picker) and read back by cart/checkout via currentRegionForPricing().
    protected const SESSION_SELECTED_REGION = 'customer_selected_region';

    // Shipping method chosen at checkout — written by CheckoutController and read back by
    // CartController so /cart can show the real chosen option instead of a flat estimate.
    protected const SESSION_SHIPPING_METHOD = 'customer_checkout_shipping_method';

    public function __construct(
        protected readonly CompanyFulfillmentRegionService $companyFulfillmentRegionService,
        protected readonly CustomerPricingResolver $pricingResolver,
        protected readonly SuggestedPriceCalculator $suggestedPriceCalculator,
        protected readonly WarehouseFulfillmentRegionService $warehouses,
        protected readonly ProductVisibilityGuard $visibilityGuard,
    ) {}

    /**
     * Binds the visitor's pricing company and a region into one scope. Every pricing method below
     * goes through this, so a caller that prices more than one product should build the scope once
     * and keep it rather than calling those methods in a loop — that is what makes the price list
     * resolve per document instead of per line.
     */
    protected function pricingScope(?string $regionName): CustomerPricingScope
    {
        return $this->pricingResolver->for($this->currentCompanyForPricing(), $regionName);
    }

    protected function currentCompanyId(): ?int
    {
        $user = $this->getUser();
        if (!$user instanceof CustomerUser) {
            return null;
        }

        return $user->getCompany()?->getId();
    }

    protected function currentCompany(): ?Company
    {
        $user = $this->getUser();
        if (!$user instanceof CustomerUser) {
            return null;
        }

        return $user->getCompany();
    }

    /**
     * Redirects back to wherever a no-JS form was submitted from (e.g. a
     * product listing page), falling back to $fallbackRoute. Only accepts
     * a same-origin relative path from the submitted redirect_to field to
     * avoid an open redirect.
     */
    protected function redirectBackOrTo(\Symfony\Component\HttpFoundation\Request $request, string $fallbackRoute): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $target = trim((string) $request->request->get('redirect_to', ''));
        if ($target !== '' && str_starts_with($target, '/') && !str_starts_with($target, '//')) {
            return $this->redirect($target);
        }

        return $this->redirectToRoute($fallbackRoute);
    }

    /**
     * Resolves the session cart (sku => qty) into full rows with fresh
     * product/pricing data. Shared by CartController and CheckoutController
     * so both always see identical cart contents.
     *
     * Rows whose price can't be resolved (explicit "No Price" pricing rule, or simply no
     * pricing configured at all) are kept with price/subtotal null and priceResolved false,
     * rather than silently dropped — checkout routes carts containing them into an Estimate
     * instead of a normal order. See plan4-quotes-vs-orders.md section 2.1.
     *
     * @return list<array{sku:string,name:string,unit:?string,price:?float,qty:int,subtotal:?float,product:ProductCore,priceResolved:bool}>
     */
    protected function resolveCartRows(EntityManagerInterface $entityManager, Company $company, \App\Service\CartService $cartService, ?string $region = null): array
    {
        // One scope for the whole cart: the price list depends on the buyer and the region, not on
        // the line, so resolving it per line was pure repetition.
        $scope = $this->pricingScope($region);
        // Resolved once for the whole cart, not per line: every row asks the same region the same
        // question, and the lookup is a query.
        $regionEntity = $this->resolveFulfillmentRegionEntity($entityManager, $region);

        $rows = [];
        foreach ($cartService->getItems() as $sku => $qty) {
            $product = $scope->findPurchasableProduct($company, (string) $sku);
            if (!$product instanceof ProductCore) {
                continue;
            }

            $qty = (int) $qty;
            $customerPrice = $scope->priceFor($product);
            $priceResolved = $customerPrice->isPriced();
            $price = $customerPrice->toFloat();

            // How this line would split if it were checked out right now (#548). Read-only and
            // computed by the same resolver checkout enforces with, so the cart cannot promise a
            // split the order would not honour. Zero backordered for every SKU nobody has opted
            // in, which is what keeps the cart page unchanged for the whole catalogue by default.
            //
            // Reported HERE rather than through CartService::capOrRemove()'s return value on
            // purpose: that channel means "the cart changed under you", and
            // CheckoutController::buildAndPersistOrder() aborts the whole order on any message
            // from it. A backordered line has not changed and must not abort anything.
            $split = $cartService->backorderSplitFor($product, $regionEntity, $qty);

            $rows[] = [
                'sku' => $product->getSku(),
                'name' => $product->getName(),
                'unit' => $product->getUnit(),
                'price' => $price,
                'qty' => $qty,
                'subtotal' => $priceResolved ? $price * $qty : null,
                'product' => $product,
                'priceResolved' => $priceResolved,
                'shipsNow' => $split->fulfilled,
                'backordered' => $split->backordered,
            ];
        }

        return $rows;
    }

    /**
     * True if the customer's resolved pricing for this product is explicitly "No Price" —
     * used to auto-remove cart/checkout lines, independent of when the flag was set.
     */
    protected function isNoPriceForCustomer(EntityManagerInterface $entityManager, ProductCore $product, ?string $regionName = null): bool
    {
        return $this->pricingScope($regionName)->isNoPrice($product);
    }

    protected function findCheckoutProduct(EntityManagerInterface $entityManager, Company $company, string $sku, ?string $regionName = null): ?ProductCore
    {
        return $this->pricingScope($regionName)->findPurchasableProduct($company, $sku);
    }

    protected function productCountFromDatabase(EntityManagerInterface $entityManager, ?ProductCategory $category = null, ?string $region = null): int
    {
        $repo = $entityManager->getRepository(ProductCore::class);
        $qb = $repo->createQueryBuilder('p')
            ->select('COUNT(DISTINCT p.id)')
            ->where('p.visible = true')
            ->andWhere('p.deleted = false')
            ->andWhere('p.status = :status')
            ->setParameter('status', ProductStatus::Active->value);

        if ($category instanceof ProductCategory) {
            $categoryIds = $this->categoryAndDescendantIds($entityManager, $category);
            if ($categoryIds === []) {
                return 0;
            }

            $qb->andWhere('IDENTITY(p.category) IN (:categoryIds)')
                ->setParameter('categoryIds', $categoryIds);
        }

        $this->applyCustomerProductVisibilityRule($qb);
        $this->excludeHiddenProducts($qb, $region);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    protected function productCountUncategorizedFromDatabase(EntityManagerInterface $entityManager, ?string $region = null): int
    {
        $repo = $entityManager->getRepository(ProductCore::class);
        $qb = $repo->createQueryBuilder('p')
            ->select('COUNT(DISTINCT p.id)')
            ->where('p.category IS NULL')
            ->andWhere('p.visible = true')
            ->andWhere('p.deleted = false')
            ->andWhere('p.status = :status')
            ->setParameter('status', ProductStatus::Active->value);

        $this->applyCustomerProductVisibilityRule($qb);
        $this->excludeHiddenProducts($qb, $region);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** @return list<array<string, string>> */
    protected function categoriesFromDatabase(EntityManagerInterface $entityManager, ?string $region = null): array
    {
        $graph = $this->visibleCategoryGraph($entityManager);

        $buildTree = function (int $parentId) use (&$buildTree, $graph, $entityManager, $region): array {
            $rows = [];
            $childIds = $graph['childrenMap'][$parentId] ?? [];
            usort($childIds, static function (int $leftId, int $rightId) use ($graph): int {
                $left = $graph['categoriesById'][$leftId]?->getName() ?? '';
                $right = $graph['categoriesById'][$rightId]?->getName() ?? '';
                return strcasecmp($left, $right);
            });

            foreach ($childIds as $childId) {
                $category = $graph['categoriesById'][$childId] ?? null;
                if (!$category instanceof ProductCategory) {
                    continue;
                }

                $rows[] = [
                    'id' => (string) $category->getId(),
                    'name' => $category->getName(),
                    'count' => (string) $this->productCountFromDatabase($entityManager, $category, $region),
                    'children' => $buildTree($childId),
                ];
            }

            return $rows;
        };

        return $buildTree(0);
    }

    /** @param array<string, list<string>> $customFieldFilters see catalogProductQueryBuilder() */
    protected function productSearchCountFromDatabase(EntityManagerInterface $entityManager, ?int $categoryId = null, string $search = '', ?string $region = null, array $customFieldFilters = []): int
    {
        $qb = $this->catalogProductQueryBuilder($entityManager, $categoryId, $search, $region, null, $customFieldFilters)
            ->select('COUNT(DISTINCT p.id)');

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Catalog sort fields that map straight to a ProductCore column, so they can be ORDER BY'd in
     * the database (and paginated there). Keyed by the query-param field name (ProductSearch[sort]),
     * valued by the DQL expression. The whitelist itself lives in the controller
     * (CatalogController::resolveCatalogSort()); this is only the field→column mapping, so nothing
     * from the request is ever interpolated into the DQL.
     */
    private const CATALOG_DB_SORT_COLUMNS = [
        'description' => 'p.name',
        'sku' => 'p.sku',
        'unit' => 'p.unit',
        'remarks' => 'p.remarks',
    ];

    /**
     * Catalog sort fields whose displayed value is computed per-row in PHP (customerProductRow())
     * rather than being a plain column — tire "size" (extracted from the name), resolved customer
     * "price", and summed available "stock". These can't be expressed as a portable DB ORDER BY,
     * so when one is requested we load every matching row, sort it in PHP by the actual displayed
     * value, then paginate the sorted result (mirroring how productIdsMatchingTireNumber() already
     * drops to PHP for logic the query can't portably express).
     */
    private const CATALOG_PHP_SORT_FIELDS = ['size', 'price', 'stock'];

    /**
     * @param array<string, list<string>> $customFieldFilters see catalogProductQueryBuilder()
     * @param list<string> $customFieldSlugs slugs to fold into each row's 'customFields' map — see customerProductRow()
     * @param ?string $sortField validated catalog sort field (see CatalogController::resolveCatalogSort()); null = default order
     * @param string $sortDir 'asc' or 'desc' (already validated by the caller)
     * @return list<array<string, mixed>>
     */
    protected function productsFromDatabase(
        EntityManagerInterface $entityManager,
        ?int $categoryId = null,
        ?string $region = null,
        string $search = '',
        ?int $page = null,
        ?int $perPage = null,
        ?bool $featured = null,
        array $customFieldFilters = [],
        array $customFieldSlugs = [],
        ?string $sortField = null,
        string $sortDir = 'asc'
    ): array
    {
        $sortDir = strtolower($sortDir) === 'desc' ? 'desc' : 'asc';
        $phpSort = $sortField !== null && in_array($sortField, self::CATALOG_PHP_SORT_FIELDS, true);

        $qb = $this->catalogProductQueryBuilder($entityManager, $categoryId, $search, $region, $featured, $customFieldFilters)
            ->addSelect('category');

        if ($sortField !== null && isset(self::CATALOG_DB_SORT_COLUMNS[$sortField])) {
            $qb->orderBy(self::CATALOG_DB_SORT_COLUMNS[$sortField], strtoupper($sortDir))
                ->addOrderBy('p.name', 'ASC');
        } elseif (!$phpSort) {
            $qb->orderBy('p.name', 'ASC');
        }

        $paginate = $page !== null && $perPage !== null && $perPage > 0;

        // For DB-sortable (or default) ordering we can page in the query; for PHP-computed sorts we
        // must load the whole matching set, sort it, and only then slice out the page.
        if ($paginate && !$phpSort) {
            $page = max(1, $page);
            $qb->setFirstResult(($page - 1) * $perPage)
                ->setMaxResults($perPage);
        }

        $rows = [];
        $products = $qb->getQuery()->getResult();
        foreach ($products as $product) {
            if (!$product instanceof ProductCore) {
                continue;
            }
            $rows[] = $this->customerProductRow($entityManager, $product, $region, $customFieldSlugs);
        }

        if ($phpSort) {
            $rows = $this->sortCatalogRowsByComputedField($rows, (string) $sortField, $sortDir);
            if ($paginate) {
                $page = max(1, $page);
                $rows = array_slice($rows, ($page - 1) * $perPage, $perPage);
            }
        }

        return $rows;
    }

    /**
     * Stable sort of already-built catalog rows by a PHP-computed display field (see
     * CATALOG_PHP_SORT_FIELDS). Products with no resolvable price ("TBD") always sort last,
     * regardless of direction, so an empty price never masquerades as the cheapest/priciest.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function sortCatalogRowsByComputedField(array $rows, string $field, string $sortDir): array
    {
        $sign = $sortDir === 'desc' ? -1 : 1;

        usort($rows, function (array $left, array $right) use ($field, $sign): int {
            if ($field === 'price') {
                $leftPrice = $this->numericCatalogPrice($left['price'] ?? null);
                $rightPrice = $this->numericCatalogPrice($right['price'] ?? null);

                if ($leftPrice === null && $rightPrice === null) {
                    return 0;
                }
                if ($leftPrice === null) {
                    return 1;
                }
                if ($rightPrice === null) {
                    return -1;
                }

                return $sign * ($leftPrice <=> $rightPrice);
            }

            if ($field === 'stock') {
                return $sign * ((int) ($left['availableQuantity'] ?? 0) <=> (int) ($right['availableQuantity'] ?? 0));
            }

            // size — a mostly-numeric string (see TireSizeExtractor / weight fallback).
            return $sign * strnatcasecmp((string) ($left['size'] ?? ''), (string) ($right['size'] ?? ''));
        });

        return $rows;
    }

    /**
     * Parses a displayed catalog price ("$1,234.56") back to a float for sorting, returning null for
     * a blank or "TBD" price so callers can sort those rows to the end.
     */
    private function numericCatalogPrice(mixed $price): ?float
    {
        $price = trim((string) $price);
        if ($price === '' || strcasecmp($price, 'TBD') === 0) {
            return null;
        }

        $clean = preg_replace('/[^0-9.\-]/', '', $price) ?? '';

        return is_numeric($clean) ? (float) $clean : null;
    }

    /**
     * @param list<string> $customFieldSlugs slugs to fold into the row's 'customFields' map — generic/
     *     slug-driven so core stays free of any bundle-specific (e.g. rim_*) knowledge, same split
     *     customFieldFacetCounts() below already uses. 'weight' is handled specially since it's a
     *     plain ProductCore column, not a CustomFieldValue.
     * @return array<string, mixed>
     */
    protected function customerProductRow(EntityManagerInterface $entityManager, ProductCore $product, ?string $region = null, array $customFieldSlugs = []): array
    {
        $price = $this->resolveCustomerPriceAmount($entityManager, $product, $region);
        $inventoryRows = $entityManager->getRepository(ProductInventory::class)->findBy(['product' => $product]);
        $quantity = $this->availableQuantityForProduct($inventoryRows, $region);

        $tireSizeExtractor = new TireSizeExtractor();
        $tireSize = $tireSizeExtractor->extract($product->getName())
            ?? $tireSizeExtractor->extract((string) $product->getShortDescription())
            ?? $tireSizeExtractor->extract((string) $product->getLongDescription());

        return [
            'id' => (int) ($product->getId() ?? 0),
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'category' => $product->getCategory()?->getName() ?? 'No category',
            'inventory' => $quantity > 0 ? 'In stock' : 'Out of stock',
            'availableQuantity' => $quantity,
            'price' => $price !== null ? '$'.number_format((float) $price, 2) : 'TBD',
            // The same two numbers before they were formatted for display. The row used to compute
            // the customer's price and then throw the number away, which is why the API ended up
            // publishing "$1,299.00" — a string that casts to 0.0. Twig ignores these; the API
            // presenter reads them. `retail` is the Suggested-Price-adjusted figure the detail page
            // already shows via suggested_price_html(), deliberately a different number from
            // `company`: the customer's price is derived from the un-adjusted base, never from this.
            'priceAmount' => $price !== null ? (float) $price : null,
            'retailPriceAmount' => ($retail = $this->suggestedPriceCalculator->getEffectivePrice($product)) !== null
                ? (float) $retail
                : null,
            'unit' => $product->getUnit() ?? '',
            'size' => $tireSize ?? $product->getWeight() ?? '',
            'remarks' => $product->getRemarks() ?? '',
            'image' => $product->getPrimaryImageFilename() ?? '',
            'images' => $this->customerProductImageRows($product),
            'description' => trim((string) ($product->getShortDescription() ?? '') . ' ' . (string) ($product->getLongDescription() ?? '')),
            'shortDescription' => trim((string) ($product->getShortDescription() ?? '')),
            'longDescription' => trim((string) ($product->getLongDescription() ?? '')),
            'customFields' => $this->customFieldValuesForProduct($entityManager, $product, $customFieldSlugs),
        ];
    }

    /**
     * @param list<string> $slugs
     * @return array<string, string> slug => value, missing/blank slugs omitted
     */
    private function customFieldValuesForProduct(EntityManagerInterface $entityManager, ProductCore $product, array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        $values = [];

        $cfSlugs = [];
        foreach ($slugs as $slug) {
            if ($slug === 'weight') {
                $weight = (string) ($product->getWeight() ?? '');
                if ($weight !== '') {
                    $values['weight'] = $weight;
                }
                continue;
            }
            $cfSlugs[] = $slug;
        }

        if ($cfSlugs === []) {
            return $values;
        }

        $rows = $entityManager->createQueryBuilder()
            ->select('cfd.slug AS slug', 'cfv.value AS value')
            ->from(CustomFieldValueProduct::class, 'cfv')
            ->innerJoin('cfv.definition', 'cfd')
            ->where('IDENTITY(cfv.product) = :objectId')
            ->andWhere('cfd.objectType = :objectType')
            ->andWhere('cfd.slug IN (:slugs)')
            ->setParameter('objectId', $product->getId())
            ->setParameter('objectType', CustomFieldDefinition::OBJECT_TYPE_PRODUCT)
            ->setParameter('slugs', $cfSlugs)
            ->getQuery()
            ->getArrayResult();

        foreach ($rows as $row) {
            $value = (string) ($row['value'] ?? '');
            if ($value !== '') {
                $values[(string) $row['slug']] = $value;
            }
        }

        return $values;
    }

    /** @return list<array{filename: string, primary: bool}> */
    private function customerProductImageRows(ProductCore $product): array
    {
        $images = array_values(array_filter(
            $product->getImages()->toArray(),
            static fn ($image): bool => $image instanceof ProductImage
        ));

        usort($images, static fn (ProductImage $a, ProductImage $b): int => $a->getSortOrder() <=> $b->getSortOrder());

        return array_map(
            static fn (ProductImage $image): array => [
                'filename' => $image->getFilename(),
                'primary' => $image->isPrimaryImage(),
            ],
            $images
        );
    }

    protected function customerVisibleProductById(EntityManagerInterface $entityManager, int $id, ?string $region = null): ?ProductCore
    {
        $qb = $entityManager->getRepository(ProductCore::class)
            ->createQueryBuilder('p')
            ->leftJoin('p.category', 'category')
            ->addSelect('category')
            ->leftJoin('p.images', 'images')
            ->addSelect('images')
            ->andWhere('p.id = :id')
            ->andWhere('p.visible = true')
            ->andWhere('p.deleted = false')
            ->andWhere('p.status = :status')
            ->setParameter('id', $id)
            ->setParameter('status', ProductStatus::Active->value);

        $this->applyCustomerProductVisibilityRule($qb);
        $this->excludeHiddenProducts($qb, $region);

        $product = $qb->getQuery()->getOneOrNullResult();

        return $product instanceof ProductCore ? $product : null;
    }

    /**
     * @param array<string, list<string>> $customFieldFilters slug => selected values (OR within a
     *     slug, AND across slugs) — e.g. {"rim_pcd": ["5x112", "5x114.3"], "rim_dimension": ["18"]}.
     *     No hardcoded knowledge of what any slug means; a category-page bundle supplies whatever
     *     slugs its own filter form submitted. See CATEGORY_PAGE_FILTER_PLAN.md §3c.
     */
    private function catalogProductQueryBuilder(EntityManagerInterface $entityManager, ?int $categoryId = null, string $search = '', ?string $region = null, ?bool $featured = null, array $customFieldFilters = [])
    {
        $repo = $entityManager->getRepository(ProductCore::class);
        $qb = $repo->createQueryBuilder('p')
            ->where('p.visible = true')
            ->andWhere('p.deleted = false')
            ->andWhere('p.status = :status')
            ->setParameter('status', ProductStatus::Active->value)
            ->leftJoin('p.category', 'category');

        if ($featured !== null) {
            $qb->andWhere('p.featured = :featuredFlag')->setParameter('featuredFlag', $featured);
        }

        if ($categoryId === -1) {
            $qb->andWhere('p.category IS NULL');
        } else {
            $category = $categoryId ? $entityManager->find(ProductCategory::class, $categoryId) : null;
            if ($category instanceof ProductCategory) {
                $categoryIds = $this->categoryAndDescendantIds($entityManager, $category);
                if ($categoryIds === []) {
                    $qb->andWhere('1 = 0');
                    return $qb;
                }

                $qb->andWhere('IDENTITY(p.category) IN (:categoryIds)')
                    ->setParameter('categoryIds', $categoryIds);
            }
        }

        $this->applyCustomerProductVisibilityRule($qb);
        $this->excludeHiddenProducts($qb, $region);

        $search = trim($search);
        if ($search !== '') {
            // Mirrors the reference app's ProductSearch::search(), which LIKEs the raw typed value
            // against its size/description/name columns regardless of whether it's numeric: the raw
            // text is ALWAYS searched (this branch runs unconditionally), and the digits-only
            // tire-size matching below is only ever OR'd on top of it — never a replacement for it.
            // We have no size column (the vendor CSV carries no distinct size value; its DESC cell
            // becomes the product name), so name + the descriptions are the equivalent text columns.
            $normalizedSearch = mb_strtolower($search);

            if (ctype_digit($search)) {
                $searchCondition = 'LOWER(COALESCE(p.sku, \'\')) = :searchExact
                    OR LOWER(COALESCE(p.name, \'\')) = :searchExact
                    OR LOWER(COALESCE(p.shortDescription, \'\')) = :searchExact
                    OR LOWER(COALESCE(p.longDescription, \'\')) = :searchExact
                    OR EXISTS (
                        SELECT 1 FROM ' . CustomFieldValueProduct::class . ' searchCfv
                        JOIN searchCfv.definition searchCfDef
                        WHERE IDENTITY(searchCfv.product) = p.id
                        AND searchCfDef.objectType = :searchCfObjectType
                        AND searchCfDef.searchable = true
                        AND LOWER(COALESCE(searchCfv.value, \'\')) = :searchExact
                    )';
                $qb->setParameter('searchExact', $normalizedSearch)
                    ->setParameter('searchCfObjectType', CustomFieldDefinition::OBJECT_TYPE_PRODUCT);
            } else {
                $searchCondition = 'LOWER(COALESCE(p.sku, \'\')) LIKE :search
                    OR LOWER(COALESCE(p.name, \'\')) LIKE :search
                    OR LOWER(COALESCE(p.shortDescription, \'\')) LIKE :search
                    OR LOWER(COALESCE(p.longDescription, \'\')) LIKE :search
                    OR EXISTS (
                        SELECT 1 FROM ' . CustomFieldValueProduct::class . ' searchCfv
                        JOIN searchCfv.definition searchCfDef
                        WHERE IDENTITY(searchCfv.product) = p.id
                        AND searchCfDef.objectType = :searchCfObjectType
                        AND searchCfDef.searchable = true
                        AND LOWER(COALESCE(searchCfv.value, \'\')) LIKE :search
                    )';
                $qb->setParameter('search', '%' . $normalizedSearch . '%')
                    ->setParameter('searchCfObjectType', CustomFieldDefinition::OBJECT_TYPE_PRODUCT);
            }

            // Tire-size search: "2356516" (or "235 65 16", "23565R16", ...) should also match a
            // product named e.g. "LT235-65R16-121-119R" by comparing digits-only substrings — see
            // TireNumberSearchMatcher.
            $tireNumberMatcher = new TireNumberSearchMatcher();
            if ($tireNumberMatcher->isEligible($search)) {
                $tireMatchIds = $this->productIdsMatchingTireNumber($entityManager, $tireNumberMatcher, $search);
                if ($tireMatchIds !== []) {
                    $searchCondition .= ' OR p.id IN (:tireNumberSearchIds)';
                    $qb->setParameter('tireNumberSearchIds', $tireMatchIds);
                }
            }

            $qb->andWhere($searchCondition);
        }

        $i = 0;
        foreach ($customFieldFilters as $slug => $values) {
            $values = array_values(array_filter($values, static fn ($v): bool => trim((string) $v) !== ''));
            if ($values === []) {
                continue;
            }

            $i++;
            $qb->andWhere(
                'EXISTS (
                    SELECT 1 FROM ' . CustomFieldValueProduct::class . " cfvFilter{$i}
                    JOIN cfvFilter{$i}.definition cfdFilter{$i}
                    WHERE IDENTITY(cfvFilter{$i}.product) = p.id
                    AND cfdFilter{$i}.slug = :cfFilterSlug{$i}
                    AND cfvFilter{$i}.value IN (:cfFilterValues{$i})
                )"
            )
                ->setParameter("cfFilterSlug{$i}", (string) $slug)
                ->setParameter("cfFilterValues{$i}", $values);
        }

        return $qb;
    }

    /**
     * Digits-only substring matching (see TireNumberSearchMatcher) can't be expressed as a
     * portable SQLite LIKE/REGEXP, so it's done in PHP over id+name pairs instead of in the
     * main query builder.
     *
     * @return list<int>
     */
    private function productIdsMatchingTireNumber(EntityManagerInterface $entityManager, TireNumberSearchMatcher $matcher, string $search): array
    {
        $rows = $entityManager->createQuery('SELECT p.id AS id, p.name AS name FROM ' . ProductCore::class . ' p')
            ->getArrayResult();

        $ids = [];
        foreach ($rows as $row) {
            if ($matcher->matches($search, (string) $row['name'])) {
                $ids[] = (int) $row['id'];
            }
        }

        return $ids;
    }

    /**
     * Doctrine equivalent of the reference app's Product::getGroupListData() — exact-string
     * GROUP BY on one custom field's value, scoped to the same visible/active/category query the
     * catalog itself uses, so counts always match what a customer would actually see. Powers
     * checkbox-with-count facets like "17 (33)". See CATEGORY_PAGE_FILTER_PLAN.md §3c.
     *
     * @return array<string, int> value => count, insertion order not guaranteed
     */
    protected function customFieldFacetCounts(EntityManagerInterface $entityManager, string $slug, ?int $categoryId = null, ?string $region = null): array
    {
        $qb = $this->catalogProductQueryBuilder($entityManager, $categoryId, '', $region)
            ->resetDQLPart('select')
            ->innerJoin(CustomFieldValueProduct::class, 'facetCfv', 'WITH', 'IDENTITY(facetCfv.product) = p.id')
            ->innerJoin('facetCfv.definition', 'facetCfd')
            ->andWhere('facetCfd.slug = :facetSlug')
            ->setParameter('facetSlug', $slug)
            ->select('facetCfv.value AS value, COUNT(DISTINCT p.id) AS productCount')
            ->groupBy('facetCfv.value');

        $rows = $qb->getQuery()->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $value = (string) $row['value'];
            if ($value === '') {
                continue;
            }
            $counts[$value] = (int) $row['productCount'];
        }

        return $counts;
    }

    /** Private-companies scoping, delegated to ProductVisibilityGuard so every caller shares one rule. */
    private function applyCustomerProductVisibilityRule(QueryBuilder $qb): void
    {
        $this->visibilityGuard->applyTo($qb, 'p', null, $this->currentCompanyId());
    }

    /** Price-list "Hide" rule, delegated to ProductVisibilityGuard. No-ops if no price list resolves. */
    private function excludeHiddenProducts(QueryBuilder $qb, ?string $regionName): void
    {
        $priceList = $this->pricingScope($regionName)->priceList();
        if ($priceList instanceof PriceList) {
            $this->visibilityGuard->applyTo($qb, 'p', $priceList, null);
        }
    }

    protected function resolveCustomerPricing(EntityManagerInterface $entityManager, ProductCore $product, ?string $regionName = null): ?ProductPricing
    {
        return $this->pricingScope($regionName)->pricingFor($product);
    }

    /**
     * Null covers both a deliberate "No Price" rule and nothing being configured — the two read the
     * same as a blank price, which is all this signature can express. Callers that need to tell
     * them apart should go through pricingScope()->priceFor(), which does.
     */
    protected function resolveCustomerPriceAmount(EntityManagerInterface $entityManager, ProductCore $product, ?string $regionName = null): ?string
    {
        return $this->pricingScope($regionName)->priceFor($product)->amount;
    }

    /** @return list<CompanyFulfillmentRegion> */
    protected function activeCompanyFulfillmentRegions(EntityManagerInterface $entityManager, Company $company): array
    {
        return $this->companyFulfillmentRegionService->activeRowsForCompany($company);
    }

    /** Client rule: a company with no active fulfillment region at all cannot see the catalog or buy. */
    protected function companyHasActiveFulfillmentRegion(EntityManagerInterface $entityManager, Company $company): bool
    {
        return $this->activeCompanyFulfillmentRegions($entityManager, $company) !== [];
    }

    /** @return list<FulfillmentRegion> */
    protected function guestVisibleFulfillmentRegions(EntityManagerInterface $entityManager): array
    {
        return $this->pricingResolver->guestVisibleFulfillmentRegions();
    }

    /**
     * Resolves a region name (as stored in session/cart pricing context) to its FulfillmentRegion
     * entity, case-insensitively — used by cart-hold syncing (CartController/CheckoutController),
     * which needs the entity itself rather than just its name.
     */
    protected function resolveFulfillmentRegionEntity(EntityManagerInterface $entityManager, ?string $regionName): ?FulfillmentRegion
    {
        return $this->pricingResolver->resolveFulfillmentRegionEntity($regionName);
    }

    /**
     * Dispatches CartSyncedEvent — called from CartController (add/update/remove/clear, and the
     * cart page view) and CheckoutController (the checkout page view). Core has no reference to
     * CartHoldBundle at all here: whatever reacts to this event (if anything) is entirely that
     * listener's concern, including no-op'ing when disabled or when no region is resolved.
     */
    protected function syncCartHold(
        Request $request,
        EntityManagerInterface $entityManager,
        CartService $cartService,
        EventDispatcherInterface $eventDispatcher,
    ): void {
        $regionName = $this->currentRegionForPricing($entityManager, $request->getSession());
        $region = $this->resolveFulfillmentRegionEntity($entityManager, $regionName);

        $eventDispatcher->dispatch(new CartSyncedEvent($cartService->getCart(), $region, $cartService->getSessionId()));
    }

    /**
     * Resolves the region picker for catalog browsing: logged-in customers are constrained to their own
     * active CompanyFulfillmentRegion rows, guests to regions marked guestVisible — requesting a region
     * outside that set, or having none at all, blocks the catalog rather than silently falling back
     * ("cannot see region at catalog and cannot buy").
     *
     * @return array{regions: list<string>, selected: string, blocked: bool}
     */
    protected function resolveBrowsingRegion(EntityManagerInterface $entityManager, \Symfony\Component\HttpFoundation\Request $request): array
    {
        $requested = trim((string) $request->query->get('region', ''));
        $regions = $this->allowedRegionNames($entityManager);

        if ($regions === []) {
            return ['regions' => [], 'selected' => '', 'blocked' => true, 'regionRequired' => false];
        }

        // An explicit ?region= outside the allowed set blocks; a stale session value just
        // falls back to the default instead.
        if ($requested !== '' && !in_array($requested, $regions, true)) {
            return ['regions' => $regions, 'selected' => '', 'blocked' => true, 'regionRequired' => false];
        }

        // An API request has no session and must not create one: the /api/ firewall is stateless, so
        // touching the session here would start one, emit a Set-Cookie the client never returns, and
        // leave an orphan session file behind on every single call.
        //
        // Losing the session also removes the sticky region a browser has, and "first allowed" is
        // the wrong thing to substitute: it would quote a multi-region customer the price for
        // whichever region happened to sort first, silently disagreeing with what that same account
        // sees on the site. One region is unambiguous and needs no parameter; more than one has to
        // be asked for explicitly. The caller is told which parameter and which values (#521 D3).
        if ($request->attributes->getBoolean('_api')) {
            if ($requested === '' && count($regions) > 1) {
                return ['regions' => $regions, 'selected' => '', 'blocked' => true, 'regionRequired' => true];
            }

            return [
                'regions' => $regions,
                'selected' => $requested !== '' ? $requested : $regions[0],
                'blocked' => false,
                'regionRequired' => false,
            ];
        }

        $session = $request->getSession();
        if ($requested === '') {
            $stored = trim((string) $session->get(self::SESSION_SELECTED_REGION, ''));
            $requested = in_array($stored, $regions, true) ? $stored : '';
        }

        $selected = $requested !== '' ? $requested : $regions[0];
        $session->set(self::SESSION_SELECTED_REGION, $selected);

        return ['regions' => $regions, 'selected' => $selected, 'blocked' => false, 'regionRequired' => false];
    }

    /**
     * Region used for pricing outside the catalog (cart, checkout, payment intent):
     * the session-stored picker selection when still valid, otherwise the same
     * first-allowed-region default the catalog picker applies. Null only when the
     * customer has no allowed region at all (the existing access gates handle that).
     */
    protected function currentRegionForPricing(
        EntityManagerInterface $entityManager,
        \Symfony\Component\HttpFoundation\Session\SessionInterface $session,
    ): ?string {
        $regions = $this->allowedRegionNames($entityManager);
        if ($regions === []) {
            return null;
        }

        $stored = trim((string) $session->get(self::SESSION_SELECTED_REGION, ''));

        return in_array($stored, $regions, true) ? $stored : $regions[0];
    }

    /**
     * Regions the current visitor may browse/buy in: the company's active
     * CompanyFulfillmentRegion rows for logged-in customers, guest-visible regions otherwise.
     *
     * @return list<string>
     */
    private function allowedRegionNames(EntityManagerInterface $entityManager): array
    {
        $company = $this->currentCompanyForPricing();

        if (!$company instanceof Company) {
            return array_map(
                static fn (FulfillmentRegion $r): string => $r->getName(),
                $this->guestVisibleFulfillmentRegions($entityManager)
            );
        }

        return array_map(
            static fn (CompanyFulfillmentRegion $row): string => $row->getFulfillmentRegion()->getName(),
            $this->activeCompanyFulfillmentRegions($entityManager, $company)
        );
    }

    /** The security read the pricing engine deliberately does not do for itself. */
    private function currentCompanyForPricing(): ?Company
    {
        return $this->pricingResolver->companyForPricing($this->getUser());
    }

    /**
     * Sums Available (Starting Inventory minus Cart Hold, Sales Hold, Pending and Approved) rather
     * than raw Starting Inventory — this is the customer-facing stock number, so it's clamped at 0
     * even though the admin inventory page shows a real (possibly negative) oversell.
     *
     * The four buckets are subtracted by ProductInventory::getAvailableQuantity() rather than here,
     * which is what keeps this from having to learn about #539's Sales Hold at all.
     *
     * The customer picks a REGION and the rows are keyed by WAREHOUSE (#546), so a named region is
     * resolved to the warehouse serving it and the rows are matched on that warehouse rather than
     * on a name. A region no warehouse serves has nothing to draw on, which is zero — the same
     * answer an unstocked region has always given.
     *
     * @param list<ProductInventory> $inventoryRows
     */
    protected function availableQuantityForProduct(array $inventoryRows, ?string $region = null): int
    {
        $selectedRegion = trim((string) $region);
        $selectedWarehouseId = null;

        if ($selectedRegion !== '') {
            $selectedWarehouseId = $this->warehouses->warehouseForRegionName($selectedRegion)?->getId();
            if ($selectedWarehouseId === null) {
                return 0;
            }
        }

        $quantity = 0;

        foreach ($inventoryRows as $inventory) {
            if (!$inventory instanceof ProductInventory) {
                continue;
            }

            if ($selectedWarehouseId !== null && $inventory->getWarehouse()?->getId() !== $selectedWarehouseId) {
                continue;
            }

            $quantity += $inventory->getAvailableQuantity();
        }

        return max(0, $quantity);
    }

    /**
     * @return list<string>
     */
    protected function customerFulfillmentRegionNames(EntityManagerInterface $entityManager, AppSettings $appSettings): array
    {
        $names = [];
        $rows = $entityManager->getRepository(FulfillmentRegion::class)->findBy(['status' => 'Active'], ['name' => 'ASC']);
        foreach ($rows as $row) {
            if (!$row instanceof FulfillmentRegion) {
                continue;
            }

            $name = trim((string) $row->getName());
            if ($name !== '') {
                $names[] = $name;
            }
        }

        if ($names === []) {
            $raw = (string) ($appSettings->get('fulfillment_regions', '') ?? '');
            $raw = str_replace([',', ';'], "\n", $raw);
            $parts = preg_split('/\\R+/', $raw) ?: [];

            foreach ($parts as $part) {
                $name = trim((string) $part);
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        $names = array_values(array_unique($names));
        if ($names === []) {
            $names = ['Main'];
        }

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

    /**
     * @return array{
     *     categoriesById: array<int, ProductCategory>,
     *     childrenMap: array<int, list<int>>
     * }
     */
    private function visibleCategoryGraph(EntityManagerInterface $entityManager): array
    {
        $categories = $entityManager->getRepository(ProductCategory::class)->findBy(['status' => 'Visible'], ['name' => 'ASC']);
        $categoriesById = [];
        $childrenMap = [];

        foreach ($categories as $category) {
            if (!$category instanceof ProductCategory || $category->getId() === null) {
                continue;
            }

            $id = (int) $category->getId();
            $categoriesById[$id] = $category;
            $parentId = $category->getParent()?->getId();
            $childrenMap[(int) ($parentId ?? 0)][] = $id;
        }

        return [
            'categoriesById' => $categoriesById,
            'childrenMap' => $childrenMap,
        ];
    }

    /**
     * @return list<int>
     */
    private function categoryAndDescendantIds(EntityManagerInterface $entityManager, ProductCategory $category): array
    {
        if ($category->getId() === null) {
            return [];
        }

        $graph = $this->visibleCategoryGraph($entityManager);
        $stack = [(int) $category->getId()];
        $seen = [];

        while ($stack !== []) {
            $id = array_pop($stack);
            if ($id === null || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;

            foreach ($graph['childrenMap'][$id] ?? [] as $childId) {
                $stack[] = $childId;
            }
        }

        return array_map('intval', array_keys($seen));
    }

    /**
     * The cart being priced, as the document a shipping calculator is handed (issue #165 step 9).
     *
     * The three customer sites that resolve shipping used to assemble a ShippingContext by hand and
     * are the only ones that cannot simply pass the cart entity, for the two reasons #186 already
     * recorded: resolveCartRows() prices a parallel array rather than the cart's own lines and drops
     * products the buyer's price list hides, which no cart line records; and checkout prices against
     * the address the customer picked on that page, which the cart does not record until conversion.
     * So the document is built from the rows and the address actually in play.
     *
     * THIS CART MUST NEVER BE PERSISTED, for exactly the reason Admin\OrderController's preview
     * order must not: FeeRepository::ensureBySlug() and CustomFieldDefinitionRepository::ensureBySlug()
     * flush() partway through a calculation, and Cart's items and addresses carry cascade: ['persist'],
     * so one persist() here would write a second cart row from a page render. What makes it safe is
     * that Doctrine ignores objects it was never given, and nothing hands this one to the manager.
     *
     * The header subtotal is stated because a document states what it is worth — the same figure
     * FeeContext::fromDocument() reads, and the one a free-shipping-threshold rule needs. It is the
     * line subtotal, before fees, discounts and the free-shipping waiver: the post-discount figure
     * shipping is judged against is applied by the controller (checkoutEffectiveShipping()), which is
     * where it has always lived. Per-line money is left unset — no shipping calculator reads it, and
     * CartItem only fills it from a pricing scope.
     *
     * @param list<array{product: ProductCore, qty: int, subtotal?: float|null}> $rows
     */
    protected function shippingDocumentFor(?Company $company, ?CompanyAddress $shippingAddress, array $rows): Cart
    {
        $document = new Cart();
        if ($company instanceof Company) {
            $document->setCompany($company);
        }
        if ($shippingAddress instanceof CompanyAddress) {
            $document->setShippingAddressFrom($shippingAddress);
        }

        foreach ($rows as $row) {
            $document->addItem(
                (new CartItem())->setProduct($row['product'])->setQuantity((int) $row['qty'])
            );
        }

        $subtotal = (float) array_sum(array_map(
            static fn (array $row): float => (float) ($row['subtotal'] ?? 0.0),
            $rows,
        ));

        return $document->setSubtotal(number_format($subtotal, 2, '.', ''));
    }

    /**
     * The customer's one delivery choice as the document's one shipping row.
     *
     * Pick-one lives in the customer flow rather than on the document, which happily holds as many
     * shipping rows as an admin types: a storefront offers a list and takes one answer, and that is
     * a property of the flow, not of the thing being built. The row is SOURCE_AUTO_CALC because a
     * calculator produced the amount — nobody typed it.
     *
     * The amount passed is the effective one, after any free-shipping threshold, so the row states
     * what is actually charged. A waived option still gets a $0 row: "delivery by Ground, free" is a
     * statement, while no row at all reads as "shipping not decided" and is what a null label means.
     *
     * @return FeeLine[]
     */
    protected function shippingLinesFor(?string $label, float $amount, string $taxClass): array
    {
        if ($label === null) {
            return [];
        }

        return [new FeeLine(
            null,
            'shipping',
            sprintf('Shipping (%s)', $label),
            $taxClass,
            round($amount, 2),
            'main_line',
            FeeLine::TYPE_SHIPPING,
            FeeLine::SOURCE_AUTO_CALC,
        )];
    }
}
