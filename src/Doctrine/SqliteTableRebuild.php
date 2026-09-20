<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Connection;

/**
 * DROP COLUMN for SQLite older than 3.35 — production runs 3.26.
 *
 * Returns the statements for the classic rebuild: copy to a temp table, drop, recreate from the
 * table's own CREATE statement minus the column, copy back, recreate its indexes. The caller runs
 * them in a non-transactional migration between PRAGMA foreign_keys OFF and ON, because the pragma
 * is ignored inside a transaction and DROP TABLE would otherwise cascade into child tables.
 */
final class SqliteTableRebuild
{
    /**
     * @param array<string, string|null> $columns column name => replacement definition, or null to drop it
     *
     * @return list<string>
     */
    public static function statements(Connection $connection, string $table, array $columns): array
    {
        $create = (string) $connection->fetchOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);
        if ($create === '') {
            throw new \RuntimeException(sprintf('Table %s does not exist.', $table));
        }

        // SQLite stores the CREATE statement byte for byte, comments included, and several tables in
        // this chain were created with explanatory `--` lines between their columns. A comma inside
        // one of those splits the definition in the wrong place: `invoice_line` carries
        // "-- Deliberately NO foreign key to product_core, matching sales_order_line." and the
        // rebuild came out with a column called `matching`, which SQLite then refused to select.
        // Stripping them costs the recreated table its comments and is the only alternative to
        // parsing a definition that does not mean what it says.
        $create = self::stripComments($create);

        $open = strpos($create, '(');
        $close = strrpos($create, ')');
        $kept = [];
        $carried = [];
        foreach (self::splitTopLevel(substr($create, $open + 1, $close - $open - 1)) as $part) {
            $name = self::columnName($part);
            if ($name !== null && array_key_exists($name, $columns)) {
                if ($columns[$name] === null) {
                    continue;
                }
                $part = $columns[$name];
            }
            $kept[] = $part;
            if ($name !== null) {
                $carried[] = $name;
            }
        }
        foreach (array_keys($columns) as $name) {
            if (!in_array($name, $carried, true) && $columns[$name] !== null) {
                throw new \RuntimeException(sprintf('Column %s.%s does not exist.', $table, $name));
            }
        }

        $indexes = $connection->fetchFirstColumn(
            "SELECT sql FROM sqlite_master WHERE type IN ('index', 'trigger') AND tbl_name = ? AND sql IS NOT NULL",
            [$table],
        );
        $dropped = array_keys(array_filter($columns, static fn (?string $d): bool => $d === null));
        $indexes = array_filter($indexes, static function (string $sql) use ($dropped): bool {
            foreach ($dropped as $name) {
                if (preg_match('/\b' . preg_quote($name, '/') . '\b/', $sql) === 1) {
                    return false;
                }
            }

            return true;
        });

        $list = implode(', ', $carried);
        $temp = '__temp__' . $table;

        return [
            sprintf('CREATE TEMPORARY TABLE %s AS SELECT %s FROM %s', $temp, $list, $table),
            sprintf('DROP TABLE %s', $table),
            substr($create, 0, $open + 1) . implode(', ', $kept) . substr($create, $close),
            sprintf('INSERT INTO %s (%s) SELECT %s FROM %s', $table, $list, $list, $temp),
            sprintf('DROP TABLE %s', $temp),
            ...array_values($indexes),
        ];
    }

    /**
     * The statement with `--` and block comments removed, quotes respected.
     *
     * Deliberately not a full SQL parser: it tracks the same three quote characters
     * {@see splitTopLevel()} does, because a `--` inside a string literal is not a comment and a
     * DEFAULT value is the one place a string literal appears in a CREATE TABLE here.
     */
    private static function stripComments(string $sql): string
    {
        $out = '';
        $quote = null;
        $length = \strlen($sql);

        for ($i = 0; $i < $length; ++$i) {
            $char = $sql[$i];

            if ($quote !== null) {
                $out .= $char;
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $out .= $char;

                continue;
            }

            if ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
                $end = strpos($sql, "\n", $i);
                if ($end === false) {
                    break;
                }
                // The newline is kept so two definitions either side of a comment stay separated.
                $i = $end - 1;

                continue;
            }

            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    break;
                }
                $i = $end + 1;
                $out .= ' ';

                continue;
            }

            $out .= $char;
        }

        return $out;
    }

    /** @return list<string> */
    private static function splitTopLevel(string $body): array
    {
        $parts = [];
        $depth = 0;
        $quote = null;
        $current = '';
        foreach (str_split($body) as $char) {
            if ($quote !== null) {
                $quote = $char === $quote ? null : $quote;
            } elseif ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
            } elseif ($char === '(') {
                ++$depth;
            } elseif ($char === ')') {
                --$depth;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $parts[] = trim($current);

        return $parts;
    }

    private static function columnName(string $part): ?string
    {
        if (preg_match('/^(CONSTRAINT|PRIMARY|FOREIGN|UNIQUE|CHECK)\b/i', $part) === 1) {
            return null;
        }
        preg_match('/^["`\[]?([A-Za-z0-9_]+)/', $part, $m);

        return $m[1] ?? null;
    }
}
