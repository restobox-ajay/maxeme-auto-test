<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;

/**
 * Makes a list's ORDER BY case-insensitive for text columns. SQLite compares text byte by byte,
 * so "ZUO" sorted before "abc"; wrapping a text field in LOWER() puts "abc" next to "ABC", as
 * the mockup's tables sort. Numbers, dates and expressions are left as they are.
 */
final class CaseInsensitiveSort
{
    private const TEXT_TYPES = ['string', 'text', 'ascii_string'];

    /** LOWER(column) when $column is an "alias.field" text field of an entity in $queryBuilder; otherwise $column as given. */
    public static function expression(QueryBuilder $queryBuilder, string $column): string
    {
        if (preg_match('/^(\w+)\.(\w+)$/', $column, $match) !== 1) {
            return $column;
        }
        $class = self::classOfAlias($queryBuilder, $match[1]);
        if ($class === null) {
            return $column;
        }
        $metadata = $queryBuilder->getEntityManager()->getClassMetadata($class);
        if (!$metadata->hasField($match[2]) || !in_array($metadata->getTypeOfField($match[2]), self::TEXT_TYPES, true)) {
            return $column;
        }

        return sprintf('LOWER(%s)', $column);
    }

    /** @return class-string|null the entity an alias of $queryBuilder (a root or a join) stands for */
    private static function classOfAlias(QueryBuilder $queryBuilder, string $alias, int $depth = 0): ?string
    {
        $roots = array_combine($queryBuilder->getRootAliases(), $queryBuilder->getRootEntities());
        if (isset($roots[$alias])) {
            return $roots[$alias];
        }
        if ($depth > 5) {
            return null;
        }
        foreach ($queryBuilder->getDQLPart('join') as $joins) {
            foreach ($joins as $join) {
                if (!$join instanceof Join || $join->getAlias() !== $alias) {
                    continue;
                }
                $path = $join->getJoin();
                if (!str_contains($path, '.')) {
                    return class_exists($path) ? $path : null;
                }
                [$parent, $association] = explode('.', $path, 2);
                $parentClass = self::classOfAlias($queryBuilder, $parent, $depth + 1);
                if ($parentClass === null) {
                    return null;
                }
                $metadata = $queryBuilder->getEntityManager()->getClassMetadata($parentClass);

                return $metadata->hasAssociation($association) ? $metadata->getAssociationTargetClass($association) : null;
            }
        }

        return null;
    }
}
