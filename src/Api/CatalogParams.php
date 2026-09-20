<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\HttpFoundation\Request;

/**
 * CONVERSION. Turns the API's declared query parameters into the shape the storefront catalog form
 * posts, so Customer\CatalogController::index() receives what it already knows how to read and
 * cannot tell an API caller from a browser.
 *
 * That controller reads its filters out of a `ProductSearch[...]` array, because that is the name
 * the storefront's <form> uses, and its facet filters out of `ProductSearch[cf][<slug>][]`. An API
 * caller should not have to know either of those, so `?category=12&q=winter&cf[rim_pcd]=5x112` is
 * renamed here and nowhere else.
 *
 * ACCEPTED PARAMETERS ARE DECLARED, NOT FORWARDED. This file used to loop over whatever arrived in
 * `filters[...]` and copy it in wholesale, which was wrong three times over (#521 F6): it wrote one
 * level too high and as a scalar, so every filter was silently ignored and the caller got the whole
 * catalog with a 200; it let `filters[category_id]` overwrite the declared `category`; and it
 * accepted array values into slots the controller casts to string, which is a TypeError.
 *
 * The principle: pass-through is right for VALUES — this file must never second-guess what a price
 * is or which products are visible. It is wrong for the SET OF KEYS, because that set is the API's
 * contract, and a contract nobody wrote down is one nobody can keep. Everything accepted is in
 * PARAMS or FILTER_PREFIX below; anything else is ignored rather than smuggled through.
 */
final class CatalogParams
{
    /**
     * The complete list of accepted query parameters, api name => how it is read. Adding one is a
     * deliberate edit here — which is the entire point.
     *
     * @var array<string, string>
     */
    public const PARAMS = [
        'region' => 'string',
        'q' => 'string',
        'category' => 'int',
        'page' => 'int',
        'sort' => 'string',
        'dir' => 'string',
    ];

    /** Facet filters arrive under this prefix: ?cf[rim_pcd]=5x112 or ?cf[rim_pcd][]=5x112. */
    public const FILTER_PREFIX = 'cf';

    /**
     * @return array<string, mixed> query params for the forged sub-request
     */
    public static function toCatalogQuery(Request $request): array
    {
        $productSearch = array_filter([
            'category_id' => self::intOrNull($request, 'category'),
            'q' => self::stringOrNull($request, 'q'),
            'sort' => self::stringOrNull($request, 'sort'),
            'dir' => self::stringOrNull($request, 'dir'),
        ], static fn (mixed $value): bool => $value !== null);

        // Written at the depth the controller reads, and always as a list of strings — the shape its
        // facet checkboxes submit. Slugs are not validated here: which slugs exist is decided by
        // whichever facet provider registered them for the category being browsed, and that is the
        // controller's business, not this file's. Values that are not scalars are dropped rather
        // than cast, because there is no sane string for an array and guessing one is how a query
        // string turns into a 500.
        $filters = self::filterValues($request);
        if ($filters !== []) {
            $productSearch[self::FILTER_PREFIX] = $filters;
        }

        return array_filter([
            'ProductSearch' => $productSearch === [] ? null : $productSearch,
            // Region is a top-level query param on the catalog route too, so it needs no renaming.
            'region' => self::stringOrNull($request, 'region'),
            'page' => self::intOrNull($request, 'page'),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, list<string>> */
    private static function filterValues(Request $request): array
    {
        $raw = $request->query->all(self::FILTER_PREFIX);

        $filters = [];
        foreach ($raw as $slug => $value) {
            $slug = trim((string) $slug);
            if ($slug === '') {
                continue;
            }

            $values = [];
            foreach (is_array($value) ? $value : [$value] as $single) {
                if (!is_scalar($single)) {
                    continue;
                }

                $single = trim((string) $single);
                if ($single !== '') {
                    $values[] = $single;
                }
            }

            if ($values !== []) {
                $filters[$slug] = $values;
            }
        }

        return $filters;
    }

    private static function stringOrNull(Request $request, string $key): ?string
    {
        $value = $request->query->get($key);
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function intOrNull(Request $request, string $key): ?int
    {
        $value = self::stringOrNull($request, $key);

        return $value === null || !ctype_digit($value) ? null : (int) $value;
    }
}
