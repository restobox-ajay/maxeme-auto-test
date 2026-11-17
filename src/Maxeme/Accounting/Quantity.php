<?php

declare(strict_types=1);

namespace App\Maxeme\Accounting;

use App\Service\QuantityScale;

/**
 * A line's quantity (hours of labour, a count of parts) as stored: core's quantity rounding
 * (QuantityScale, up to 4 decimals), never the 2 of money. Only a TOTAL is rounded to the cent
 * (Money::times()), so 1.333 hours at $90 is $119.97, not 1.33 × $90 = $119.70.
 *
 * Trailing zeros go, so a form shows "1.5", not "1.5000".
 */
final class Quantity
{
    public static function normalize(string|int|float|null $quantity): string
    {
        return QuantityScale::trim(QuantityScale::round($quantity));
    }
}
