<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Reproduces number1_inventory's "Size" column value from free text. Confirmed by comparing
 * live output against the reference app: its `size` column is NOT the human "255/45R19"-style
 * substring — it's the all-digits concatenation of the whole product text (e.g. "DP810
 * 255/45ZR19 104W" -> "8102554519104", model number and load/speed index included, which is why
 * a "255/45R19" and a "255/45ZR19" variant of the same product can show the identical value: the
 * "Z" speed-rating letter contributes no digit). Only produces a value at all when the text
 * actually looks like a tire spec (PATTERN) — otherwise every product with any digit in its name
 * would get a meaningless digit string in its "Size" column.
 *
 * PATTERN covers two distinct tire-size shapes: the metric/P-metric radial format
 * ("255/45R19", "235-65R16") and the light-truck "flotation" format ("33X12.50R20LT"), which
 * uses "X" instead of "/" as its separator and a decimal second number instead of a 2-digit
 * aspect ratio — a name like "AQQISHI AQSONE A/T 33X12.50R20LT 114Q" was falling through to no
 * match (and so an empty Size column) before this was added.
 */
final class TireSizeExtractor
{
    private const PATTERN = '/\d{2,3}[\/\-X]\d{1,2}(?:\.\d{1,2})?Z?R\d{2}/i';

    public function extract(string $text): ?string
    {
        if (preg_match(self::PATTERN, $text) !== 1) {
            return null;
        }

        $digits = (new TireNumberSearchMatcher())->extractDigits($text);

        return $digits !== '' ? $digits : null;
    }
}
