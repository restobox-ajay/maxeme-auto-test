<?php

declare(strict_types=1);

namespace App\Tests\Service\Pricing;

use App\Service\Pricing\PriceNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one rule the price grid's server side uses to decide whether a string is money (#464).
 *
 * It is `is_numeric()` minus exponent notation, and each of those two halves has its own way of
 * going wrong. Drop the `is_numeric()` half and "12abc" becomes 12. Drop the exponent half and "1e3"
 * becomes 1000. Write the exponent half with `strpos()` instead of `stripos()` and "1e3" is refused
 * while "1E3" sails through — a half-closed hole that looks closed from the outside, which is why
 * every exponent case below is asserted in both cases.
 *
 * The same grammar is implemented a second time in JavaScript, by numericValue() in
 * templates/admin/product/prices.html.twig, and the two are held together by the executed harnesses
 * in tests/Asset/. This file pins the PHP half on its own so that a failure says which of the two
 * moved.
 */
final class PriceNumberTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function acceptedProvider(): iterable
    {
        yield 'a plain integer' => ['25'];
        yield 'a plain decimal' => ['12.50'];
        yield 'a trailing-point decimal' => ['12.'];
        yield 'a bare fraction' => ['.5'];
        yield 'a leading-zero decimal' => ['0.99'];

        // The two the rest of this feature fought to keep (#445, #458, #462). A price of zero and a
        // negative price are both things an admin can legitimately mean, so there is deliberately no
        // sign or range test here — adding one would put a floor back at every call site at once.
        yield 'a zero' => ['0'];
        yield 'a negative integer' => ['-5'];
        yield 'a negative decimal' => ['-12.75'];
        yield 'an explicit plus sign' => ['+7'];

        // PHP 8 allows whitespace on both sides of a numeric string, and the callers trim before
        // asking anyway. Accepting it here keeps the two consistent.
        yield 'leading whitespace' => ['  12'];
        yield 'trailing whitespace' => ['12  '];
    }

    #[DataProvider('acceptedProvider')]
    public function testTheseAreStillPrices(string $value): void
    {
        self::assertTrue(
            PriceNumber::isPrice($value),
            sprintf('"%s" is an ordinary price and must not be caught by the exponent rule.', $value),
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function rejectedProvider(): iterable
    {
        // The exponent cases — the whole reason this class exists. Both letter cases, both signs of
        // exponent, and one with a decimal mantissa, because `stripos()` is the only implementation
        // that refuses all of them and `strpos()` refuses exactly half.
        yield 'lowercase exponent' => ['1e3'];
        yield 'uppercase exponent' => ['1E3'];
        yield 'negative exponent' => ['1e-3'];
        yield 'uppercase negative exponent' => ['1E-3'];
        yield 'explicit positive exponent' => ['1e+3'];
        yield 'decimal mantissa with an exponent' => ['-2.5E2'];

        // Everything is_numeric() already refused, kept here so a future rewrite of the rule cannot
        // loosen it while satisfying the exponent cases above.
        yield 'a numeric prefix' => ['12abc'];
        yield 'a hexadecimal literal' => ['0x1A'];
        yield 'a binary literal' => ['0b101'];
        yield 'letters' => ['abc'];
        yield 'blank' => [''];
        yield 'whitespace only' => ['   '];
        yield 'a thousands separator' => ['1,000'];
        yield 'a currency symbol' => ['$12.50'];
        yield 'a bare sign' => ['-'];
        yield 'two decimal points' => ['1.2.3'];
    }

    #[DataProvider('rejectedProvider')]
    public function testTheseAreNotPrices(string $value): void
    {
        self::assertFalse(
            PriceNumber::isPrice($value),
            sprintf('"%s" must not be accepted as a price (#464).', $value),
        );
    }

    /**
     * The exponent rule is the deliberate departure from `is_numeric()`, so it is asserted as a
     * departure rather than only as a list of rejections. If a future change makes the two agree
     * again, this says which direction it moved and why that was not the intention.
     */
    public function testTheOnlyDepartureFromIsNumericIsExponentNotation(): void
    {
        foreach (['1e3', '1E3', '1e-3', '1E+3', '-2.5E2'] as $exponent) {
            self::assertTrue(is_numeric($exponent), sprintf('"%s" is numeric to PHP...', $exponent));
            self::assertFalse(
                PriceNumber::isPrice($exponent),
                sprintf('...and "%s" is deliberately not a price to this application (#464).', $exponent),
            );
        }
    }
}
