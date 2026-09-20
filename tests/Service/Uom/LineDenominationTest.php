<?php

declare(strict_types=1);

namespace App\Tests\Service\Uom;

use App\Entity\ProductCore;
use App\Entity\UnitOfMeasure;
use App\Service\Uom\LineDenomination;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic behind the one selector (#601 phase 4, #659).
 *
 * The worked example the owner settled the design on is here verbatim, because it is the case the
 * decision turns on: $10.00 a box of 12 stored at six decimals prints back as $10.00 and totals
 * $400.00, where the same figure at four decimals printed $399.98 and contradicted itself in public.
 *
 * What #659 changed is where the twelve comes from. It is no longer a per-product rung's
 * `factor_to_base`; it is the quotient of two global factors to the family base — `BOX-12` at 12
 * against a base unit of `EA` at 1. Everything else here is the same arithmetic, which is the point:
 * one table defines conversion and nothing else does.
 *
 * Plain unit tests — nothing here touches the database. A unit's factor is a column on the row and
 * needs no persistence to be true.
 */
final class LineDenominationTest extends TestCase
{
    public function testAQuantityEnteredInBoxesIsStoredInBaseUnits(): void
    {
        self::assertSame('480.0000', LineDenomination::toBaseQuantity('40', $this->unit('BOX-12', '12'), $this->each()));
    }

    /**
     * The ratio is between TWO units, not a property of one.
     *
     * A gram against a base of kilograms is 0.001, and a kilogram against a base of grams is 1000 —
     * the same two rows read in two directions. A model that stored "how many base units" on the
     * unit itself could not express both, which is precisely why the factor is to the FAMILY base
     * and the product's own base unit is the second half of every quotient.
     */
    public function testTheRatioIsBetweenTheLinesUnitAndTheProductsBaseUnit(): void
    {
        $gram = $this->unit('G', '1', UnitOfMeasure::FAMILY_WEIGHT);
        $kilo = $this->unit('KG', '1000', UnitOfMeasure::FAMILY_WEIGHT);

        self::assertSame(1000.0, LineDenomination::factorToBase($kilo, $gram));
        self::assertSame(0.001, LineDenomination::factorToBase($gram, $kilo));
    }

    /**
     * A line naming no unit is handed back verbatim.
     *
     * Not cosmetic: the form renders these figures straight back into the boxes the next save reads,
     * so a formatting pass here would rewrite a stored quantity nobody typed — which is the defect
     * #259 closed on the quote form from the other direction.
     */
    public function testALineWithNoUnitIsNeitherConvertedNorReformatted(): void
    {
        $each = $this->each();

        self::assertSame('3.00', LineDenomination::toBaseQuantity('3.00', null, $each));
        self::assertSame('3.00', LineDenomination::toEnteredQuantity('3.00', null, $each));
        self::assertSame('11.5', LineDenomination::toBasePrice('11.5', null, $each));
        self::assertSame('11.5', LineDenomination::toUnitPrice('11.5', null, $each));
    }

    /**
     * A product that declares no base unit has no scale to convert INTO, so nothing is converted.
     *
     * Answering with the unit's own factor would be the app inventing a base: a line saying 40
     * BOX-12 on a product whose numbers are denominated in nothing would silently become 480 of
     * nothing. A base-unit reading is the only honest answer, and it is what the row already meant.
     */
    public function testAProductWithNoDeclaredBaseUnitConvertsNothing(): void
    {
        self::assertSame(1.0, LineDenomination::factorToBase($this->unit('BOX-12', '12'), null));
        self::assertSame('40', LineDenomination::toBaseQuantity('40', $this->unit('BOX-12', '12'), null));
    }

    /**
     * Switching the selector re-expresses the line: 480 base units are 2 pallets, never 40 of them.
     *
     * This is the arithmetic behind the bug #644 names outright — "switching Case to Pallet must not
     * leave 40 in the box now meaning 40 pallets", which silently multiplies the order.
     */
    public function testABaseFigureIsReExpressedInWhicheverUnitIsSelected(): void
    {
        $each = $this->each();
        $box = $this->unit('BOX-12', '12');
        $pallet = $this->unit('PALLET-240', '240');

        self::assertSame('40.0000', LineDenomination::toEnteredQuantity('480', $box, $each));
        self::assertSame('2.0000', LineDenomination::toEnteredQuantity('480', $pallet, $each));
    }

    /** A unit the base figure does not divide by evenly re-expresses as a fraction of one. */
    public function testAnUnevenReExpressionIsAFractionRatherThanARoundedUnit(): void
    {
        self::assertSame('2.4000', LineDenomination::toEnteredQuantity('480', $this->unit('BULK-200', '200'), $this->each()));
    }

    public function testAPricePerBoxIsStoredPerBaseUnit(): void
    {
        self::assertSame('0.500000', LineDenomination::toBasePrice('6.00', $this->unit('BOX-12', '12'), $this->each()));
    }

    /**
     * #601's worked example, settled 2026-09-09, end to end.
     *
     * ```
     * 10.00 / 12         = 0.8333333...  stored 0.833333
     * per box            0.833333 x 12   = 9.999996   -> $10.00
     * line total         0.833333 x 480  = 399.99984  -> $400.00
     * ```
     *
     * The residue is below a cent, which is the whole reason the columns went to six decimals in
     * #645. At four it would be 0.8333, printing $10.00 against a total of $399.98.
     */
    public function testSixDecimalsMakeTheDerivedPerBoxPriceAndTheLineTotalAgree(): void
    {
        $each = $this->each();
        $box = $this->unit('BOX-12', '12');

        $stored = LineDenomination::toBasePrice('10.00', $box, $each);
        self::assertSame('0.833333', $stored);

        self::assertSame('10.00', LineDenomination::toUnitPrice($stored, $box, $each));
        self::assertSame('400.00', LineDenomination::lineTotal('480', $stored));
    }

    /** The same figures at four decimals, which is the case the owner corrected. */
    public function testFourDecimalsWouldHaveContradictedTheDocument(): void
    {
        self::assertSame('399.98', LineDenomination::lineTotal('480', '0.8333'));
    }

    /** A quote line nobody has priced totals nothing, and nothing is not zero. */
    public function testAnUnpricedLineHasNoTotalRatherThanATotalOfZero(): void
    {
        self::assertNull(LineDenomination::lineTotal('480', null));
    }

    /**
     * The label is the unit's CODE, with no bracketed pack size (#659).
     *
     * `CASE(12)` existed because a packaging rung and a base unit shared one column and the brackets
     * were the only tell — which the issue names as a defect rather than a convention. A term now
     * carries its own count, so `BOX-12` says twelve and `BOX-24` says twenty-four, and printing the
     * ratio as well would state the same fact twice on one document.
     */
    public function testTheLabelIsTheUnitsCodeWithNoBracketedPackSize(): void
    {
        self::assertSame('BOX-12', LineDenomination::label($this->unit('BOX-12', '12'), 'EA', null));
        self::assertSame('PALLET-240', LineDenomination::label($this->unit('PALLET-240', '240'), 'EA', null));
    }

    /**
     * A line naming no unit prints the U/M it always printed.
     *
     * The line's own `unit` snapshot wins over the product's live base unit, because a document says
     * what it said — the product can be re-based afterwards and the printed copy must not move.
     */
    public function testALineNamingNoUnitPrintsItsOwnSnapshotFirst(): void
    {
        $product = (new ProductCore())->setSku('LBL-1');
        $product->setBaseUnit((new UnitOfMeasure())->setCode('KG'));

        self::assertSame('BOX', LineDenomination::label(null, 'BOX', $product));
        self::assertSame('KG', LineDenomination::label(null, null, $product));
        self::assertSame('-', LineDenomination::label(null, null, null));
    }

    /**
     * The untouched-box test, stated on its own.
     *
     * It is what stands between "switching BOX-12 to PALLET-240 re-expresses the line" and
     * "switching it multiplies the order twentyfold", so its edges matter: a box that came back with
     * the same NUMBER in a different shape is untouched, and a post carrying no rendered value at
     * all is not evidence of anything and reads as touched — which is what makes every form and
     * every script that predates this field behave exactly as it did.
     */
    public function testABoxCountsAsUntouchedOnlyWhenTheServerSaidWhatWasInIt(): void
    {
        self::assertTrue(LineDenomination::boxUntouched('40', '40'));
        self::assertTrue(LineDenomination::boxUntouched('40', '40.0000'), 'the same figure through a decimal column');
        self::assertTrue(LineDenomination::boxUntouched(' 6.00 ', '6.00'), 'whitespace is not an edit');

        self::assertFalse(LineDenomination::boxUntouched('3', '40'), 'a figure the admin typed');
        self::assertFalse(LineDenomination::boxUntouched('40', null), 'a post carrying no rendered value proves nothing');
        self::assertFalse(LineDenomination::boxUntouched('40', ''), 'nor does an empty one');
        self::assertFalse(LineDenomination::boxUntouched(['40'], '40'), 'an array is not a figure (#395)');
    }

    /** A hand-broken factor reads as a base-unit line instead of dividing by zero mid-invoice. */
    public function testANonPositiveFactorFallsBackToOneRatherThanExploding(): void
    {
        $broken = (new UnitOfMeasure())->setCode('BROKEN')->setFactorToFamilyBase('0');

        self::assertSame(1.0, LineDenomination::factorToBase($broken, $this->each()));
        self::assertSame(1.0, LineDenomination::factorToBase($this->unit('BOX-12', '12'), $broken));
    }

    private function each(): UnitOfMeasure
    {
        return $this->unit('EA', '1');
    }

    private function unit(string $code, string $factor, string $family = UnitOfMeasure::FAMILY_QUANTITY): UnitOfMeasure
    {
        return (new UnitOfMeasure())
            ->setCode($code)
            ->setName('Unit ' . $code)
            ->setFamily($family)
            ->setFactorToFamilyBase($factor);
    }
}
