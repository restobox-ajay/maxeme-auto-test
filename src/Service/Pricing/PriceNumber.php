<?php

declare(strict_types=1);

namespace App\Service\Pricing;

/**
 * The one question the pricing code asks of a string before treating it as money: "is this a price?"
 *
 * It exists because the answer is *not* `is_numeric()`. `is_numeric('1e3')` and `is_numeric('1E3')`
 * are both true, so before #464 an admin who typed `1e3` into a price or a rule-value field had it
 * silently stored as 1000.00 — three orders of magnitude away from anything they could have meant by
 * three keystrokes. Exponent notation is a programmer's spelling of a number; it is not a spelling
 * anyone uses for a price, and in a grid where every other cell is a plain decimal it is far more
 * likely to be a typo or a pasted identifier than an intention. So it is refused outright rather
 * than interpreted, and the admin gets the same "must be a valid number" message as for any other
 * unusable input.
 *
 * `stripos`, not `strpos`: `1E3` is exactly as numeric as `1e3` and must fail the same way. And the
 * test is on the RAW STRING, before any cast — `(float) '1e3'` is 1000.0 with nothing left to show
 * where it came from, so the check has to happen while the notation is still visible.
 *
 * Being a single function in a single place is the point. This rule is enforced at eight call sites
 * across the price grid's server side (SuggestedPriceCalculator, updatePrice(), bulkApplyPriceRule()
 * and the two suggested-price endpoints), and it is mirrored a ninth time in JavaScript by
 * numericValue() in templates/admin/product/prices.html.twig. Eight hand-written copies of
 * `is_numeric($x) && stripos($x, 'e') === false` is eight chances to write `strpos`, and the whole
 * of #464 is what happens when two copies of one rule drift apart.
 */
final class PriceNumber
{
    /**
     * Whether $value is a number this application will accept as a price or a rule value.
     *
     * Everything `is_numeric()` accepts except exponent notation: `'0'`, `'-5'`, `'12.50'`, `'.5'`,
     * `'12.'` and surrounding whitespace all pass, while `''`, `'12abc'`, `'0x1A'`, `'1e3'` and
     * `'1E3'` do not. Deliberately no sign or range test — a zero price and a negative price are
     * both things the grid can legitimately hold (#458, #462), and inventing a floor here would put
     * one back everywhere at once.
     */
    public static function isPrice(string $value): bool
    {
        return is_numeric($value) && stripos($value, 'e') === false;
    }
}
