<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ProductCore;
use App\Service\Uom\LineDenomination;

/**
 * Global rounding helper - all qty backend must go through this. # of decimals capped by db column
 * and can be set by config and globally applied.
 */
final class QuantityScale
{
    public const SETTING_KEY = 'quantity_decimal_places';

    /**
     * The one place the working scale is stated: the ceiling for the setting, for a unit's step, and
     * the precision every intermediate sum is carried at. Nothing else should declare its own.
     */
    public const MAX_DECIMALS = LineDenomination::QUANTITY_SCALE;

    private static ?AppSettings $settings = null;

    /** Product id => its unit's step, so a loop over 20,000 rows reads each product's unit once. */
    private static array $steps = [];

    public static function bind(?AppSettings $settings): void
    {
        self::$settings = $settings;
        self::$steps = [];
    }

    public static function decimals(): int
    {
        $configured = trim((string) (self::$settings?->get(self::SETTING_KEY) ?? ''));
        $requested = is_numeric($configured) ? (int) $configured : self::MAX_DECIMALS;

        return max(0, min(self::MAX_DECIMALS, $requested));
    }

    /**
     * No product: the store-wide decimals. With one: that product's base unit overrides it.
     *
     * A unit carries a STEP rather than a decimal count — `each` is 1, `kg` is 0.001 — so it can
     * also say "halves only" or "packs of six", which a decimal count cannot.
     */
    public static function round(string|int|float|null $quantity, ?ProductCore $product = null): string
    {
        $step = self::stepFor($product);

        if ($step === null || bccomp($step, '0', self::MAX_DECIMALS) <= 0) {
            return bcround(self::str($quantity), self::decimals(), \RoundingMode::HalfAwayFromZero);
        }

        $steps = bcround(bcdiv(self::str($quantity), $step, self::MAX_DECIMALS), 0, \RoundingMode::HalfAwayFromZero);

        return bcmul($steps, $step, self::MAX_DECIMALS);
    }

    /**
     * Cached by product id. An unsaved product has no id to key on, so it is read every time —
     * there is one of those per request, not twenty thousand.
     */
    private static function stepFor(?ProductCore $product): ?string
    {
        $id = $product?->getId();

        if ($id === null) {
            return $product?->getBaseUnit()?->getRoundingPrecision();
        }

        if (!array_key_exists($id, self::$steps)) {
            self::$steps[$id] = $product->getBaseUnit()?->getRoundingPrecision();
        }

        return self::$steps[$id];
    }

    public static function compare(string|int|float|null $a, string|int|float|null $b): int
    {
        return bccomp(self::str($a), self::str($b), self::MAX_DECIMALS);
    }

    public static function add(string|int|float|null $a, string|int|float|null $b): string
    {
        return bcadd(self::str($a), self::str($b), self::MAX_DECIMALS);
    }

    public static function sub(string|int|float|null $a, string|int|float|null $b): string
    {
        return bcsub(self::str($a), self::str($b), self::MAX_DECIMALS);
    }

    public static function mul(string|int|float|null $a, string|int|float|null $b, int $scale = self::MAX_DECIMALS): string
    {
        return bcmul(self::str($a), self::str($b), $scale);
    }

    public static function isWhole(string|int|float|null $quantity): bool
    {
        $value = self::str($quantity);

        return bccomp($value, bcround($value, 0, \RoundingMode::TowardsZero), self::MAX_DECIMALS) === 0;
    }

    /** The column's spelling: 5 reads back as 5.0000, so a reloaded row compares equal to an unsaved one. */
    public static function canonical(string|int|float|null $quantity): string
    {
        return bcadd(self::str($quantity), '0', self::MAX_DECIMALS);
    }

    /** For prose: 5.0000 reads as 5. The stored figure keeps its places. */
    public static function trim(string $quantity): string
    {
        return str_contains($quantity, '.') ? (rtrim(rtrim($quantity, '0'), '.') ?: '0') : $quantity;
    }

    /**
     * Scaled-integer forms, kept while the last call sites still hold one. Prefer add/sub/compare:
     * they work on the decimal directly and cannot be scaled twice by mistake.
     */
    public static function unitsAtColumnScale(string|int|float|null $quantity): int
    {
        return (int) bcmul(self::str($quantity), bcpow('10', (string) self::MAX_DECIMALS), 0);
    }

    public static function formatAtColumnScale(int $units): string
    {
        return bcdiv((string) $units, bcpow('10', (string) self::MAX_DECIMALS), self::MAX_DECIMALS);
    }

    private static function str(string|int|float|null $quantity): string
    {
        if ($quantity === null) {
            return '0';
        }
        if (is_float($quantity)) {
            return sprintf('%.' . self::MAX_DECIMALS . 'F', $quantity);
        }

        // A non-numeric string (garbage input, not a caller bug — a string this class is asked to
        // canonicalise is not necessarily validated first) is treated as zero rather than handed to
        // bcmath, which throws a ValueError on anything that isn't a well-formed number.
        $value = trim((string) $quantity);

        return $value !== '' && is_numeric($value) ? $value : '0';
    }
}
