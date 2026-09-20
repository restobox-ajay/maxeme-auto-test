<?php

declare(strict_types=1);

namespace BarcodeBundle\Symbology;

/**
 * Code 128, as bar widths (#552).
 *
 * ## Why this is written here rather than pulled in
 *
 * A barcode symbology is a lookup table and a modulo-103 checksum. The whole of it is below, it is
 * testable without a printer, and the alternative is a dependency whose only job is to hold the same
 * 107 rows. `Deno only, no npm` applies to JavaScript; the same instinct applies here — this is not
 * enough code to be worth owning someone else's release cycle for.
 *
 * ## Set B, with a Set C shortcut for all-digit values
 *
 * Set B covers printable ASCII 32–126, which is every SKU, bin code, lot id and serial this app can
 * produce. An all-digit value of even length is encoded in Set C instead, two digits per symbol,
 * because bin codes and lot ids are frequently numeric and halving the symbol width is the
 * difference between a label that fits a 50 mm die-cut and one that does not.
 *
 * There is deliberately no mid-string switching between sets. It saves modules on mixed values and
 * it is where every hand-rolled Code 128 implementation goes wrong; a slightly wider symbol is a
 * cost nobody can measure, and a mis-encoded one is stock booked against the wrong bin.
 *
 * ## What it returns
 *
 * A list of module widths, alternating **bar, space, bar, space…**, always starting on a bar and
 * always ending on the stop pattern's final bar. The caller decides how wide a module is in
 * millimetres, which is the only decision that depends on the label stock.
 */
final class Code128
{
    /**
     * The 107 symbol patterns: six module widths each (bar, space, bar, space, bar, space) summing
     * to eleven, plus the seven-module stop pattern at index 106.
     *
     * Index 0–102 are the data values, 103–105 the three start codes, 106 the stop.
     */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312',
        '132212', '221213', '221312', '231212', '112232', '122132', '122231', '113222',
        '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131',
        '311222', '321122', '321221', '312212', '322112', '322211', '212123', '212321',
        '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121',
        '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321',
        '331121', '312113', '312311', '332111', '314111', '221411', '431111', '111224',
        '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114',
        '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112',
        '421211', '212141', '214121', '412121', '111143', '111341', '131141', '114113',
        '114311', '411113', '411311', '113141', '114131', '311141', '411131',
        '211412', '211214', '211232', '2331112',
    ];

    private const START_B = 104;
    private const START_C = 105;
    private const STOP = 106;

    /**
     * True when this value can be printed at all.
     *
     * Anything outside printable ASCII cannot be encoded in Set B, and a scanner that reads a
     * mangled label books stock against the wrong thing — so an unprintable value is refused here
     * rather than silently transliterated.
     */
    public static function isEncodable(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        return preg_match('/^[\x20-\x7E]+$/', $value) === 1;
    }

    /**
     * The symbol values for $value, checksum and stop included.
     *
     * Exposed separately from modules() because the checksum is the part worth asserting in a test:
     * it is `(start + Σ position × value) mod 103`, and getting the position weighting wrong
     * produces a barcode that scans as a different string rather than as nothing at all.
     *
     * @return list<int>
     */
    public static function symbols(string $value): array
    {
        if (!self::isEncodable($value)) {
            throw new \InvalidArgumentException(sprintf(
                'Code 128 covers printable ASCII only; %s cannot be printed as a barcode.',
                var_export($value, true),
            ));
        }

        $useSetC = preg_match('/^\d+$/', $value) === 1 && \strlen($value) % 2 === 0;

        $codes = [];
        if ($useSetC) {
            for ($i = 0; $i < \strlen($value); $i += 2) {
                $codes[] = (int) substr($value, $i, 2);
            }
        } else {
            foreach (str_split($value) as $character) {
                // Set B: value = ASCII − 32, so a space is 0 and DEL−1 (126, '~') is 94.
                $codes[] = \ord($character) - 32;
            }
        }

        $start = $useSetC ? self::START_C : self::START_B;

        $checksum = $start;
        foreach ($codes as $position => $code) {
            $checksum += ($position + 1) * $code;
        }

        return [$start, ...$codes, $checksum % 103, self::STOP];
    }

    /**
     * Module widths, alternating bar/space and starting on a bar.
     *
     * @return list<int>
     */
    public static function modules(string $value): array
    {
        $modules = [];

        foreach (self::symbols($value) as $symbol) {
            foreach (str_split(self::PATTERNS[$symbol]) as $width) {
                $modules[] = (int) $width;
            }
        }

        return $modules;
    }

    /**
     * The bars only, as `[offsetInModules, widthInModules]` pairs — which is what a renderer
     * actually draws. Spaces are the gaps between them and need no elements of their own.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function bars(string $value): array
    {
        $bars = [];
        $offset = 0;

        foreach (self::modules($value) as $index => $width) {
            if ($index % 2 === 0) {
                $bars[] = [$offset, $width];
            }

            $offset += $width;
        }

        return $bars;
    }

    /** Total width of the symbol in modules, quiet zones excluded. */
    public static function widthInModules(string $value): int
    {
        return array_sum(self::modules($value));
    }
}
