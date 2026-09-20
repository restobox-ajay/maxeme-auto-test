<?php

namespace App\Controller\Customer;

use App\Api\ApiProblem;
use App\Api\ProductPresenter;
use App\Entity\ProductCategory;
use App\Entity\ProductCore;
use App\Service\CartService;
use App\Service\CatalogFacetResolver;
use App\Service\CatalogListColumnResolver;
use App\Service\CatalogViewConfigResolver;
use App\Service\TemplateOverrideResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/product')]
final class CatalogController extends AbstractCustomerController
{
    private const PER_PAGE = 24;

    /**
     * Whitelist of list-view column sort fields honored server-side (ProductSearch[sort]). Anything
     * outside this set is ignored and the default order (name, ascending) applies, so no
     * attacker-controlled string ever reaches the query. See AbstractCustomerController's
     * CATALOG_DB_SORT_COLUMNS / CATALOG_PHP_SORT_FIELDS for how each field is actually ordered.
     */
    private const SORT_FIELDS = ['description', 'sku', 'unit', 'size', 'price', 'stock', 'remarks'];

    #[Route('/index', name: 'customer_catalog', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $entityManager, LoggerInterface $logger, TemplateOverrideResolver $templateOverrideResolver, CatalogFacetResolver $catalogFacetResolver, CatalogViewConfigResolver $catalogViewConfigResolver, CatalogListColumnResolver $catalogListColumnResolver, CartService $cartService): Response
    {
        $cartHoldExpiresAt = $cartService->getCart()?->getHoldExpiresAt();

        $productSearch = $request->query->all('ProductSearch');
        $categoryId = (int) ($productSearch['category_id'] ?? 0);
        // Same point convention as the template-override/facet resolvers below (§3b/§3c of
        // CATEGORY_PAGE_FILTER_PLAN.md) — resolved here too so a bundle can restrict/default the
        // grid/list toggle per exact category, without this controller hardcoding any of it.
        $templatePoint = $categoryId > 0 ? 'customer_catalog_index:' . $categoryId : 'customer_catalog_index';
        $viewConfig = $catalogViewConfigResolver->resolve($templatePoint);
        $requestedView = isset($productSearch['view']) ? strtoupper((string) $productSearch['view']) : null;
        $currentView = $this->resolveCurrentView($requestedView, $viewConfig);
        $searchQuery = trim((string) $request->query->get('q', $productSearch['q'] ?? ''));
        [$sortField, $sortDir] = $this->resolveCatalogSort($productSearch);
        $page = max(1, (int) $request->query->get('page', 1));
        $category = $categoryId > 0 ? $entityManager->find(ProductCategory::class, $categoryId) : null;
        $customFieldFilters = $this->customFieldFiltersFromRequest($productSearch);
        $listColumns = $catalogListColumnResolver->columnsFor($templatePoint);

        $browsingRegion = $this->resolveBrowsingRegion($entityManager, $request);
        $regions = $browsingRegion['regions'];
        $selectedRegion = $browsingRegion['selected'];
        $blocked = $browsingRegion['blocked'];

        if ($blocked) {
            // A missing region on a multi-region company is the caller's mistake, not a refusal —
            // 400 with the parameter name and the values that would work, so an integrator can fix
            // it from the response instead of guessing. The values are the caller's own company's
            // regions, which they can already see on the site.
            if ($request->attributes->getBoolean('_api') && ($browsingRegion['regionRequired'] ?? false)) {
                return (new ApiProblem(
                    'region_required',
                    'Fulfillment region required',
                    'Your company has more than one fulfillment region, so "region" must be specified.',
                    Response::HTTP_BAD_REQUEST,
                    ['parameter' => 'region', 'allowedValues' => $regions],
                ))->toResponse();
            }

            if ($request->attributes->getBoolean('_api') || $request->isXmlHttpRequest()) {
                return new JsonResponse(['blocked' => true], Response::HTTP_FORBIDDEN);
            }

            return $this->render('customer/catalog/index.html.twig', [
                'blocked' => true,
                'categories' => $this->categoriesFromDatabase($entityManager, $selectedRegion),
                'products' => [],
                'activeCategoryId' => (string) $categoryId,
                'totalProductsCount' => 0,
                'uncategorizedCount' => 0,
                'activeCategoryCount' => 0,
                'regions' => $regions,
                'selectedRegion' => $selectedRegion,
                'searchQuery' => $searchQuery,
                'currentView' => $currentView,
                'currentPage' => 1,
                'totalPages' => 1,
                'matchingProductsCount' => 0,
                'perPage' => self::PER_PAGE,
                'selectedCategoryTrail' => [],
                'cartHoldExpiresAt' => $cartHoldExpiresAt,
            ], new Response('', Response::HTTP_FORBIDDEN));
        }

        $totalProductsCount = $this->productCountFromDatabase($entityManager, null, $selectedRegion);
        $uncategorizedCount = $this->productCountUncategorizedFromDatabase($entityManager, $selectedRegion);
        $matchingProductsCount = $this->productSearchCountFromDatabase(
            $entityManager,
            $categoryId === -1 ? -1 : ($categoryId > 0 ? $categoryId : null),
            $searchQuery,
            $selectedRegion,
            $customFieldFilters,
        );
        $totalPages = max(1, (int) ceil($matchingProductsCount / self::PER_PAGE));
        $page = min($page, $totalPages);
        $products = $this->productsFromDatabase(
            $entityManager,
            $categoryId === -1 ? -1 : ($categoryId > 0 ? $categoryId : null),
            $selectedRegion,
            $searchQuery,
            $page,
            self::PER_PAGE,
            null,
            $customFieldFilters,
            array_column($listColumns, 'slug'),
            $sortField,
            $sortDir,
        );

        // Same query, same rows, a third rendering. `_api` is set only by a sub-request forwarded
        // from App\Controller\Api, so the API gets the rows themselves instead of Twig — that is the
        // entire reason the API needs no catalog logic of its own. Placed here, after every
        // filter/visibility/pricing decision above has already been made, so an API caller cannot
        // reach a different answer than the page does.
        if ($request->attributes->getBoolean('_api')) {
            return new JsonResponse([
                // Never $products directly. That array exists to feed a Twig template and may grow a
                // field whenever a partial needs one; publishing it wholesale is how the payload
                // outran the page. ProductPresenter names every key it emits — see #521 F7/F8.
                'products' => ProductPresenter::fromCatalogRows($products, $request->getSchemeAndHttpHost()),
                'total' => $matchingProductsCount,
                'page' => $page,
                'pages' => $totalPages,
                'perPage' => self::PER_PAGE,
                'region' => $selectedRegion,
                'query' => $searchQuery,
            ]);
        }

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'html' => $this->renderView('customer/catalog/_products.html.twig', [
                    'products' => $products,
                    'isListView' => $currentView === 'LIST_VIEW',
                    'currentSort' => $sortField,
                    'currentDir' => $sortDir,
                ]),
                'total' => $matchingProductsCount,
                'page' => $page,
                'pages' => $totalPages,
                'perPage' => self::PER_PAGE,
                'activeCategoryId' => (string) $categoryId,
                'selectedRegion' => $selectedRegion,
                'query' => $searchQuery,
                'view' => $currentView,
            ]);
        }

        $selectedCategoryTrail = [];
        if ($category instanceof ProductCategory) {
            $selectedCategoryTrail = $this->categoryBreadcrumbTrail($category);
        } elseif ($categoryId === -1) {
            $selectedCategoryTrail = [
                ['id' => '-1', 'name' => 'Uncategorized'],
            ];
        }

        // Resolved against the exact category being browsed, not its tree root — a category a
        // bundle targets (e.g. an "Accessories" role pointed at an existing subcategory) is not
        // guaranteed to be top-level in every store's real category tree. Browsing "All
        // categories" or "Uncategorized" has no single category id, so it always gets the shared
        // default template. See CATEGORY_PAGE_FILTER_PLAN.md §3b.
        $template = $templateOverrideResolver->resolve($templatePoint, 'customer/catalog/index.html.twig');

        $customFieldFacets = [];
        foreach ($catalogFacetResolver->facetsFor($templatePoint) as $facet) {
            $customFieldFacets[] = [
                'slug' => $facet['slug'],
                'label' => $facet['label'],
                'advanced' => $facet['advanced'],
                'counts' => $this->customFieldFacetCounts($entityManager, $facet['slug'], $categoryId > 0 ? $categoryId : null, $selectedRegion),
                'selected' => $customFieldFilters[$facet['slug']] ?? [],
            ];
        }

        if ($selectedRegion === '') {
            // resolveBrowsingRegion() should never return an empty selection when not blocked —
            // if this fires, something upstream regressed; surface it instead of guessing a region.
            $logger->warning('Catalog resolved an empty region while not blocked.', [
                'companyId' => $this->currentCompanyId(),
            ]);
        }

        return $this->render($template, [
            'blocked' => false,
            'categories' => $this->categoriesFromDatabase($entityManager, $selectedRegion),
            'products' => $products,
            'activeCategoryId' => (string) $categoryId,
            'totalProductsCount' => $totalProductsCount,
            'uncategorizedCount' => $uncategorizedCount,
            'activeCategoryCount' => $categoryId === -1 ? $uncategorizedCount : ($category instanceof ProductCategory ? $this->productCountFromDatabase($entityManager, $category, $selectedRegion) : $totalProductsCount),
            'regions' => $regions,
            'selectedRegion' => $selectedRegion,
            'searchQuery' => $searchQuery,
            'currentSort' => $sortField,
            'currentDir' => $sortDir,
            'currentView' => $currentView,
            'gridViewAvailable' => $viewConfig['gridAvailable'],
            'listViewAvailable' => $viewConfig['listAvailable'],
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'matchingProductsCount' => $matchingProductsCount,
            'perPage' => self::PER_PAGE,
            'selectedCategoryTrail' => $selectedCategoryTrail,
            'customFieldFacets' => $customFieldFacets,
            'listColumns' => $listColumns,
            'cartHoldExpiresAt' => $cartHoldExpiresAt,
        ]);
    }

    /**
     * Honors an explicit, still-available `?ProductSearch[view]=`; otherwise falls back to the
     * page's configured default view, itself falling back to whichever mode remains available if
     * the configured default was turned off. See App\Contract\Bundle\CatalogViewConfigProviderInterface.
     *
     * @param array{gridAvailable: bool, listAvailable: bool, defaultView: string} $viewConfig
     */
    private function resolveCurrentView(?string $requestedView, array $viewConfig): string
    {
        if ($requestedView === 'GRID_VIEW' && $viewConfig['gridAvailable']) {
            return 'GRID_VIEW';
        }

        if ($requestedView === 'LIST_VIEW' && $viewConfig['listAvailable']) {
            return 'LIST_VIEW';
        }

        if ($viewConfig['defaultView'] === 'LIST_VIEW' && $viewConfig['listAvailable']) {
            return 'LIST_VIEW';
        }

        return $viewConfig['gridAvailable'] ? 'GRID_VIEW' : 'LIST_VIEW';
    }

    /**
     * Validates the list-view sort request against the SORT_FIELDS whitelist and a fixed asc/desc
     * direction. Returns [null, 'asc'] (default name-ascending order) whenever the requested field
     * isn't whitelisted, so an unknown/malicious ProductSearch[sort] is silently ignored rather
     * than reaching the query. See §a/§b of GitHub issue #180.
     *
     * @param array<string, mixed> $productSearch
     * @return array{0: ?string, 1: string} [field or null, 'asc'|'desc']
     */
    private function resolveCatalogSort(array $productSearch): array
    {
        $field = isset($productSearch['sort']) ? (string) $productSearch['sort'] : '';
        if (!in_array($field, self::SORT_FIELDS, true)) {
            return [null, 'asc'];
        }

        $dir = isset($productSearch['dir']) ? strtolower((string) $productSearch['dir']) : 'asc';

        return [$field, $dir === 'desc' ? 'desc' : 'asc'];
    }

    /**
     * Reads ProductSearch[cf][<slug>][]=<value> generically — no hardcoded knowledge of which
     * slugs mean what, that's entirely up to whichever CatalogFacetProviderInterface registered
     * the slug for the category being browsed. See CATEGORY_PAGE_FILTER_PLAN.md §3c.
     *
     * @param array<string, mixed> $productSearch
     * @return array<string, list<string>>
     */
    private function customFieldFiltersFromRequest(array $productSearch): array
    {
        $raw = $productSearch['cf'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $filters = [];
        foreach ($raw as $slug => $values) {
            if (!is_string($slug) || $slug === '' || !is_array($values)) {
                continue;
            }

            $clean = array_values(array_filter(array_map(
                static fn ($v): string => trim((string) $v),
                $values
            ), static fn (string $v): bool => $v !== ''));

            if ($clean !== []) {
                $filters[$slug] = $clean;
            }
        }

        return $filters;
    }

    #[Route('/detail/{id}', name: 'customer_product_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id, Request $request, EntityManagerInterface $entityManager, CartService $cartService): Response
    {
        $browsingRegion = $this->resolveBrowsingRegion($entityManager, $request);
        $cartHoldExpiresAt = $cartService->getCart()?->getHoldExpiresAt();

        if ($browsingRegion['blocked']) {
            return $this->render('customer/catalog/detail.html.twig', [
                'blocked' => true,
                'regions' => $browsingRegion['regions'],
                'selectedRegion' => $browsingRegion['selected'],
                'cartHoldExpiresAt' => $cartHoldExpiresAt,
            ], new Response('', Response::HTTP_FORBIDDEN));
        }

        $selectedRegion = $browsingRegion['selected'];

        $product = $this->customerVisibleProductById($entityManager, $id, $selectedRegion);
        if (!$product instanceof ProductCore) {
            throw $this->createNotFoundException('Product could not be found.');
        }

        $productRow = $this->customerProductRow($entityManager, $product, $selectedRegion);

        return $this->render('customer/catalog/detail.html.twig', [
            'blocked' => false,
            'product' => $product,
            'productRow' => $productRow,
            'regions' => $browsingRegion['regions'],
            'selectedRegion' => $selectedRegion,
            'cartHoldExpiresAt' => $cartHoldExpiresAt,
        ]);
    }

    /**
     * @return list<array{id:string,name:string}>
     */
    private function categoryBreadcrumbTrail(ProductCategory $category): array
    {
        $trail = [];
        $current = $category;

        while ($current instanceof ProductCategory && $current->getId() !== null) {
            array_unshift($trail, [
                'id' => (string) $current->getId(),
                'name' => $current->getName(),
            ]);
            $current = $current->getParent();
        }

        return $trail;
    }
}
