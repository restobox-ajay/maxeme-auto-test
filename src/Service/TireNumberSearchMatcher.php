<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Tire-size search: a user typing e.g. "235 65 16" or "23565R16" should still find a product
 * named "AQQISHI-AQSONE-A-T-LT235-65R16-121-119R" — the digits of the search string appear
 * consecutively in the digits of the product name, once every non-digit character (letters,
 * dashes, slashes, spaces) is stripped from both sides. Any non-empty digit string is eligible —
 * even a short one like a 2-digit rim size ("17") is expected to fuzzy-match broadly.
 */
final class TireNumberSearchMatcher
{
    private const MIN_SEARCH_DIGITS = 1;

    public function extractDigits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    public function isEligible(string $search): bool
    {
        return strlen($this->extractDigits($search)) >= self::MIN_SEARCH_DIGITS;
    }

    public function matches(string $search, string $productName): bool
    {
        $searchDigits = $this->extractDigits($search);
        if (strlen($searchDigits) < self::MIN_SEARCH_DIGITS) {
            return false;
        }

        $nameDigits = $this->extractDigits($productName);

        return $nameDigits !== '' && str_contains($nameDigits, $searchDigits);
    }
}
