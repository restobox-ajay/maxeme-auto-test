<?php

declare(strict_types=1);

namespace App\Maxeme\Accounting;

use App\Service\QuantityScale;

/**
 * A line's quantity (hours of labour, a count of parts) as stored. It uses core's quantity
 * rounding: QuantityScale, at the "Quantity Decimal Places" setting (2 by default, at most 4).
 * The total is worked out from the stored quantity and then rounded to the cent (Money::times()):
 * 1.25 hours at $90.99 is $113.74 (113.7375 rounded).
 *
 * Trailing zeros go, so a form shows "1.5", not "1.50".
 */
final class Quantity
{
    public static function normalize(string|int|float|null $quantity): string
    {
        return QuantityScale::trim(QuantityScale::round($quantity));
    }
}
