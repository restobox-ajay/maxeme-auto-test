<?php

declare(strict_types=1);

namespace App\Maxeme\Validation;

/**
 * Phone numbers and postal codes as the mockup has them (audit fix 34181b0):
 * - a phone is stored as the digits typed (an extension stays) and shown as 604-285-8200
 *   (1-604-285-8200, 285-8200); text that is not a phone ("N/A", "604… LLC") is left alone;
 * - a Canadian postal code is stored and shown as "V6X 2P9".
 */
final class ContactFormat
{
    /** "604-285-8200" for 10 digits (1-… for 11 starting with 1, 285-8200 for 7); anything else as it is. */
    public static function phone(?string $value): string
    {
        $text = trim((string) $value);
        if (preg_match('/^\+?[\d\s().-]+$/', $text) === 1) {
            return self::cut((string) preg_replace('/\D/', '', $text)) ?? $text;
        }
        // "6042858200 x12": the number formatted, the rest kept.
        if (preg_match('/^(\d{10})(?!\d)(.*)$/s', $text, $match) === 1) {
            return self::cut($match[1]) . $match[2];
        }

        return $text;
    }

    /** What to store for a typed phone: its digits when it is a phone of 7 or more digits, else the text as typed (null when blank). */
    public static function normalizePhone(?string $value): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^\+?[\d\s().-]+$/', $text) === 1 && strlen((string) preg_replace('/\D/', '', $text)) >= 7) {
            return (string) preg_replace('/\D/', '', $text);
        }

        return $text;
    }

    /** The last ten digits of a phone (7 digits or more), for spotting the same number written differently; '' otherwise. */
    public static function phoneKey(?string $value): string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $value);

        return strlen($digits) >= 7 ? substr($digits, -10) : '';
    }

    /** "V6X 2P9" for a Canadian postal code however it was typed; anything else as typed (null when blank). */
    public static function normalizePostalCode(?string $value): ?string
    {
        $text = strtoupper(trim((string) $value));
        if ($text === '') {
            return null;
        }

        return preg_match('/^([A-Z]\d[A-Z])[ -]?(\d[A-Z]\d)$/', $text, $match) === 1 ? $match[1] . ' ' . $match[2] : $text;
    }

    /** "88,000" → "88000" (a mileage written with thousands separators); anything else as typed. */
    public static function normalizeMileage(?string $value): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return preg_match('/^\d{1,3}(,\d{3})+$/', $text) === 1 ? str_replace(',', '', $text) : $text;
    }

    private static function cut(string $digits): ?string
    {
        return match (true) {
            strlen($digits) === 10 => sprintf('%s-%s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6)),
            strlen($digits) === 11 && $digits[0] === '1' => sprintf('1-%s-%s-%s', substr($digits, 1, 3), substr($digits, 4, 3), substr($digits, 7)),
            strlen($digits) === 7 => sprintf('%s-%s', substr($digits, 0, 3), substr($digits, 3)),
            default => null,
        };
    }
}
