<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Movement;

/**
 * The decimal arithmetic behind a pack conversion's cost (#22), in integer micro-units.
 *
 * ## Why integers and not floats, and not bcmath
 *
 * `product_core.cost_price` is `NUMERIC(18, 6)`. bcmath is not installed on this box or on the
 * servers, and floats cannot hold six decimal places of a four-figure cost without drifting — the
 * whole point of the round-trip guarantee below is that a break followed by an unbreak returns the
 * value EXACTLY, and "exactly" is not a claim a float can make.
 *
 * So every figure here is an integer count of micro-units (10^-6 of a currency unit) and every
 * operation is integer arithmetic. Formatting back to a string happens once, at the edge.
 *
 * ## Where the rounding lands, and why the round trip is exact
 *
 * **The case is the anchor in BOTH directions.** A case costs what `product_core.cost_price` says it
 * costs; the per-unit figure is derived from it by division and is the only figure that rounds.
 *
 *     unit cost = round-half-up( case cost / units per case ), at six decimal places
 *
 * A $25.00 case of twelve gives `2.083333` per unit, and twelve of those are `24.999996` — four
 * micro-units short of the case. That shortfall is the **rounding residue**, it is recorded on the
 * conversion row rather than hidden, and it is what makes the round trip exact:
 *
 *     break   1 case:   25.000000 leaves the case SKU,  24.999996 arrives at the unit SKU   (−0.000004)
 *     unbreak 1 case:   24.999996 leaves the unit SKU,  25.000000 arrives at the case SKU   (+0.000004)
 *     net                                                                                     0.000000
 *
 * The alternative — anchoring the unbreak on the unit figure and letting the case cost be
 * `12 × 2.083333` — would make the case worth `24.999996` after a round trip, and a case broken and
 * rebuilt a thousand times would lose four cents. Anchoring both directions on the case is what
 * removes the drift, and it is the same decision NetSuite's assembly build/unbuild makes: the
 * assembly's cost is the assembly's, and the components carry the remainder.
 *
 * ## What this class is NOT
 *
 * It is not inventory valuation. Valuation and COGS are parked (`#600`), and nothing here writes a
 * cost onto a product, a ledger or an account. It computes the figures one conversion moved, so that
 * the operation's own record says what it was worth — the same way `invoice_line` records a price
 * without there being a general ledger behind it.
 */
final class PackCost
{
    /** `NUMERIC(18, 6)` — the scale every money column in this application carries. */
    public const SCALE = 6;

    private const FACTOR = 1000000;

    /**
     * A decimal string as an integer count of micro-units.
     *
     * Anything that is not a number reads as zero rather than throwing: the only source is
     * `product_core.cost_price`, which is nullable and free-typed on the product form, and a
     * conversion must not 500 because somebody left a cost blank. A blank cost is handled ABOVE
     * this class — {@see PackConversion} stores nulls and the screen says the cost is unknown.
     */
    public static function toMicros(string $decimal): int
    {
        $decimal = trim($decimal);
        if ($decimal === '' || !is_numeric($decimal)) {
            return 0;
        }

        $negative = str_starts_with($decimal, '-');
        $digits = ltrim($decimal, '+-');

        [$whole, $fraction] = array_pad(explode('.', $digits, 2), 2, '');
        // Truncated rather than rounded: the source column holds six places, so a seventh can only
        // come from a hand-written fixture and is not a figure anybody typed.
        $fraction = substr(str_pad($fraction, self::SCALE, '0'), 0, self::SCALE);

        $micros = (int) ($whole === '' ? '0' : $whole) * self::FACTOR + (int) ($fraction === '' ? '0' : $fraction);

        return $negative ? -$micros : $micros;
    }

    /** Micro-units back to the string a `NUMERIC(18, 6)` column stores. */
    public static function format(int $micros): string
    {
        $sign = $micros < 0 ? '-' : '';
        $micros = abs($micros);

        return sprintf('%s%d.%06d', $sign, intdiv($micros, self::FACTOR), $micros % self::FACTOR);
    }

    /**
     * `round-half-up(numerator / divisor)` on integers, away from zero on a tie.
     *
     * Half-up rather than banker's rounding because this is money being divided into a known number
     * of equal parts and the residue is recorded either way — the tie-breaking rule changes which
     * micro-unit the residue is, not whether it is accounted for.
     */
    public static function divideRounded(int $numerator, int $divisor): int
    {
        if ($divisor === 0) {
            return 0;
        }

        $negative = ($numerator < 0) !== ($divisor < 0);
        $numerator = abs($numerator);
        $divisor = abs($divisor);

        $quotient = intdiv(2 * $numerator + $divisor, 2 * $divisor);

        return $negative ? -$quotient : $quotient;
    }

    /** The per-unit cost a case of $unitsPerCase at $caseCost gives, as a stored decimal string. */
    public static function perUnit(string $caseCost, int $unitsPerCase): string
    {
        return self::format(self::divideRounded(self::toMicros($caseCost), $unitsPerCase));
    }
}
