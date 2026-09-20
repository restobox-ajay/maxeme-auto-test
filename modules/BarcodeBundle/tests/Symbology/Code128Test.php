<?php

declare(strict_types=1);

namespace BarcodeBundle\Tests\Symbology;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use BarcodeBundle\Symbology\Code128;

/**
 * A barcode that scans as the wrong string is worse than one that does not scan, so the encoder is
 * checked two ways that fail differently:
 *
 *  1. **Structurally**, against the symbology's own rules — 107 patterns, six modules each summing
 *     to eleven, a seven-module stop. A single mistyped digit in the table breaks one of those.
 *  2. **By decoding what it encoded.** The decoder below is written against the same table, so it
 *     cannot prove the table is the real Code 128 — that is what the structural checks are for — but
 *     it does prove the checksum weighting, the set selection and the bar/space alternation, which
 *     is where the encoder can silently produce a valid symbol carrying the wrong value.
 */
final class Code128Test extends TestCase
{
    /** @return list<string> the pattern table, read back out of the encoder one symbol at a time */
    private function patterns(): array
    {
        $reflection = new \ReflectionClass(Code128::class);

        /** @var list<string> $patterns */
        $patterns = $reflection->getConstant('PATTERNS');

        return $patterns;
    }

    public function testTheTableIsTheShapeTheSymbologyRequires(): void
    {
        $patterns = $this->patterns();

        self::assertCount(107, $patterns, 'Code 128 has 103 data values, 3 start codes and a stop.');

        foreach (\array_slice($patterns, 0, 106) as $index => $pattern) {
            self::assertSame(6, \strlen($pattern), sprintf('symbol %d must be six modules', $index));
            self::assertSame(11, array_sum(str_split($pattern)), sprintf('symbol %d must span eleven modules', $index));
        }

        self::assertSame(7, \strlen($patterns[106]), 'the stop pattern is seven modules');
        self::assertSame(13, array_sum(str_split($patterns[106])), 'the stop pattern spans thirteen modules');

        self::assertCount(107, array_unique($patterns), 'two symbols sharing a pattern would be undecodable');
    }

    public function testTheChecksumIsPositionWeightedFromTheStartCode(): void
    {
        // Set B, so each character is ASCII − 32: A=33, B=34, C=35.
        $symbols = Code128::symbols('ABC');

        self::assertSame(104, $symbols[0], 'Set B start code');
        self::assertSame([33, 34, 35], \array_slice($symbols, 1, 3));
        self::assertSame(106, $symbols[5], 'stop');

        // (104 + 1×33 + 2×34 + 3×35) mod 103 = (104 + 33 + 68 + 105) mod 103 = 310 mod 103 = 1
        self::assertSame((104 + 33 + 68 + 105) % 103, $symbols[4]);
    }

    public function testAnAllDigitEvenLengthValueUsesSetCAndHalvesTheSymbolCount(): void
    {
        $setB = Code128::symbols('ABCDEF');
        $setC = Code128::symbols('123456');

        self::assertSame(105, $setC[0], 'Set C start code');
        self::assertSame([12, 34, 56], \array_slice($setC, 1, 3), 'two digits per symbol');
        self::assertCount(9, $setB, 'start + 6 data + checksum + stop for Set B');
        self::assertCount(6, $setC, 'start + 3 data + checksum + stop for Set C');
    }

    public function testAnOddLengthDigitStringStaysInSetB(): void
    {
        // Set C encodes PAIRS, so an odd count cannot be expressed without a mid-string switch —
        // which this deliberately does not do.
        self::assertSame(104, Code128::symbols('12345')[0]);
    }

    #[DataProvider('values')]
    public function testEncodingThenDecodingReturnsTheOriginalValue(string $value): void
    {
        self::assertSame($value, $this->decode(Code128::modules($value)));
    }

    /** @return iterable<string, array{string}> */
    public static function values(): iterable
    {
        yield 'a bin code' => ['A01-02-03'];
        yield 'a SKU' => ['WIDGET-100'];
        yield 'a namespaced lot' => ['LOT-4172'];
        yield 'a serial with mixed case' => ['sn-Ab99xZ'];
        yield 'an even digit run, which takes Set C' => ['012345678902'];
        yield 'an odd digit run, which does not' => ['0123456789'];
        yield 'the printable extremes' => [' ~'];
    }

    public function testAnUnencodableValueIsRefusedRatherThanTransliterated(): void
    {
        self::assertFalse(Code128::isEncodable(''));
        self::assertFalse(Code128::isEncodable("TAB\there"));
        self::assertFalse(Code128::isEncodable('café'));

        $this->expectException(\InvalidArgumentException::class);
        Code128::symbols('café');
    }

    public function testBarsAndModulesAgreeOnWhereEverythingIs(): void
    {
        $value = 'A01-02-03';
        $modules = Code128::modules($value);
        $bars = Code128::bars($value);

        self::assertSame(array_sum($modules), Code128::widthInModules($value));
        // Bars are the even-indexed elements, and a Code 128 symbol always starts and ends on one.
        self::assertCount((int) ceil(\count($modules) / 2), $bars);
        self::assertSame(0, $bars[0][0], 'the first bar starts at module zero');
    }

    /**
     * Reads module widths back into a string, the way a scanner does: chunk into symbols, look each
     * one up, then undo the set encoding.
     *
     * @param list<int> $modules
     */
    private function decode(array $modules): string
    {
        $patterns = $this->patterns();
        $lookup = array_flip($patterns);

        $symbols = [];
        $cursor = 0;
        while ($cursor < \count($modules)) {
            // Six modules per symbol, except the stop, which is the seven remaining at the end.
            $width = ($cursor + 7 === \count($modules)) ? 7 : 6;
            $chunk = implode('', \array_slice($modules, $cursor, $width));

            self::assertArrayHasKey($chunk, $lookup, sprintf('module run %s is not a Code 128 symbol', $chunk));
            $symbols[] = $lookup[$chunk];
            $cursor += $width;
        }

        self::assertSame(106, array_pop($symbols), 'every symbol run ends with the stop pattern');

        $checksum = array_pop($symbols);
        $start = array_shift($symbols);

        $expected = $start;
        foreach ($symbols as $position => $symbol) {
            $expected += ($position + 1) * $symbol;
        }
        self::assertSame($expected % 103, $checksum, 'the checksum did not verify');

        if ($start === 105) {
            return implode('', array_map(static fn (int $s): string => sprintf('%02d', $s), $symbols));
        }

        return implode('', array_map(static fn (int $s): string => \chr($s + 32), $symbols));
    }
}
