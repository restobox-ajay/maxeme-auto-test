<?php

declare(strict_types=1);

namespace App\Tests\Maxeme\Legacy;

use App\Maxeme\Legacy\LegacyText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegacyTextTest extends TestCase
{
    /** What the legacy database holds for $text: its UTF-8 bytes read as MySQL latin1 and re-encoded. */
    private static function doubleEncode(string $text): string
    {
        $out = '';
        foreach (str_split($text) as $byte) {
            $code = ord($byte);
            $out .= in_array($code, [0x81, 0x8D, 0x8F, 0x90, 0x9D], true) || $code < 0x80 || $code >= 0xA0
                ? mb_chr($code, 'UTF-8')
                : mb_convert_encoding($byte, 'UTF-8', 'Windows-1252');
        }

        return $out;
    }

    /** @return iterable<string, array{string}> */
    public static function texts(): iterable
    {
        yield 'Chinese note' => ['ENGINE OIL & FILTER REPLACEMENT (引擎機油及濾芯更換)'];
        yield 'Chinese name' => ['周先生'];
        yield 'a byte MySQL latin1 passes through (0x8F in 芯)' => ['濾芯'];
        yield 'accents' => ['Müller café'];
    }

    #[DataProvider('texts')]
    public function testRepairsDoubleEncodedText(string $text): void
    {
        self::assertSame($text, LegacyText::repair(self::doubleEncode($text)));
    }

    #[DataProvider('texts')]
    public function testLeavesCorrectTextAlone(string $text): void
    {
        self::assertSame($text, LegacyText::repair($text));
    }

    public function testDropsHalfACharacterLeftByATruncatedColumn(): void
    {
        $truncated = substr('車已牽', 0, -1); // the last character cut mid-sequence

        self::assertSame('車已', LegacyText::repair(self::doubleEncode($truncated)));
    }

    public function testLeavesNonStringsAndAsciiAlone(): void
    {
        self::assertNull(LegacyText::repair(null));
        self::assertSame(42, LegacyText::repair(42));
        self::assertSame('plain', LegacyText::repair('plain'));
    }
}
