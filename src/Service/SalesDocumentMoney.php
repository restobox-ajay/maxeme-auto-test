<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Where an order and a quote are allowed to round, which is: once, at the grand total.
 *
 * Everything on the way there — a line's qty × price, the running subtotal, the fee rows' sum, the
 * tax breakdown's total — is an INTERMEDIATE and is carried at SCALE places rather than snapped to
 * the cent. Only the figure a human is shown is rounded to 2.
 *
 * The two flows disagreed about this, which is the bug (#257). An order built its grand total from
 * the subtotal it had already stored — a decimal(12,2) column, so already rounded — while a quote
 * built its from the raw running float it had accumulated, and then rounded its own displayed
 * Subtotal separately. Several lines whose qty × price falls between cents were enough to land the
 * same basket a cent apart on the two documents, and to make the quote disagree with itself.
 *
 * SCALE is 8 rather than "as many as a float has" so the rounding point is a stated policy instead
 * of whatever error the last multiplication happened to leave behind. It is far enough below the
 * cent that no realistic basket can be pushed across a half-cent boundary by it, and far enough
 * above the float noise of a few thousand accumulated multiplications to absorb it.
 *
 * What is PERSISTED per line is unaffected: sales_order_line.subtotal and estimate_line.subtotal
 * are decimal(12,2) on both entities and still store the cent-rounded figure. This is about the
 * arithmetic between the row and the total, not about the columns.
 *
 * Deliberately not configurable. Making the policy switchable (round-at-line vs round-at-total) is
 * its own decision with its own migration, deferred to #258; this class exists so that when that
 * lands there is one place to change rather than four.
 */
final class SalesDocumentMoney
{
    /** Decimal places every figure between a line and the grand total is carried at. */
    public const SCALE = 8;

    /**
     * A figure that is on its way to the grand total and must not be snapped to the cent yet.
     *
     * Rounds at all — rather than returning $value untouched — because an accumulator that is never
     * rounded drifts: adding a few thousand qty × price products leaves binary-float residue in the
     * last places, and two callers summing the same lines in a different order would then disagree
     * at the 15th decimal. Rounding each step to a stated scale makes the running figure a function
     * of the inputs rather than of the addition order.
     */
    public static function intermediate(float $value): float
    {
        return round($value, self::SCALE);
    }
}
