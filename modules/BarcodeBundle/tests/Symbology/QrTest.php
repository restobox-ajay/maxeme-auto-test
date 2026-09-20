<?php

declare(strict_types=1);

namespace BarcodeBundle\Tests\Symbology;

use BarcodeBundle\Symbology\Qr;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A QR that does not decode is worse than no QR at all, so this decodes what the encoder produced.
 *
 * ## Why a decoder in the test rather than a list of expected matrices
 *
 * A hardcoded expected matrix proves the encoder still produces what it produced the day the test
 * was written. It does not prove that a scanner can read it — if the module placement were mirrored,
 * or the mask bits inverted, a snapshot test would happily lock the wrong answer in forever.
 *
 * {@see decode()} below is an independent reader: it rebuilds the function-pattern map from the
 * version, reads the mask out of the format information, undoes the mask, walks the zig-zag,
 * de-interleaves the blocks and parses the mode and length header. It shares no code with the
 * encoder — only the published tables both must agree with. If it reads back the string that went
 * in, the placement, the masking, the format information and the block interleaving are all right.
 *
 * The two things a round-trip CANNOT check are checked separately and against published values:
 *
 *  - {@see testTheFormatInformationMatchesThePublishedTable} — all eight of the level-M format
 *    strings, which are printed in ISO/IEC 18004 and reproduced in every reference implementation.
 *  - {@see testTheGeneratorPolynomialForTenCheckCodewordsIsTheOneInTheTables} — the Reed-Solomon
 *    generator for version 1 at level M. A decoder that does not verify parity would read back a
 *    string encoded with wrong parity bytes perfectly happily; a real scanner would not.
 */
final class QrTest extends TestCase
{
    /**
     * @return list<array{0: string}>
     */
    public static function values(): array
    {
        return [
            ['HELLO WORLD'],
            // A minted internal EAN-13, the commonest thing this application will ever put in a QR.
            ['2000123456787'],
            ['WIDGET-1'],
            ['A-01'],
            ['LOT-42'],
            // Every version boundary in both directions: the last value that fits, and the first
            // that does not. Versions 4 and 6 are where the block count changes from one to two and
            // from two to four, which is where an interleaver goes wrong.
            [str_repeat('X', 14)],
            [str_repeat('Y', 15)],
            [str_repeat('Z', 26)],
            [str_repeat('Q', 42)],
            [str_repeat('R', 43)],
            [str_repeat('S', 62)],
            [str_repeat('T', 63)],
            [str_repeat('U', 84)],
            [str_repeat('V', 85)],
            [str_repeat('W', 106)],
            // Punctuation and mixed case, because byte mode has to carry a vendor part number.
            ['Sortly-style/QR_9?x=1&y=2'],
        ];
    }

    #[DataProvider('values')]
    public function testEveryValueDecodesBackToItself(string $value): void
    {
        self::assertSame($value, self::decode(Qr::matrix($value)), sprintf(
            'a QR encoding %s did not read back as %s',
            var_export($value, true),
            var_export($value, true),
        ));
    }

    /**
     * The fifteen-bit format strings for error-correction level M, one per mask, exactly as they
     * appear in the standard's table.
     */
    public function testTheFormatInformationMatchesThePublishedTable(): void
    {
        $published = [
            0 => '101010000010010',
            1 => '101000100100101',
            2 => '101111001111100',
            3 => '101101101001011',
            4 => '100010111111001',
            5 => '100000011001110',
            6 => '100111110010111',
            7 => '100101010100000',
        ];

        foreach ($published as $mask => $expected) {
            self::assertSame(
                $expected,
                str_pad(decbin(Qr::formatBits($mask)), 15, '0', \STR_PAD_LEFT),
                sprintf('the format information for mask %d is wrong, so no scanner would find the mask', $mask),
            );
        }
    }

    /**
     * The version-1 level-M generator polynomial, as α exponents.
     *
     * Published as 0, 251, 67, 46, 61, 118, 70, 64, 94, 32, 45 — the leading α^0 is the implicit
     * monomial and the ten below are what the encoder stores. Wrong parity bytes produce a symbol
     * that a lenient decoder reads and a real scanner rejects, which is the one failure the
     * round-trip test cannot see.
     */
    public function testTheGeneratorPolynomialForTenCheckCodewordsIsTheOneInTheTables(): void
    {
        $divisor = self::call('rsDivisor', 10);

        [, $log] = self::galoisTables();

        self::assertSame(
            [251, 67, 46, 61, 118, 70, 64, 94, 32, 45],
            array_map(static fn (int $coefficient): int => $log[$coefficient], $divisor),
        );
    }

    public function testCapacityStopsWhereTheEncoderStops(): void
    {
        self::assertSame(14, Qr::capacity(1));
        self::assertSame(106, Qr::capacity(Qr::MAX_VERSION));
        self::assertTrue(Qr::isEncodable(str_repeat('A', 106)));
        self::assertFalse(Qr::isEncodable(str_repeat('A', 107)));
        self::assertFalse(Qr::isEncodable(''));
    }

    public function testAValueTooLongIsRefusedRatherThanTruncated(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Qr::matrix(str_repeat('A', 107));
    }

    public function testTheSymbolIsSquareAndGrowsFourModulesPerVersion(): void
    {
        self::assertSame(21, \count(Qr::matrix('SHORT')));
        self::assertSame(21, \count(Qr::matrix('SHORT')[0]));
        self::assertSame(25, \count(Qr::matrix(str_repeat('Y', 15))));
        self::assertSame(41, \count(Qr::matrix(str_repeat('W', 106))));
    }

    /** The fixed patterns a scanner locates the symbol by, before it reads a single data module. */
    public function testTheFixedPatternsAreWhereAScannerLooksForThem(): void
    {
        $modules = Qr::matrix('2000123456787');
        $size = \count($modules);

        self::assertTrue($modules[3][3], 'the top-left finder has a dark centre');
        self::assertFalse($modules[1][3], 'the finder ring is light');
        self::assertTrue($modules[0][0], 'the finder outline is dark');
        self::assertFalse($modules[3][7], 'the separator beside the finder is light');
        self::assertTrue($modules[6][8], 'the timing pattern starts dark');
        self::assertFalse($modules[6][9], 'and alternates');
        self::assertTrue($modules[$size - 8][8], 'the dark module at (4v+9, 8) is always dark');
    }

    // ------------------------------------------------------------------ the reader

    /**
     * An independent QR reader, sharing no code with the encoder.
     *
     * @param list<list<bool>> $modules
     */
    private static function decode(array $modules): string
    {
        $size = \count($modules);
        $version = intdiv($size - 17, 4);

        $isFunction = self::functionMap($size, $version);
        $mask = self::maskFrom($modules);

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

        $bits = '';
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }

            for ($vertical = 0; $vertical < $size; $vertical++) {
                for ($j = 0; $j < 2; $j++) {
                    $column = $right - $j;
                    $row = ((($right + 1) & 2) === 0) ? $size - 1 - $vertical : $vertical;

                    if (!$isFunction[$row][$column]) {
                        $bits .= $modules[$row][$column] ? '1' : '0';
                    }
                }
            }
        }

        $totals = [1 => 26, 2 => 44, 3 => 70, 4 => 100, 5 => 134, 6 => 172];
        $eccPerBlock = [1 => 10, 2 => 16, 3 => 26, 4 => 18, 5 => 24, 6 => 16];
        $blockCount = [1 => 1, 2 => 1, 3 => 1, 4 => 2, 5 => 2, 6 => 4];

        $codewords = [];
        foreach (str_split(substr($bits, 0, $totals[$version] * 8), 8) as $byte) {
            $codewords[] = bindec($byte);
        }

        $blocks = $blockCount[$version];
        $perBlock = intdiv($totals[$version] - $blocks * $eccPerBlock[$version], $blocks);

        $data = array_fill(0, $blocks, []);
        $index = 0;
        for ($i = 0; $i < $perBlock; $i++) {
            for ($b = 0; $b < $blocks; $b++) {
                $data[$b][$i] = $codewords[$index++];
            }
        }

        $stream = '';
        foreach ($data as $block) {
            foreach ($block as $codeword) {
                $stream .= str_pad(decbin($codeword), 8, '0', \STR_PAD_LEFT);
            }
        }

        if (bindec(substr($stream, 0, 4)) !== 0b0100) {
            return '<not byte mode>';
        }

        $length = bindec(substr($stream, 4, 8));

        $value = '';
        for ($i = 0; $i < $length; $i++) {
            $value .= \chr(bindec(substr($stream, 12 + $i * 8, 8)));
        }

        return $value;
    }

    /** @return list<list<bool>> */
    private static function functionMap(int $size, int $version): array
    {
        $map = array_fill(0, $size, array_fill(0, $size, false));

        $mark = static function (int $row, int $column) use (&$map, $size): void {
            if ($row >= 0 && $row < $size && $column >= 0 && $column < $size) {
                $map[$row][$column] = true;
            }
        };

        for ($i = 0; $i < $size; $i++) {
            $mark(6, $i);
            $mark($i, 6);
        }

        foreach ([[3, 3], [3, $size - 4], [$size - 4, 3]] as [$centreRow, $centreColumn]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $mark($centreRow + $dy, $centreColumn + $dx);
                }
            }
        }

        $alignment = [1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30], 6 => [6, 34]];
        $centres = $alignment[$version];
        $last = \count($centres) - 1;
        foreach ($centres as $i => $row) {
            foreach ($centres as $j => $column) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $mark($row + $dy, $column + $dx);
                    }
                }
            }
        }

        for ($i = 0; $i <= 8; $i++) {
            $mark($i, 8);
            $mark(8, $i);
        }
        for ($i = 0; $i < 8; $i++) {
            $mark(8, $size - 1 - $i);
        }
        for ($i = 8; $i < 15; $i++) {
            $mark($size - 15 + $i, 8);
        }

        return $map;
    }

    /** @param list<list<bool>> $modules */
    private static function maskFrom(array $modules): int
    {
        $bits = 0;
        for ($i = 0; $i <= 5; $i++) {
            if ($modules[$i][8]) {
                $bits |= 1 << $i;
            }
        }
        if ($modules[7][8]) {
            $bits |= 1 << 6;
        }
        if ($modules[8][8]) {
            $bits |= 1 << 7;
        }
        if ($modules[8][7]) {
            $bits |= 1 << 8;
        }
        for ($i = 9; $i < 15; $i++) {
            if ($modules[8][14 - $i]) {
                $bits |= 1 << $i;
            }
        }

        return (($bits ^ 0x5412) >> 10) & 7;
    }

    /** @return array{0: array<int, int>, 1: array<int, int>} */
    private static function galoisTables(): array
    {
        $exp = [];
        $log = [];
        $value = 1;

        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $value;
            $log[$value] = $i;
            $value <<= 1;
            if (($value & 0x100) !== 0) {
                $value ^= 0x11D;
            }
        }

        return [$exp, $log];
    }

    /** @return list<int> */
    private static function call(string $method, int $argument): array
    {
        $reflection = new \ReflectionMethod(Qr::class, $method);

        /** @var list<int> $result */
        $result = $reflection->invoke(null, $argument);

        return $result;
    }
}
