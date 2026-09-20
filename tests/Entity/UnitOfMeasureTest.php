<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\UnitOfMeasure;
use PHPUnit\Framework\TestCase;

/**
 * The measurement row's own rules (#601, phase 1 #643).
 *
 * The one that earns its keep is `accepts()`: it is what makes "whole numbers are enforced per unit,
 * not by the column type" true, and it is the difference between a product in `each` refusing 2.5 at
 * the form and the same figure landing in the database because NUMERIC(14,4) was happy to take it.
 */
final class UnitOfMeasureTest extends TestCase
{
    public function testTheCodeIsUpperCasedAndTrimmedSoOneUnitCannotBecomeTwo(): void
    {
        $unit = (new UnitOfMeasure())->setCode('  kg ');

        self::assertSame('KG', $unit->getCode());
    }

    public function testAnUnrecognisedFamilyFallsBackToQuantity(): void
    {
        $unit = (new UnitOfMeasure())->setFamily('luminosity');

        self::assertSame(UnitOfMeasure::FAMILY_QUANTITY, $unit->getFamily());
    }

    public function testFactorsAreStoredAtSixDecimalsSoTwoEqualFactorsCompareEqual(): void
    {
        $pounds = (new UnitOfMeasure())->setCode('LB')->setFactorToFamilyBase('0.45359237');

        // Six decimals, which is the scale of the column — not two, which would make a pound 0.45.
        self::assertSame('0.453592', $pounds->getFactorToFamilyBase());
    }

    /** `each` rejects 2.5; `kg` accepts it. Same column, different unit — that is the whole point. */
    public function testAWholeNumberUnitRejectsAFractionAndAMeasuredUnitDoesNot(): void
    {
        $each = (new UnitOfMeasure())->setCode('EA')->setRoundingPrecision('1');
        $kilos = (new UnitOfMeasure())->setCode('KG')->setRoundingPrecision('0.001');

        self::assertFalse($each->accepts('2.5'), 'a countable thing has no half');
        self::assertTrue($each->accepts('3'));
        self::assertTrue($kilos->accepts('2.5'));
        self::assertTrue($kilos->accepts('2.125'));
        self::assertFalse($kilos->accepts('2.1255'), 'finer than the unit measures is not a quantity in it');
    }

    /**
     * fmod(0.3, 0.1) is 0.0999… in binary floating point, so a unit that compared that way would
     * refuse 0.3 kg and be indistinguishable from a bug. Scaled integers do not have the problem.
     */
    public function testAcceptsIsNotFooledByBinaryFloatingPoint(): void
    {
        $kilos = (new UnitOfMeasure())->setRoundingPrecision('0.1');

        self::assertTrue($kilos->accepts('0.3'));
        self::assertTrue($kilos->accepts('0.7'));
        self::assertTrue($kilos->accepts('1.1'));
    }

    public function testWholeNumbersOnlyIsTrueOnlyAtAPrecisionOfExactlyOne(): void
    {
        self::assertTrue((new UnitOfMeasure())->setRoundingPrecision('1')->isWholeNumbersOnly());
        self::assertFalse((new UnitOfMeasure())->setRoundingPrecision('0.5')->isWholeNumbersOnly());
        self::assertFalse((new UnitOfMeasure())->setRoundingPrecision('10')->isWholeNumbersOnly());
    }

    public function testTheLabelReadsAsCodeAndNameForTheProductFormSelect(): void
    {
        $unit = (new UnitOfMeasure())->setCode('kg')->setName('Kilogram');

        self::assertSame('KG — Kilogram', $unit->getLabel());
    }
}
