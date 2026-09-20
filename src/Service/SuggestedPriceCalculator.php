<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ProductCore;
use App\Service\Pricing\PriceNumber;

/**
 * "Price Suggested" — an optional Number/Markup$/Markup% override on top of a product's real base
 * price (defaultPrice, falling back to originalPrice), shown as a side-by-side reference number on
 * the storefront. Never affects real/checkout pricing — same boundary the reference app
 * (number1_inventory's Product::getSuggestedEffectivePrice()) and the original
 * Number1SuggestedPriceBundle port both kept; see SUGGESTED_PRICE_CORE_ADAPTATION_PLAN.md phase 2.
 */
final class SuggestedPriceCalculator
{
    public const TYPE_NUMBER = 'Number';
    public const TYPE_MARKUP_DOLLAR = 'Markup$';
    public const TYPE_MARKUP_PERCENT = 'Markup%';

    /** The final "Price Suggested" number to show, or null if there's nothing to show at all. */
    public function getEffectivePrice(ProductCore $product): ?string
    {
        $base = $this->getBasePrice($product);
        $baseNumber = $base !== null ? (float) $base : null;

        $type = trim((string) ($product->getSuggestedPriceType() ?? ''));
        $rawValue = trim((string) ($product->getSuggestedPriceValue() ?? ''));
        // PriceNumber::isPrice() rather than is_numeric(): `1e3` is numeric to PHP and would become
        // a suggested price of 1000.00, which is not what three keystrokes in a money field mean
        // (#464). A rejected value is an ABSENT value here, exactly like `abc` — the branches below
        // fall back to the base price rather than inventing a figure.
        $value = PriceNumber::isPrice($rawValue) ? (float) $rawValue : null;

        // A blank type is not a rule — it is the absence of one. The admin never picked anything,
        // so there is nothing to apply and the product simply shows its own base price. The stored
        // value is deliberately not consulted: a row can carry a leftover suggested_price_value
        // from a rule that was later cleared, and reading it back would resurrect a rule nobody
        // chose. "No type" and "a type whose value happens to be missing" are different questions,
        // and only the second one is about the value at all.
        //
        // These two used to share a branch (`$type === '' || $type === self::TYPE_NUMBER`), which
        // is what let a blank type render a stored number as though Number had been selected.
        if ($type === '') {
            return $base;
        }

        if ($type === self::TYPE_NUMBER) {
            // Number, by contrast, is a rule the admin picked on purpose, so what they typed under
            // it is the answer. Only an ABSENT value falls back to the base price. A value that is
            // present and numeric is the price, including 0 — "this product is suggested at
            // nothing" is a statement an admin can deliberately make, and silently showing the base
            // price instead contradicts what they typed with no error to explain it.
            //
            // The absent check used to be `$value === null || $value <= 0`, which folded three
            // unrelated cases — unset, zero, negative — into the same silent fallback. Everywhere
            // else in this feature a sign is meaningful (a negative Markup% is a discount, a
            // negative Discount% is a premium), so quietly discarding one here was the odd one out.
            //
            // A negative is passed through rather than floored (#458). The floor used to live here
            // to match the price grid's `max(0, $effective)` in Admin\ProductController::updatePrice(),
            // but that floor was itself inconsistent — Markup$/Markup% below have never had one, so
            // `Markup$ -20` on a base of 10 already returned -10.00 while `Number -5` returned 0.00.
            // Removing it in both places is what makes the sign mean the same thing everywhere.
            if ($value === null) {
                return $base;
            }

            return number_format($value, 2, '.', '');
        }

        if ($baseNumber === null || $value === null) {
            return $base;
        }

        if ($type === self::TYPE_MARKUP_DOLLAR) {
            return number_format($baseNumber + $value, 2, '.', '');
        }

        if ($type === self::TYPE_MARKUP_PERCENT) {
            return number_format($baseNumber + ($baseNumber * $value / 100), 2, '.', '');
        }

        return $base;
    }

    /** Same base-price fallback core already uses everywhere else (Admin\ProductController.php). */
    public function getBasePrice(ProductCore $product): ?string
    {
        // Note what is NOT asked at either step: whether the number is positive. is_numeric('0') and
        // is_numeric('-5') are both true, so a zero or negative default_price IS the base price and
        // original_price is never consulted for it. The fallback means "default_price is not a
        // usable number", not "default_price is not a usable price". The price grid's JavaScript
        // used to read it the second way and so resolved a different base from this one (#464).
        $default = trim((string) ($product->getDefaultPrice() ?? ''));
        if (PriceNumber::isPrice($default)) {
            return $default;
        }

        $original = trim((string) ($product->getOriginalPrice() ?? ''));
        if (PriceNumber::isPrice($original)) {
            return $original;
        }

        return null;
    }
}
