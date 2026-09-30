<?php

declare(strict_types=1);

namespace App\Maxeme\Listing;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * One page of a server-side list, plus what the list template needs for its sort headers,
 * search box and footer (see templates/maxeme/_macros.html.twig).
 *
 * @template T
 */
final class ListPage
{
    /** @param list<T> $items */
    private function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly ListQuery $query,
    ) {
    }

    /**
     * Applies the list's search box, its sort and its page to $queryBuilder (see filter()).
     *
     * @param array<string, string> $sorts sort key => DQL column (the keys ListQuery was built with)
     * @param list<string> $searchColumns DQL columns the search box looks in
     *
     * @return self<mixed>
     */
    public static function paginate(QueryBuilder $queryBuilder, ListQuery $query, array $sorts, array $searchColumns): self
    {
        self::filter($queryBuilder, $query, $sorts, $searchColumns)
            ->setFirstResult($query->offset())
            ->setMaxResults($query->limit);

        $paginator = new Paginator($queryBuilder, fetchJoinCollection: false);

        return new self(iterator_to_array($paginator, false), count($paginator), $query);
    }

    /**
     * Applies the list's search box (a case-insensitive "contains" across $searchColumns) and its
     * sort (then the root alias's id, for a stable order) to $queryBuilder, but no page: every row
     * of the current view, e.g. for an export.
     *
     * @param array<string, string> $sorts
     * @param list<string> $searchColumns
     */
    public static function filter(QueryBuilder $queryBuilder, ListQuery $query, array $sorts, array $searchColumns): QueryBuilder
    {
        if ($query->search !== '' && $searchColumns !== []) {
            $queryBuilder
                ->andWhere($queryBuilder->expr()->orX(...array_map(
                    static fn (string $column): string => sprintf('LOWER(%s) LIKE :list_search', $column),
                    $searchColumns,
                )))
                ->setParameter('list_search', '%' . mb_strtolower($query->search) . '%');
        }

        $alias = $queryBuilder->getRootAliases()[0];

        return $queryBuilder
            ->orderBy($sorts[$query->sort], $query->dir)
            ->addOrderBy($alias . '.id', 'ASC');
    }

    public function pageCount(): int
    {
        return max(1, (int) ceil($this->total / $this->query->limit));
    }
}
