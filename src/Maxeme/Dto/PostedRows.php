<?php

declare(strict_types=1);

namespace App\Maxeme\Dto;

use App\Service\TextInput;

/**
 * Reads a form's repeated rows (lines[n][...], charges[n][...], jobs[n][...]) the same way
 * everywhere: rows that are not arrays are skipped, and every field goes through core's
 * TextInput::nullableString() — trimmed, blank as null, a tampered array as absent, and control
 * characters such as NUL (which break the PDF) removed; tabs and newlines are kept.
 */
final class PostedRows
{
    /**
     * @param mixed $rows the posted array (anything else counts as no rows)
     *
     * @return list<array<array-key, mixed>>
     */
    public static function of(mixed $rows): array
    {
        return array_values(array_filter(is_array($rows) ? $rows : [], 'is_array'));
    }

    /**
     * A reader for one row: $value('name') is that field as text, or null when blank or absent.
     *
     * @param array<array-key, mixed> $row
     *
     * @return \Closure(string): ?string
     */
    public static function reader(array $row): \Closure
    {
        return static fn (string $key): ?string => TextInput::nullableString($row[$key] ?? null);
    }
}
