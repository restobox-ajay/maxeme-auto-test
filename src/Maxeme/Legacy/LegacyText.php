<?php

declare(strict_types=1);

namespace App\Maxeme\Legacy;

/**
 * Repairs the legacy database's double-encoded text. Most non-ASCII values there (Chinese names
 * and notes, mostly) are UTF-8 bytes that were read as MySQL "latin1" (Windows-1252) and saved as
 * UTF-8 again, so "引擎" is stored as "å¼•æ“Ž".
 *
 * The repair reverses that one step, and only keeps the result when it is valid UTF-8 and
 * different: correctly encoded text ("é", "Müller") does not survive the reversal as valid UTF-8,
 * so it is returned unchanged.
 */
final class LegacyText
{
    public static function repair(mixed $value): mixed
    {
        if (!is_string($value) || preg_match('/[^\x00-\x7F]/', $value) !== 1) {
            return $value;
        }

        $bytes = '';
        foreach (mb_str_split($value) as $character) {
            $byte = self::latin1Byte($character);
            if ($byte === null) {
                return $value; // not something a latin1 misreading could have produced
            }
            $bytes .= $byte;
        }

        if ($bytes === $value) {
            return $value;
        }

        // A legacy column that cut the text at 255 bytes can leave half a character at the end.
        for ($cut = 0; $cut <= 3; ++$cut) {
            $candidate = $cut === 0 ? $bytes : substr($bytes, 0, -$cut);
            if (mb_check_encoding($candidate, 'UTF-8')) {
                return $candidate;
            }
        }

        return $value;
    }

    /**
     * The byte MySQL's latin1 decodes to $character: Windows-1252, whose five undefined bytes
     * (0x81, 0x8D, 0x8F, 0x90, 0x9D) MySQL passes through as the C1 control characters.
     */
    private static function latin1Byte(string $character): ?string
    {
        $codepoint = mb_ord($character, 'UTF-8');
        if ($codepoint <= 0x7F || ($codepoint >= 0xA0 && $codepoint <= 0xFF) || in_array($codepoint, [0x81, 0x8D, 0x8F, 0x90, 0x9D], true)) {
            return chr($codepoint);
        }

        $byte = mb_convert_encoding($character, 'Windows-1252', 'UTF-8');

        return $byte !== '?' && strlen($byte) === 1 ? $byte : null;
    }
}
