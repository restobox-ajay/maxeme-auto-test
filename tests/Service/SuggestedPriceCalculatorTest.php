<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ProductCore;
use App\Service\SuggestedPriceCalculator;
use PHPUnit\Framework\TestCase;

final class SuggestedPriceCalculatorTest extends TestCase
{
    private SuggestedPriceCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new SuggestedPriceCalculator();
    }

    public function testGetBasePriceReturnsDefaultPriceWhenNumeric(): void
    {
        $product = (new ProductCore())->setDefaultPrice('12.50')->setOriginalPrice('99.00');

        self::assertSame('12.50', $this->calculator->getBasePrice($product));
    }

    public function testGetBasePriceFallsBackToOriginalPriceWhenDefaultMissing(): void
    {
        $product = (new ProductCore())->setOriginalPrice('30.00');

        self::assertSame('30.00', $this->calculator->getBasePrice($product));
    }

    public function testGetBasePriceReturnsNullWhenNeitherPriceIsSet(): void
    {
        $product = new ProductCore();

        self::assertNull($this->calculator->getBasePrice($product));
    }

    public function testGetBasePriceIgnoresNonNumericDefaultPrice(): void
    {
        $product = (new ProductCore())->setDefaultPrice('n/a')->setOriginalPrice('20.00');

        self::assertSame('20.00', $this->calculator->getBasePrice($product));
    }

    public function testGetEffectivePriceReturnsBaseWhenTypeEmptyAndNoValue(): void
    {
        $product = (new ProductCore())->setDefaultPrice('12.50');

        self::assertSame('12.50', $this->calculator->getEffectivePrice($product));
    }

    /**
     * A blank type means no rule was ever chosen, so the stored value is not consulted at all — not
     * even a 0, which under the Number rule would be a real price. The two cases look alike only
     * because they used to share a branch.
     */
    public function testGetEffectivePriceReturnsBaseWhenTypeEmptyAndValueIsZero(): void
    {
        $product = (new ProductCore())
            ->setDefaultPrice('12.50')
            ->setSuggestedPriceValue('0');

        self::assertSame('12.50', $this->calculator->getEffectivePrice($product));
    }

    /**
     * The case that predates #445: a positive value left behind by a rule type that was later
     * cleared used to be rendered as though Number were still selected. Nothing was chosen, so
     * nothing applies.
     */
    public function testGetEffectivePriceReturnsBaseWhenTypeEmptyAndValueIsPositive(): void
    {
        $product = (new ProductCore())
            ->setDefaultPrice('12.50')
            ->setSuggestedPriceValue('25');

        self::assertSame('12.50', $this->calculator->getEffectivePrice($product));
    }

    /** No rule at all, so the leftover value's sign never gets a say either — the base price stands. */
    public function testGetEffectivePriceReturnsBaseWhenTypeEmptyAndValueIsNegative(): void
    {
        $product = (new ProductCore())
            ->setDefaultPrice('12.50')
            ->setSuggestedPriceValue('-5');

        self::assertSame('12.50', $this->calculator->getEffectivePrice($product));
    }

    public function testGetEffectivePriceReturnsNullWhenNoBaseAndNoValue(): void
    {
        $product = new ProductCore();

        self::assertNull($this->calculator->getEffectivePrice($product));
    }

    public function testGetEffectivePriceUsesRawNumberValueForNumberType(): void
    {
        $product = (new ProductCore())
            ->setDefaultPrice('12.50')
            ->setSuggestedPriceType(SuggestedPriceCalculator::TYPE_NUMBER)
            ->setSuggestedPriceValue('25');

        self::assertSame('25.00', $this->calculator->getEffectivePrice($product));
    }

    /**
     * A typed 0 is a price, not an absent value. This previously returned the base price (12.50),
     * silently contradicting what the admin entered; that behaviour was snapshotted by a
     * characterization test rather than chosen, and is the odd one out in a feature where every
     * other sign is meaningful.
     */
    public function testGetEffectivePriceUsesZeroNumberValueRatherThanFallingBackToBase(): void
    {
        $product = (new ProductCore())
            ->setDefaultPrice('12.50')
            ->setSuggestedPriceType(SuggestedPriceCalculator::TYPE_NUMBER)
            ->setSuggestedPriceValue('0');

        self::assertSame('0.00', $this->calculator->getEffectivePrice($product));
    }

    /**
     * Passed through, neither floored nor discarded (#458). This used to return 0.00, floored to
     * match the price grid's `max(0, ...)` in Admin\ProductController::updatePrice() — but that
     * parity argument only ever held for one of the three branches here: Markup$ and Markup% have
     * never had a floor, so `Markup$ -20` on a base of 10 already returned -10.00 while `Number -5`
     * returned 0.00. The floor came off both this branch and the price grid together, so the sign
     * an admin types now survives whichever rule type they typed it under.
     */
    public function testGetEffectivePriceReturnsNegativeNumberValueUnfloored(): void
    {
        $product = (new ProductCore())
            ->setDefaultPrice('12.50')
            ->setSuggestedPriceType(SuggestedPriceCalculator::TYPE_NUMBER)
            ->setSuggestedPriceValue('-5');

        self::assertSame('-5.00', $this->calculator->getEffectivePrice($product));
    }

    /** An ABSENT value is the one case that still falls back to the base price. */
    public function testGetEffectivePriceFallsBackToBaseWhenNumberValueIsAbsent(): void
    {
        $product = (new ProductCore())
            ->setDefaultPrice('12.50')
            ->setSuggestedPriceType(SuggestedPriceCalculator::TYPE_NUMBER)
            ->setSuggestedPriceValue(null);

        self::assertSame('12.50', $this->calculator->getEffectivePrice($product));
    }

    public function testGetEffectivePriceIgnoresNonNumericValue(): void
    {
        $product = (new ProductCore())
            ->setDefaultPrice('12.50')
            ->setSuggestedPriceType(SuggestedPriceCalculator::TYPE_NUMBER)
            ->setSuggestedPriceValue('abc');

        self::assertSame('12.50', $this->calculator->getEffectivePrice($product));
    }

    public function testGetEffectivePriceAppliesMarkupDollar(): void
    {
        $product = (new ProductCore())
            ->setDefaultPrice('10.00')
            ->setSuggestedPriceType(SuggestedPriceCalculator::TYPE_MARKUP_DOLLAR)
            ->setSuggestedPriceValue('5');

        self::assertSame('15.00', $this->calculator->getEffectivePrice($product));
    }

    public function testGetEffectivePriceAppliesMarkupPercent(): void
    {
        $product = (new ProductCore())
            ->setDefaultPrice('10.00')
            ->setSuggestedPriceType(SuggestedPriceCalculator::TYPE_MARKUP_PERCENT)
            ->setSuggestedPriceValue('10');

        self::assertSame('11.00', $this->calculator->getEffectivePrice($product));
    }

    /** A negative markup is a deliberate below-cost/loss-leader price, not an error — it is relative
     *  to the base price either way, so a negative value just pushes the effective price under base
     *  instead of over it. */
    public function testGetEffectivePriceAppliesNegativeMarkupDollarBelowBase(): void
    {
        $product = (new ProductCore())
            ->setDefaultPrice('10.00')
            ->setSuggestedPriceType(SuggestedPriceCalculator::TYPE_MARKUP_DOLLAR)
            ->setSuggestedPriceValue('-3');

        self::assertSame('7.00', $this->calculator->getEffectivePrice($product));
    }

    public function testGetEffectivePriceAppliesNegativeMarkupPercentBelowBase(): void
    {
        $product = (new ProductCore())
            ->setDefaultPrice('10.00')
            ->setSuggestedPriceType(SuggestedPriceCalculator::TYPE_MARKUP_PERCENT)
            ->setSuggestedPriceValue('-25');

        self::assertSame('7.50', $this->calculator->getEffectivePrice($product));
    }

    /**
     * A markup big enough to take the price under zero is not a special case — it is the same rule
     * with a bigger number. Pinned here because it is the branch the Number floor used to be
     * justified against: these two must agree about what a negative result means (#458).
     */
    public function testGetEffectivePriceAllowsMarkupDollarToTakeThePriceBelowZero(): void
    {
        $product = (new ProductCore())
            ->setDefaultPrice('10.00')
            ->setSuggestedPriceType(SuggestedPriceCalculator::TYPE_MARKUP_DOLLAR)
            ->setSuggestedPriceValue('-20');

        self::assertSame('-10.00', $this->calculator->getEffectivePrice($product));
    }

    public function testGetEffectivePriceAllowsMarkupPercentToTakeThePriceBelowZero(): void
    {
        $product = (new ProductCore())
            ->setDefaultPrice('10.00')
            ->setSuggestedPriceType(SuggestedPriceCalculator::TYPE_MARKUP_PERCENT)
            ->setSuggestedPriceValue('-200');

        self::assertSame('-10.00', $this->calculator->getEffectivePrice($product));
    }

    public function testGetEffectivePriceMarkupReturnsBaseWhenNoBasePrice(): void
    {
        $product = (new ProductCore())
            ->setSuggestedPriceType(SuggestedPriceCalculator::TYPE_MARKUP_PERCENT)
            ->setSuggestedPriceValue('10');

        self::assertNull($this->calculator->getEffectivePrice($product));
    }

    public function testGetEffectivePriceMarkupReturnsBaseWhenValueMissing(): void
    {
        $product = (new ProductCore())
            ->setDefaultPrice('10.00')
            ->setSuggestedPriceType(SuggestedPriceCalculator::TYPE_MARKUP_PERCENT);

        self::assertSame('10.00', $this->calculator->getEffectivePrice($product));
    }

    public function testGetEffectivePriceReturnsBaseForUnknownType(): void
    {
        $product = (new ProductCore())
            ->setDefaultPrice('10.00')
            ->setSuggestedPriceType('SomethingElse')
            ->setSuggestedPriceValue('5');

        self::assertSame('10.00', $this->calculator->getEffectivePrice($product));
    }

    /**
     * Exponent notation is refused, and this is the one place #464 changed the SERVER rather than
     * the client. `is_numeric('1e3')` is true, so `1e3` used to become a suggested price of 1000.00
     * — three orders of magnitude away from anything three keystrokes in a money field could have
     * meant. It is far likelier to be a typo, or an identifier pasted into the wrong cell, than an
     * intention, so it is treated as an absent value like any other unusable input.
     *
     * `1E3` matters as much as `1e3`: `is_numeric()` accepts both, so the check is `stripos()` and
     * an implementation that reached for `strpos()` would leave exactly half the hole open. That is
     * the whole reason for the second case here.
     */
    public function testGetEffectivePriceRejectsExponentNotationInTheValue(): void
    {
        foreach (['1e3', '1E3', '1e-3', '-2.5E2'] as $value) {
            $product = (new ProductCore())
                ->setDefaultPrice('10.00')
                ->setSuggestedPriceType(SuggestedPriceCalculator::TYPE_NUMBER)
                ->setSuggestedPriceValue($value);

            self::assertSame(
                '10.00',
                $this->calculator->getEffectivePrice($product),
                sprintf('"%s" is not a price and must fall back to the base price (#464).', $value),
            );
        }
    }

    /**
     * The same rule on the base-price chain. An exponent in default_price is not a base, so the
     * chain moves on to original_price exactly as it would for "n/a".
     */
    public function testGetBasePriceRejectsExponentNotation(): void
    {
        self::assertSame(
            '20.00',
            $this->calculator->getBasePrice(
                (new ProductCore())->setDefaultPrice('1e3')->setOriginalPrice('20.00'),
            ),
            'An exponent default_price is not a base price; original_price is (#464).',
        );

        self::assertNull(
            $this->calculator->getBasePrice(
                (new ProductCore())->setDefaultPrice('1E3')->setOriginalPrice('1e3'),
            ),
            'With exponents at both links there is no base price at all (#464).',
        );
    }

    /**
     * The other side of that line. Refusing exponents must not become refusing anything unusual, so
     * the shapes an admin actually types stay accepted — including the two the rest of this feature
     * fought to keep, a zero and a negative.
     */
    public function testOrdinaryDecimalsAndNegativesAreStillAccepted(): void
    {
        foreach (['0', '-5', '12.50', '-0.75', '.5', '12.'] as $value) {
            $product = (new ProductCore())
                ->setDefaultPrice('10.00')
                ->setSuggestedPriceType(SuggestedPriceCalculator::TYPE_NUMBER)
                ->setSuggestedPriceValue($value);

            self::assertSame(
                number_format((float) $value, 2, '.', ''),
                $this->calculator->getEffectivePrice($product),
                sprintf('"%s" is a perfectly ordinary price and must still be accepted.', $value),
            );
        }

        foreach (['0', '-5', '12.50', '.5'] as $base) {
            self::assertSame(
                $base,
                $this->calculator->getBasePrice((new ProductCore())->setDefaultPrice($base)),
                sprintf('"%s" must still be usable as a base price.', $base),
            );
        }
    }
}
