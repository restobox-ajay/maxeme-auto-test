<?php

declare(strict_types=1);

namespace BarcodeBundle\Symbology;

/**
 * QR Code (ISO/IEC 18004 Model 2), as a grid of dark and light modules (#608).
 *
 * ## Why a second symbology at all
 *
 * Code 128 is a line of bars, so its width grows with the length of the value and its only
 * redundancy is one check character. On a 50 × 25 mm bin label that runs out of room fast, which is
 * exactly what `LabelTemplate::MIN_MODULE_MM` and the renderer's shrink-to-fit logic are fighting.
 *
 * A QR is square, so length costs area rather than width; it reads at any rotation, which is what a
 * phone camera held over a shelf actually does; and it carries real Reed-Solomon error correction,
 * so it survives a crease, a scuff and a smear of grease that would kill a linear code. Sortly
 * defaults to QR for those reasons and they are the right reasons.
 *
 * **Code 128 stays the default and is not going anywhere.** A keyboard-wedge scanner on a receiving
 * line reads a linear code faster and more reliably than a camera reads a QR, and the wedge is the
 * hardware a receiving desk already owns. Both, chosen per template — never one replacing the other.
 *
 * ## Why this is written here rather than pulled in
 *
 * Same answer as {@see Code128}, one size up. A QR encoder is a lookup of six capacity numbers, a
 * Reed-Solomon remainder over GF(256), a fixed pattern layout and eight masks scored by four
 * published rules. All of it is below, all of it is testable without a printer, and the only
 * alternative was a Composer dependency whose release cycle this project would then own. There is a
 * round-trip test that decodes what this produces back to the string it was given, which is the only
 * assertion that actually proves a QR encoder works.
 *
 * ## The deliberate limits: byte mode, level M, versions 1 to 6
 *
 *  - **Byte mode only.** Alphanumeric mode is denser for upper-case-and-digits values and would save
 *    a version on some codes. It is also a second encoder to keep correct, and the values here are
 *    at most 64 characters — where the saving is one version of a symbol that is already small.
 *  - **Error correction level M**, about 15% recoverable. L is not enough for a label that lives on
 *    a carton in a warehouse; Q and H buy robustness this does not need at the cost of area on
 *    stock that is short of it.
 *  - **Versions 1 to 6**, 21 × 21 up to 41 × 41 modules, holding 14 to 106 bytes. `product_barcode.code`
 *    is VARCHAR(64) and every other thing a label encodes is shorter, so version 6 is well past
 *    anything this application can produce. Stopping at 6 is also why there is no version
 *    information block in the code below: that field only exists from version 7 up, and a field
 *    that cannot occur is a field that cannot be got wrong.
 *
 * ## What it returns
 *
 * A square grid of booleans indexed `[row][column]`, true meaning a dark module, with NO quiet zone
 * — the caller adds the four-module margin the standard requires, because how much white is
 * available is a property of the label stock and not of the symbol.
 */
final class Qr
{
    /** Error correction level M: bits `00` in the format information. */
    private const ECC_FORMAT_BITS = 0b00;

    /** The largest symbol this encoder will build. Version 7 adds a version information block. */
    public const MAX_VERSION = 6;

    /** Total codewords (data + error correction) in each version. */
    private const TOTAL_CODEWORDS = [1 => 26, 2 => 44, 3 => 70, 4 => 100, 5 => 134, 6 => 172];

    /** Error-correction codewords per block, at level M. */
    private const ECC_PER_BLOCK = [1 => 10, 2 => 16, 3 => 26, 4 => 18, 5 => 24, 6 => 16];

    /**
     * Blocks the data is split into, at level M.
     *
     * Every version from 1 to 6 has blocks of ONE size at this level — no short/long split — which
     * is the reason the interleaver below is six lines rather than thirty. It stops being true at
     * version 7, which is one more reason the ceiling is where it is.
     */
    private const BLOCKS = [1 => 1, 2 => 1, 3 => 1, 4 => 2, 5 => 2, 6 => 4];

    /**
     * Alignment pattern centre coordinates per version. Version 1 has none.
     *
     * A pattern is placed at every combination of these coordinates except the three that would sit
     * on top of a finder pattern, which for versions 2 to 6 leaves exactly one, in the bottom right.
     */
    private const ALIGNMENT = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34]];

    /** The two padding codewords, alternated to fill out the data capacity. Fixed by the standard. */
    private const PAD_CODEWORDS = [0xEC, 0x11];

    /** How many bytes fit in each version, at level M, in byte mode. */
    public static function capacity(int $version): int
    {
        // Four bits of mode indicator plus eight bits of character count, then whole bytes.
        return self::dataCodewords($version) - 2;
    }

    /** True when this value fits in a symbol this encoder can build. */
    public static function isEncodable(string $value): bool
    {
        return $value !== '' && \strlen($value) <= self::capacity(self::MAX_VERSION);
    }

    /** The smallest version that holds this value, or null when nothing here does. */
    public static function versionFor(string $value): ?int
    {
        for ($version = 1; $version <= self::MAX_VERSION; $version++) {
            if (\strlen($value) <= self::capacity($version)) {
                return $version;
            }
        }

        return null;
    }

    /** The symbol's width in modules, quiet zone excluded. */
    public static function sizeFor(int $version): int
    {
        return 17 + 4 * $version;
    }

    /**
     * The finished symbol: `[row][column]`, true where a module is dark.
     *
     * @return list<list<bool>>
     */
    public static function matrix(string $value): array
    {
        $version = self::versionFor($value);

        if ($version === null) {
            throw new \InvalidArgumentException(sprintf(
                'A QR of version %d holds %d bytes at error-correction level M; %s is %d.',
                self::MAX_VERSION,
                self::capacity(self::MAX_VERSION),
                var_export($value, true),
                \strlen($value),
            ));
        }

        $size = self::sizeFor($version);

        /** @var list<list<bool>> $modules */
        $modules = array_fill(0, $size, array_fill(0, $size, false));
        /** @var list<list<bool>> $isFunction */
        $isFunction = array_fill(0, $size, array_fill(0, $size, false));

        self::drawFunctionPatterns($modules, $isFunction, $version, $size);
        self::drawCodewords($modules, $isFunction, self::interleave(self::dataCodewords8($value, $version), $version), $size);

        // Every mask produces a decodable symbol; the score only picks the one least likely to
        // confuse a scanner with false finder patterns or large blank areas.
        $best = 0;
        $bestPenalty = \PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = self::withMask($modules, $isFunction, $mask, $size);
            self::drawFormatBits($candidate, $isFunction, $mask, $size);

            $penalty = self::penalty($candidate, $size);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $mask;
            }
        }

        $final = self::withMask($modules, $isFunction, $best, $size);
        self::drawFormatBits($final, $isFunction, $best, $size);

        return $final;
    }

    // ---------------------------------------------------------------- data

    private static function dataCodewords(int $version): int
    {
        return self::TOTAL_CODEWORDS[$version] - self::BLOCKS[$version] * self::ECC_PER_BLOCK[$version];
    }

    /**
     * The value as data codewords: mode, length, bytes, terminator, padding.
     *
     * @return list<int>
     */
    private static function dataCodewords8(string $value, int $version): array
    {
        $capacity = self::dataCodewords($version);
        $bits = '';

        // 0100 is byte mode. The character count is eight bits for every version up to 9.
        $bits .= '0100';
        $bits .= str_pad(decbin(\strlen($value)), 8, '0', \STR_PAD_LEFT);

        foreach (str_split($value) as $character) {
            $bits .= str_pad(decbin(\ord($character)), 8, '0', \STR_PAD_LEFT);
        }

        // Terminator: up to four zero bits, and never past the capacity.
        $bits .= str_repeat('0', min(4, $capacity * 8 - \strlen($bits)));
        // Then out to a whole codeword.
        $bits .= str_repeat('0', (8 - \strlen($bits) % 8) % 8);

        $codewords = [];
        foreach (str_split($bits, 8) as $byte) {
            $codewords[] = bindec($byte);
        }

        // The two pad codewords alternate. They are fixed by the standard, not arbitrary filler.
        for ($i = 0; \count($codewords) < $capacity; $i++) {
            $codewords[] = self::PAD_CODEWORDS[$i % 2];
        }

        return $codewords;
    }

    /**
     * Splits the data into blocks, appends each block's error correction, and interleaves the lot.
     *
     * Interleaving is what makes the error correction worth having: a scuff destroys a contiguous
     * run of modules, and spreading each block's codewords across the whole symbol turns one long
     * burst of damage into a few recoverable errors in every block rather than the total loss of one.
     *
     * @param list<int> $data
     *
     * @return list<int>
     */
    private static function interleave(array $data, int $version): array
    {
        $blockCount = self::BLOCKS[$version];
        $eccLength = self::ECC_PER_BLOCK[$version];
        $perBlock = \count($data) / $blockCount;

        $divisor = self::rsDivisor($eccLength);

        $blocks = [];
        $eccs = [];
        for ($b = 0; $b < $blockCount; $b++) {
            $block = \array_slice($data, (int) ($b * $perBlock), (int) $perBlock);
            $blocks[] = $block;
            $eccs[] = self::rsRemainder($block, $divisor);
        }

        $out = [];
        for ($i = 0; $i < (int) $perBlock; $i++) {
            foreach ($blocks as $block) {
                $out[] = $block[$i];
            }
        }
        for ($i = 0; $i < $eccLength; $i++) {
            foreach ($eccs as $ecc) {
                $out[] = $ecc[$i];
            }
        }

        return $out;
    }

    // ------------------------------------------------- Reed-Solomon over GF(256)

    /**
     * The generator polynomial's coefficients, highest power first, for `$degree` check codewords.
     *
     * Built as the product of (x − α^i) for i from 0, in the field GF(256) with the QR primitive
     * polynomial 0x11D. Worth knowing the shape of: for degree 10 — version 1 at level M — the
     * coefficients are α to the powers 0, 251, 67, 46, 61, 118, 70, 64, 94, 32, 45, which is the
     * published table and what the test asserts against.
     *
     * @return list<int>
     */
    private static function rsDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;

        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::gfMultiply($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::gfMultiply($root, 2);
        }

        return $result;
    }

    /**
     * @param list<int> $data
     * @param list<int> $divisor
     *
     * @return list<int>
     */
    private static function rsRemainder(array $data, array $divisor): array
    {
        $degree = \count($divisor);
        $result = array_fill(0, $degree, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ $result[0];
            array_shift($result);
            $result[] = 0;

            for ($i = 0; $i < $degree; $i++) {
                $result[$i] ^= self::gfMultiply($divisor[$i], $factor);
            }
        }

        return $result;
    }

    /** Carry-less multiply modulo the QR field's primitive polynomial, x^8 + x^4 + x^3 + x^2 + 1. */
    private static function gfMultiply(int $x, int $y): int
    {
        $z = 0;

        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0x1FF;
            $z ^= (($y >> $i) & 1) * $x;
            $z &= 0x1FF;
        }

        return $z & 0xFF;
    }

    // ---------------------------------------------------------------- layout

    /**
     * @param list<list<bool>> $modules
     * @param list<list<bool>> $isFunction
     */
    private static function drawFunctionPatterns(array &$modules, array &$isFunction, int $version, int $size): void
    {
        // Timing patterns: the alternating row and column at index 6 that tell a scanner how wide a
        // module is once perspective has stretched the symbol.
        for ($i = 0; $i < $size; $i++) {
            self::setFunction($modules, $isFunction, 6, $i, $i % 2 === 0);
            self::setFunction($modules, $isFunction, $i, 6, $i % 2 === 0);
        }

        // The three finder patterns, with their separators.
        self::drawFinder($modules, $isFunction, 3, 3);
        self::drawFinder($modules, $isFunction, 3, $size - 4);
        self::drawFinder($modules, $isFunction, $size - 4, 3);

        // Alignment patterns, skipping the three positions that would land on a finder.
        $centres = self::ALIGNMENT[$version];
        $last = \count($centres) - 1;
        foreach ($centres as $i => $row) {
            foreach ($centres as $j => $column) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                    continue;
                }
                self::drawAlignment($modules, $isFunction, $row, $column);
            }
        }

        // Reserve the format information area, and set the one module that is always dark.
        self::drawFormatBits($modules, $isFunction, 0, $size, true);
    }

    /**
     * @param list<list<bool>> $modules
     * @param list<list<bool>> $isFunction
     */
    private static function drawFinder(array &$modules, array &$isFunction, int $centreRow, int $centreColumn): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                // Chebyshev distance from the centre: 0-2 dark, 3 light (the ring), 4 the separator.
                $distance = max(abs($dx), abs($dy));
                $row = $centreRow + $dy;
                $column = $centreColumn + $dx;

                if ($row >= 0 && $row < \count($modules) && $column >= 0 && $column < \count($modules)) {
                    self::setFunction($modules, $isFunction, $row, $column, $distance !== 2 && $distance !== 4);
                }
            }
        }
    }

    /**
     * @param list<list<bool>> $modules
     * @param list<list<bool>> $isFunction
     */
    private static function drawAlignment(array &$modules, array &$isFunction, int $centreRow, int $centreColumn): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                self::setFunction(
                    $modules,
                    $isFunction,
                    $centreRow + $dy,
                    $centreColumn + $dx,
                    max(abs($dx), abs($dy)) !== 1,
                );
            }
        }
    }

    /**
     * The fifteen bits of format information, twice, plus the module that is always dark.
     *
     * Five data bits — two for the error-correction level, three for the mask — extended by a
     * BCH(15,5) code and then XORed with 0b101010000010010. The XOR exists so that the all-zero
     * format (level M, mask 0) does not produce fifteen light modules, which a scanner would read as
     * part of the quiet zone.
     *
     * @param list<list<bool>> $modules
     * @param list<list<bool>> $isFunction
     * @param bool             $reserveOnly when true this is the reservation pass during function
     *                                      pattern layout: the area is marked as function modules so
     *                                      data never lands on it, and the bits written now are
     *                                      overwritten once a mask has been chosen
     */
    private static function drawFormatBits(array &$modules, array &$isFunction, int $mask, int $size, bool $reserveOnly = false): void
    {
        $bits = self::formatBits($mask);

        $set = static function (int $row, int $column, bool $dark) use (&$modules, &$isFunction, $reserveOnly): void {
            $modules[$row][$column] = $dark;
            if ($reserveOnly) {
                $isFunction[$row][$column] = true;
            }
        };

        // First copy, around the top-left finder.
        for ($i = 0; $i <= 5; $i++) {
            $set($i, 8, self::bit($bits, $i));
        }
        $set(7, 8, self::bit($bits, 6));
        $set(8, 8, self::bit($bits, 7));
        $set(8, 7, self::bit($bits, 8));
        for ($i = 9; $i < 15; $i++) {
            $set(8, 14 - $i, self::bit($bits, $i));
        }

        // Second copy, split between the top-right and bottom-left finders, so the format survives
        // damage to any one corner.
        for ($i = 0; $i < 8; $i++) {
            $set(8, $size - 1 - $i, self::bit($bits, $i));
        }
        for ($i = 8; $i < 15; $i++) {
            $set($size - 15 + $i, 8, self::bit($bits, $i));
        }

        // Always dark, at (4 × version + 9, 8). It carries no information; it is a fixed reference.
        $set($size - 8, 8, true);
    }

    /** The fifteen-bit format value for one mask at level M. */
    public static function formatBits(int $mask): int
    {
        $data = (self::ECC_FORMAT_BITS << 3) | ($mask & 7);

        $remainder = $data;
        for ($i = 0; $i < 10; $i++) {
            $remainder = ($remainder << 1) ^ ((($remainder >> 9) & 1) * 0x537);
        }

        return (($data << 10) | ($remainder & 0x3FF)) ^ 0x5412;
    }

    /**
     * Lays the interleaved codewords into the symbol, two columns at a time, right to left.
     *
     * The zig-zag is not decoration: it is how the standard says a scanner reads the modules back,
     * and getting the direction or the column pairing wrong produces a symbol that looks exactly
     * right and decodes to nothing.
     *
     * @param list<list<bool>> $modules
     * @param list<list<bool>> $isFunction
     * @param list<int>        $codewords
     */
    private static function drawCodewords(array &$modules, array &$isFunction, array $codewords, int $size): void
    {
        $bitIndex = 0;
        $totalBits = \count($codewords) * 8;

        for ($right = $size - 1; $right >= 1; $right -= 2) {
            // Column 6 is the vertical timing pattern; the pairing steps over it rather than
            // through it.
            if ($right === 6) {
                $right = 5;
            }

            for ($vertical = 0; $vertical < $size; $vertical++) {
                for ($j = 0; $j < 2; $j++) {
                    $column = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $row = $upward ? $size - 1 - $vertical : $vertical;

                    if (!$isFunction[$row][$column] && $bitIndex < $totalBits) {
                        $modules[$row][$column] = (($codewords[$bitIndex >> 3] >> (7 - ($bitIndex & 7))) & 1) === 1;
                        $bitIndex++;
                    }
                    // Anything past the last codeword is a remainder bit: left light here, and then
                    // masked like any other data module, which is what the standard asks for.
                }
            }
        }
    }

    /**
     * @param list<list<bool>> $modules
     * @param list<list<bool>> $isFunction
     *
     * @return list<list<bool>>
     */
    private static function withMask(array $modules, array $isFunction, int $mask, int $size): array
    {
        for ($row = 0; $row < $size; $row++) {
            for ($column = 0; $column < $size; $column++) {
                if ($isFunction[$row][$column]) {
                    continue;
                }

                $invert = match ($mask) {
                    0 => ($column + $row) % 2 === 0,
                    1 => $row % 2 === 0,
                    2 => $column % 3 === 0,
                    3 => ($column + $row) % 3 === 0,
                    4 => (intdiv($column, 3) + intdiv($row, 2)) % 2 === 0,
                    5 => ($column * $row) % 2 + ($column * $row) % 3 === 0,
                    6 => (($column * $row) % 2 + ($column * $row) % 3) % 2 === 0,
                    default => ((($column + $row) % 2) + ($column * $row) % 3) % 2 === 0,
                };

                if ($invert) {
                    $modules[$row][$column] = !$modules[$row][$column];
                }
            }
        }

        return $modules;
    }

    // ---------------------------------------------------------------- masking score

    /**
     * The four published penalty rules. Lower is better.
     *
     * @param list<list<bool>> $modules
     */
    private static function penalty(array $modules, int $size): int
    {
        $penalty = 0;

        // Rule 1: runs of five or more of one colour, in every row and every column.
        for ($i = 0; $i < $size; $i++) {
            $penalty += self::runPenalty(self::row($modules, $i));
            $penalty += self::runPenalty(self::column($modules, $i, $size));
        }

        // Rule 2: every 2 × 2 block of one colour.
        for ($row = 0; $row < $size - 1; $row++) {
            for ($column = 0; $column < $size - 1; $column++) {
                $colour = $modules[$row][$column];
                if ($colour === $modules[$row][$column + 1]
                    && $colour === $modules[$row + 1][$column]
                    && $colour === $modules[$row + 1][$column + 1]
                ) {
                    $penalty += 3;
                }
            }
        }

        // Rule 3: anything that looks like a finder pattern in the data area. This is the expensive
        // one to get wrong on a real scanner, which is why it costs 40 a time.
        for ($i = 0; $i < $size; $i++) {
            $penalty += 40 * self::finderLikeCount(self::row($modules, $i));
            $penalty += 40 * self::finderLikeCount(self::column($modules, $i, $size));
        }

        // Rule 4: how far the proportion of dark modules is from half.
        $dark = 0;
        foreach ($modules as $row) {
            foreach ($row as $module) {
                if ($module) {
                    $dark++;
                }
            }
        }
        $total = $size * $size;
        $deviation = (int) floor(abs($dark * 100 / $total - 50));

        return $penalty + 10 * intdiv($deviation, 5);
    }

    /**
     * @param list<list<bool>> $modules
     *
     * @return list<bool>
     */
    private static function row(array $modules, int $index): array
    {
        return $modules[$index];
    }

    /**
     * @param list<list<bool>> $modules
     *
     * @return list<bool>
     */
    private static function column(array $modules, int $index, int $size): array
    {
        $column = [];
        for ($row = 0; $row < $size; $row++) {
            $column[] = $modules[$row][$index];
        }

        return $column;
    }

    /** @param list<bool> $line */
    private static function runPenalty(array $line): int
    {
        $penalty = 0;
        $run = 1;

        for ($i = 1, $n = \count($line); $i < $n; $i++) {
            if ($line[$i] === $line[$i - 1]) {
                $run++;

                continue;
            }

            if ($run >= 5) {
                $penalty += 3 + ($run - 5);
            }
            $run = 1;
        }

        return $run >= 5 ? $penalty + 3 + ($run - 5) : $penalty;
    }

    /**
     * How many times the 1:1:3:1:1 finder proportion, with four light modules beside it, occurs.
     *
     * @param list<bool> $line
     */
    private static function finderLikeCount(array $line): int
    {
        $pattern = [true, false, true, true, true, false, true];
        $light = [false, false, false, false];
        $count = 0;
        $n = \count($line);

        for ($i = 0; $i + 7 <= $n; $i++) {
            if (\array_slice($line, $i, 7) !== $pattern) {
                continue;
            }

            $before = $i >= 4 && \array_slice($line, $i - 4, 4) === $light;
            $after = $i + 11 <= $n && \array_slice($line, $i + 7, 4) === $light;

            if ($before || $after) {
                $count++;
            }
        }

        return $count;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param list<list<bool>> $modules
     * @param list<list<bool>> $isFunction
     */
    private static function setFunction(array &$modules, array &$isFunction, int $row, int $column, bool $dark): void
    {
        $modules[$row][$column] = $dark;
        $isFunction[$row][$column] = true;
    }

    private static function bit(int $value, int $index): bool
    {
        return (($value >> $index) & 1) === 1;
    }
}
