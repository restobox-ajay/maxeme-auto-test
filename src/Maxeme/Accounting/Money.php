<?php

declare(strict_types=1);

namespace App\Maxeme\Accounting;

/**
 * Dollar amounts as the decimal strings the database holds ("12.50"), calculated in whole cents so
 * there is no float rounding. Rounding is half up, as the legacy JS roundToTwo() did.
 */
final class Money
{
    public static function toCents(string|int|float|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        return (int) round((float) $amount * 100);
    }

    public static function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /** An amount with more decimals (core's prices keep 6) to the cent, or null. */
    public static function rounded(?string $amount): ?string
    {
        return $amount !== null ? self::fromCents(self::toCents($amount)) : null;
    }

    /** $rate percent of $cents, rounded to the cent. */
    public static function percentOf(int $cents, int $rate): int
    {
        return (int) round($cents * $rate / 100);
    }
}
