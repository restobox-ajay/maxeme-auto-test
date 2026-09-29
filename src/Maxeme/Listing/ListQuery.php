<?php

declare(strict_types=1);

namespace App\Maxeme\Listing;

use Symfony\Component\HttpFoundation\Request;

/**
 * The server half of core's URL-driven admin tables: app.js turns a `.table-card`'s search box,
 * `th[data-sort-field]` headers, per-page select and pager into `q`, `sort`, `dir`, `page` and
 * `limit` query parameters, and this reads them back. The sort key is always one the list
 * declared, so it is safe to map onto a column.
 */
final class ListQuery
{
    /** app.js's PER_PAGE_OPTS: the per-page choices it draws in the table footer. */
    public const LIMITS = [20, 100, 200, 500];

    private function __construct(
        public readonly string $search,
        public readonly string $sort,
        public readonly string $dir,
        public readonly int $page,
        public readonly int $limit,
        public readonly string $defaultSort,
        public readonly string $defaultDir,
    ) {
    }

    /** @param list<string> $sortKeys the first one is the default */
    public static function fromRequest(Request $request, array $sortKeys, string $defaultDir = 'asc'): self
    {
        $sort = (string) $request->query->get('sort', '');
        $dir = strtolower((string) $request->query->get('dir', $defaultDir));
        $limit = $request->query->getInt('limit', self::LIMITS[0]);

        return new self(
            search: trim((string) $request->query->get('q', '')),
            sort: in_array($sort, $sortKeys, true) ? $sort : $sortKeys[0],
            dir: $dir === 'desc' ? 'desc' : 'asc',
            page: max(1, $request->query->getInt('page', 1)),
            limit: in_array($limit, self::LIMITS, true) ? $limit : self::LIMITS[0],
            defaultSort: $sortKeys[0],
            defaultDir: $defaultDir,
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->limit;
    }
}
