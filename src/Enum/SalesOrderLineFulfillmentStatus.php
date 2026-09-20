<?php

declare(strict_types=1);

namespace App\Enum;

use App\Service\QuantityScale;

/**
 * How much of one order line the warehouse can actually send today (#548).
 *
 * Purely a reading of two numbers already on the line — its quantity and its backordered quantity —
 * and never set independently. SalesOrderLine::setBackorderedQuantity() recomputes it on every
 * write, which is what makes it a cache rather than a second opinion: there is no way to store a
 * status that disagrees with the arithmetic behind it.
 *
 * Stored as a plain VARCHAR for the same reason SalesOrderStatus is (see its docblock): a row
 * carrying a value this enum does not know must still hydrate, so every read goes through
 * tryFrom() and an unrecognised string reads as Fulfilled — the state every line was in before
 * this feature existed.
 */
enum SalesOrderLineFulfillmentStatus: string
{
    /** Nothing is backordered. Every line in the app was this before #548, and still is by default. */
    case Fulfilled = 'Fulfilled';

    /** Some of the line ships now, the rest waits for stock. */
    case PartiallyBackordered = 'Partially Backordered';

    /** None of it can ship yet. */
    case Backordered = 'Backordered';

    /** The only place the three cases are chosen, so a line's status can never be argued with. */
    public static function forQuantities(string|int|float $quantity, string|int|float $backordered): self
    {
        if (QuantityScale::compare($backordered, 0) <= 0) {
            return self::Fulfilled;
        }

        return QuantityScale::compare($backordered, $quantity) >= 0 ? self::Backordered : self::PartiallyBackordered;
    }
}
