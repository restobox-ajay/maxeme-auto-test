<?php

declare(strict_types=1);

namespace App\Service\Product;

use App\Entity\ProductCore;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The product field every admin screen shares — the three tiers, held once, in the one layer no
 * bundle can delete.
 *
 * ## What this is
 *
 * Asking for a product is the same job on every screen in the app, and the answer #399 settled for
 * the sell side is three tiers:
 *
 *  1. under `INLINE_LIMIT` products, a plain `<select>` carrying the whole catalog — which a
 *     browser makes type-to-searchable for free, with no script on the page at all;
 *  2. past it, a `<select>` seeded only with what the screen already names, plus a
 *     `data-search-url` that lets `js-searchable-select` fetch the rest as the admin types;
 *  3. and, for tier 2 with scripting off, a `<noscript>` id box — because that select genuinely
 *     has nothing to offer.
 *
 * The procurement bundle wrote that down in a picker of its own once five of its screens had each
 * asked for a raw database id in their own slightly different way. Then Inventory Depth needed the
 * same field on two more (`lots`, `low_stock`), and the question became where one copy of it can
 * live.
 *
 * ## Why core, and not a shared bundle or a second copy (#8)
 *
 * **Because core is the only layer neither bundle can delete.** Verified rather than assumed:
 *
 *  - `config/bundles.php` discovers modules with `glob(modules/*)`. A module folder can be deleted
 *    outright and the app still boots — one fewer match, no "class not found".
 *  - `bundle_status` switches a module Inactive independently (`BundleStatusRepository::isActive()`,
 *    `/admin/bundles`), which every module screen enforces for itself with `denyIfInactive()`.
 *
 * So `InventoryDepthBundle` including `@Procurement/_product_field.html.twig` would have been a
 * 500 on an inventory screen the day somebody deleted the purchasing module — the Twig namespace
 * and the route the partial calls `path()` on both leave with it. That exact failure is already
 * written down one file away, in `ReorderController::purchaseOrderRoute()`, which guards a mere
 * LINK to procurement for the same reason. A picker is not a link; it cannot be guarded away,
 * because the fallback would be the raw id box this replaces.
 *
 * A second copy in InventoryDepthBundle was the other option and is worse than it looks: the five
 * procurement screens drifted apart while each held its own copy of this field, which is what the
 * shared one was written to stop. Two copies is how that starts again.
 *
 * A third "shared" module fixes nothing — it is deletable too, so it moves the failure rather than
 * removing it.
 *
 * ## What stayed in the procurement bundle, and why
 *
 * The vendor money. Its own picker keeps its class name and its exact public API — the vendor it
 * takes included — and now composes this class for the catalog half, adding rate-card costs to the
 * rows. Core must not know what a vendor is: a core class type-hinting a module entity inverts the
 * dependency this whole note is about, and `glob()` would then be free to delete a class core
 * signatures name. (Naming that class here would break it in a second way — the bundle's own
 * packaging test greps `src/` for its namespace, which is this rule enforced from the other side.)
 *
 * That is also why the partial takes its search URL as a parameter instead of choosing one.
 * A purchase screen wants the vendor-priced endpoint; an inventory screen has no vendor and wants
 * the plain one this ships (`App\Controller\Admin\ProductPickerController`).
 */
final class ProductPicker
{
    /**
     * Past this many products the `<select>` stops carrying the catalog and the search endpoint
     * takes over.
     *
     * 200 is `OrderController::PRODUCT_SELECT_INLINE_LIMIT`, restated so that every side of the app
     * switches to remote search at the same catalog size. One catalog behaving differently
     * depending on which screen you happened to open is the drift this class exists to end.
     */
    public const INLINE_LIMIT = 200;

    /**
     * Matches are capped per request, and one extra row is fetched to answer "is there more?"
     * without a second COUNT over the same LIKE — `OrderController::PRODUCT_SEARCH_PAGE_SIZE`'s
     * trick, for the same reason.
     */
    public const SEARCH_PAGE_SIZE = 50;

    /**
     * Nothing is searched below two characters.
     *
     * Enforced on the server and not only in the browser, because the alternative is worse than
     * returning nothing: an empty term skips the LIKE entirely and hands back the first page of the
     * catalog, which is neither a search result nor a set anybody asked for. Keep in step with
     * MIN_SEARCH_CHARS in app.js.
     */
    public const MIN_SEARCH_CHARS = 2;

    /**
     * The name the `<noscript>` id box posts under, appended to the field's own name.
     *
     * A suffix and not a second copy of the field name, so nothing depends on which of the two PHP
     * happens to parse last. `idFromPostedRow()` is the one place that decides which won.
     */
    public const MANUAL_SUFFIX = '_manual';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProductBarcodeSearchResolver $barcodeSearch,
    ) {
    }

    /**
     * True when the catalog is too big to inline, so the screen must render tier 2 + 3.
     *
     * Counted rather than loaded — the whole point of the tier is not to touch 5,000 rows to draw
     * one dropdown.
     */
    public function isRemote(): bool
    {
        return $this->em->getRepository(ProductCore::class)->count(['deleted' => false]) > self::INLINE_LIMIT;
    }

    /**
     * The options a `<select>` should carry.
     *
     * Under the limit that is the catalog. Past it, it is exactly the products the screen already
     * names and nothing else: a seeded select shows what the row currently holds instead of
     * rendering as empty and looking broken, and the search endpoint supplies everything else.
     *
     * @param list<int> $selectedIds product ids the screen already names, kept when remote
     *
     * @return list<ProductCore>
     */
    public function options(array $selectedIds = []): array
    {
        $criteria = ['deleted' => false];

        if ($this->isRemote()) {
            $selectedIds = array_values(array_filter(array_unique(array_map('intval', $selectedIds))));
            if ($selectedIds === []) {
                return [];
            }

            $criteria['id'] = $selectedIds;
        }

        /** @var list<ProductCore> $rows */
        $rows = $this->em->getRepository(ProductCore::class)->findBy($criteria, ['sku' => 'ASC']);

        return $rows;
    }

    /**
     * One page of matching products, as entities.
     *
     * Entities and not rows, because the callers that need more than a name have to price them:
     * the purchase side resolves one query of rate-card costs for the whole page, which it cannot
     * do from three strings and must not do per product (a findOneBy() per row is 50 extra round
     * trips per keystroke).
     *
     * @return array{products: list<ProductCore>, hasMore: bool}
     */
    public function searchPage(string $term, int $offset = 0): array
    {
        $term = trim($term);

        if (mb_strlen($term) < self::MIN_SEARCH_CHARS) {
            return ['products' => [], 'hasMore' => false];
        }

        // #707: a scanned/typed UPC, EAN, GTIN, or vendor/customer part number matches too, not
        // only name/SKU — resolved through the barcode seam so this class stays free of a
        // compile-time reference to BarcodeBundle, which is deletable.
        $barcodeMatchedIds = $this->barcodeSearch->matchingProductIds($term);

        // One field-by-field DQL condition, built once from ProductSearchFields::ORDER and reused
        // both to decide what matches (OR'd together below) and to rank what matched (a field
        // earlier in that list outranks one later in it) — the two questions used to be answered
        // by two independently hand-written pieces of SQL, which is exactly the drift #707's
        // barcode fix showed up: matching gained a third field and ranking would have had to be
        // taught about it separately.
        $conditions = [];
        foreach (ProductSearchFields::ORDER as $field) {
            $conditions[$field] = match ($field) {
                ProductSearchFields::SKU => 'p.sku LIKE :term',
                ProductSearchFields::NAME => 'p.name LIKE :term',
                ProductSearchFields::BARCODE => $barcodeMatchedIds !== [] ? 'p.id IN (:barcodeIds)' : '1 = 0',
                default => '1 = 0',
            };
        }

        $rankCases = [];
        foreach (array_values($conditions) as $rank => $condition) {
            $rankCases[] = sprintf('WHEN %s THEN %d', $condition, $rank);
        }
        $rankExpression = 'CASE ' . implode(' ', $rankCases) . ' ELSE ' . count($conditions) . ' END';

        $qb = $this->em->getRepository(ProductCore::class)->createQueryBuilder('p')
            ->where('p.deleted = false')
            ->andWhere(implode(' OR ', $conditions))
            ->setParameter('term', '%' . $term . '%');

        if ($barcodeMatchedIds !== []) {
            $qb->setParameter('barcodeIds', $barcodeMatchedIds);
        }

        $found = $qb
            ->addSelect(sprintf('(%s) AS HIDDEN match_rank', $rankExpression))
            ->orderBy('match_rank', 'ASC')
            ->addOrderBy('p.sku', 'ASC')
            // The tiebreaker is what makes paging safe rather than decoration: SKUs collide in a
            // real catalog, and LIMIT/OFFSET over a non-unique sort may return a row twice or skip
            // one between pages. Any total order does; id is the cheap one. Note the functional
            // suite cannot prove this is needed — SQLite returns rowid order deterministically, so
            // the paging test passes with this line deleted. It is here for engines that promise
            // nothing of the sort.
            ->addOrderBy('p.id', 'ASC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(self::SEARCH_PAGE_SIZE + 1)
            ->getQuery()
            ->getResult();

        $hasMore = count($found) > self::SEARCH_PAGE_SIZE;

        /** @var list<ProductCore> $page */
        $page = array_values(array_filter(
            array_slice($found, 0, self::SEARCH_PAGE_SIZE),
            static fn (mixed $row): bool => $row instanceof ProductCore,
        ));

        return ['products' => $page, 'hasMore' => $hasMore];
    }

    /**
     * The same page, in the shape `js-searchable-select` reads: id, sku, name and nothing else.
     *
     * No money. A screen with no vendor and no company has no price to show that would not be a
     * guess, and a picker is not the place to guess one.
     *
     * @return array{products: list<array<string, string>>, hasMore: bool}
     */
    public function search(string $term, int $offset = 0): array
    {
        $page = $this->searchPage($term, $offset);

        return [
            'products' => array_map(static fn (ProductCore $product): array => [
                'id' => (string) $product->getId(),
                'sku' => $product->getSku(),
                'name' => $product->getName(),
            ], $page['products']),
            'hasMore' => $page['hasMore'],
        ];
    }

    /**
     * The product id a posted row names — the select first, then the `<noscript>` id box.
     *
     * `admin/_partials/product_field.html.twig` renders two controls past the inline limit, under
     * two different names on purpose, so this decides which one won rather than leaving it to
     * whichever PHP happened to parse last. The select wins when it posted something, because with
     * scripting on it is the only one in the DOM; the manual box is only ever reachable when the
     * select had nothing to offer.
     *
     * Static, and here rather than on a controller base class, because the two halves of this
     * contract — the markup that writes the names and the code that reads them — belong in one
     * place. Both bundles' abstract controllers call it; neither restates the rule.
     *
     * @param array<string, mixed> $row a posted line, or the whole request bag for a single-product form
     */
    public static function idFromPostedRow(array $row, string $key = 'product_id'): int
    {
        $chosen = (int) ($row[$key] ?? 0);

        return $chosen > 0 ? $chosen : (int) ($row[$key . self::MANUAL_SUFFIX] ?? 0);
    }
}
