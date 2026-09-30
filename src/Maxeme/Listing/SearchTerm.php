<?php

declare(strict_types=1);

namespace App\Maxeme\Listing;

use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;

/**
 * A sidebar search box's text (`find`), split into words: every word has to match somewhere, so
 * "vivian chang" finds VIVIAN CHANG whichever field holds each half.
 */
final class SearchTerm
{
    public const PARAM = 'find';

    /** Characters a phone number is typed with, dropped before comparing digits. */
    private const PHONE_PUNCTUATION = ['-', ' ', '(', ')', '.', '+'];

    /** @param list<string> $words lower-cased */
    private function __construct(
        public readonly string $text,
        public readonly array $words,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return self::of((string) $request->query->get(self::PARAM, ''));
    }

    public static function of(string $text): self
    {
        $text = trim($text);
        $words = preg_split('/\s+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return new self($text, array_values(array_unique($words)));
    }

    public function isEmpty(): bool
    {
        return $this->words === [];
    }

    /** An invoice number as typed ("00015836", "#15836"), or null when the text is not one. */
    public function invoiceNumber(): ?int
    {
        $digits = ltrim(ltrim($this->text, '#'), '0');

        return ctype_digit($digits) && $digits !== '' ? (int) $digits : null;
    }

    /**
     * Narrows $query to rows where every word matches: a case-insensitive "contains" on any of
     * $columns, a digits-only "contains" on any of $phoneColumns (for a word with 3+ digits), or,
     * for a numeric word, whatever $number(QueryBuilder, param name) adds (e.g. an invoice number).
     *
     * @param list<string> $columns
     * @param list<string> $phoneColumns
     * @param (callable(QueryBuilder, string): string)|null $number
     */
    public function apply(QueryBuilder $query, array $columns, array $phoneColumns = [], ?callable $number = null): void
    {
        foreach ($this->words as $i => $word) {
            $param = 'find_' . $i;
            $or = array_map(static fn (string $column): string => sprintf('LOWER(%s) LIKE :%s', $column, $param), $columns);
            $query->setParameter($param, '%' . $word . '%');

            $digits = preg_replace('/\D+/', '', $word) ?? '';
            if (strlen($digits) >= 3 && $phoneColumns !== []) {
                $query->setParameter($param . '_digits', '%' . $digits . '%');
                foreach ($phoneColumns as $column) {
                    $or[] = sprintf('%s LIKE :%s_digits', self::stripped($column), $param);
                }
            }

            if ($number !== null && ctype_digit(ltrim($word, '#0')) && ltrim($word, '#0') !== '') {
                $query->setParameter($param . '_number', (int) ltrim($word, '#0'));
                $or[] = $number($query, $param . '_number');
            }

            $query->andWhere($query->expr()->orX(...$or));
        }
    }

    private static function stripped(string $column): string
    {
        $sql = $column;
        foreach (self::PHONE_PUNCTUATION as $character) {
            $sql = sprintf("REPLACE(%s, '%s', '')", $sql, $character);
        }

        return $sql;
    }
}
