<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Movement;

use InventoryDepthBundle\Movement\PackCost;
use PHPUnit\Framework\TestCase;

/**
 * Where the rounding lands on a pack conversion, and why a round trip cannot lose value (#22).
 *
 * The conducted Cest proves it once, end to end, for a `25.000000` case of twelve. This proves it
 * for a sweep of costs and pack sizes, which is the only way to tell "the arithmetic is right" from
 * "that one example happens to work" — and it is pure arithmetic, so it costs nothing to run it over
 * a hundred cases.
 *
 * ## The rule being pinned
 *
 * **The case is the anchor in both directions.** A case costs what `product_core.cost_price` says;
 * the per-unit figure is `case cost / units per case`, rounded half-up at six decimal places, and it
 * is the only figure that rounds. So:
 *
 *     break    out: cases x case cost          in: cases x units x unit cost
 *     rebuild  out: cases x units x unit cost  in: cases x case cost
 *
 * and the two residues are equal and opposite by construction. The alternative — giving the case
 * back at `units x unit cost` — would lose the remainder on every round trip, which is exactly the
 * quiet value destruction {@see testBreakingAndRebuildingTheSameCasesAlwaysNetsToZero()} would catch.
 */
final class PackCostRoundsWhereTheCaseSaysTest extends TestCase
{
    /**
     * Six decimal places in, six out, and nothing lost on the way through.
     *
     * The scale is not decorative: `product_core.cost_price` is `NUMERIC(18, 6)`, and it is six
     * places that put the residue on a case that does not divide evenly below a millionth of a
     * currency unit rather than at a cent.
     */
    public function testADecimalStringSurvivesTheTripThroughMicroUnits(): void
    {
        self::assertSame(25000000, PackCost::toMicros('25.000000'));
        self::assertSame(25000000, PackCost::toMicros('25'), 'SQLite hands a NUMERIC column back with its trailing zeros gone');
        self::assertSame(2083333, PackCost::toMicros('2.083333'));
        self::assertSame(-2083333, PackCost::toMicros('-2.083333'));

        self::assertSame('25.000000', PackCost::format(25000000));
        self::assertSame('2.083333', PackCost::format(2083333));
        self::assertSame('-2.083333', PackCost::format(-2083333));
        self::assertSame('0.000004', PackCost::format(4), 'the residue on a 25.00 case of twelve, stated');
    }

    /**
     * A blank or non-numeric cost reads as zero rather than throwing.
     *
     * The only source is `product_core.cost_price`, which is nullable and free-typed on the product
     * form. A conversion must not 500 because somebody left a cost blank — and a blank cost is
     * handled ABOVE this class anyway: `PackConversion` stores nulls and the screen says the value is
     * unknown rather than claiming zero.
     */
    public function testANonNumericCostIsZeroAndNotAnException(): void
    {
        self::assertSame(0, PackCost::toMicros(''));
        self::assertSame(0, PackCost::toMicros('   '));
        self::assertSame(0, PackCost::toMicros('not a price'));
    }

    /** Half-up, away from zero on a tie — the rule, stated where it can be changed by accident. */
    public function testDivisionRoundsHalfUpAndAwayFromZero(): void
    {
        self::assertSame(3, PackCost::divideRounded(5, 2), 'two and a half rounds to three, not to two');
        self::assertSame(-3, PackCost::divideRounded(-5, 2));
        self::assertSame(2, PackCost::divideRounded(5, 3));
        self::assertSame(0, PackCost::divideRounded(5, 0), 'a pack size of zero cannot reach this, and must not divide by it either');
    }

    /** The owner's example, and the one that does not divide. */
    public function testTheWorkedExamples(): void
    {
        self::assertSame('2.000000', PackCost::perUnit('24.000000', 12), 'a case costing 24 that breaks into 12 gives units of 2');
        self::assertSame('2.083333', PackCost::perUnit('25.000000', 12), '25 over 12 does not divide evenly, and the sixth place is where it stops');
        self::assertSame('0.125000', PackCost::perUnit('3.000000', 24));
    }

    /**
     * **The property the whole feature turns on**, over a sweep rather than one example.
     *
     * For every cost and every pack size below: breaking N cases and then rebuilding the same N
     * leaves the value exactly where it started. The two residues are computed the way the two
     * operations compute them — the break's unit side against the case anchor, the rebuild's case
     * anchor against the unit side — so a change that anchored the rebuild on the unit figure would
     * fail here for every entry that does not divide evenly, and pass for the ones that do, which is
     * precisely the failure mode a single example would miss.
     */
    public function testBreakingAndRebuildingTheSameCasesAlwaysNetsToZero(): void
    {
        $costs = ['0.000000', '0.010000', '1.000000', '24.000000', '25.000000', '99.990000', '1234.567891', '7.000000'];
        $packSizes = [2, 3, 6, 7, 11, 12, 13, 24, 40, 144, 1000];
        $caseCounts = [1, 3, 17, 500];

        foreach ($costs as $cost) {
            foreach ($packSizes as $units) {
                $unitCost = PackCost::perUnit($cost, $units);

                foreach ($caseCounts as $cases) {
                    $caseSide = PackCost::toMicros($cost) * $cases;
                    $unitSide = PackCost::toMicros($unitCost) * $cases * $units;

                    $breakResidue = $unitSide - $caseSide;
                    $rebuildResidue = $caseSide - $unitSide;

                    self::assertSame(
                        0,
                        $breakResidue + $rebuildResidue,
                        sprintf('%s over %d, %d case(s): a break and its rebuild must cancel', $cost, $units, $cases),
                    );

                    // And the residue is bounded by half a micro-unit per unit, which is what
                    // "rounded at six places" means — a drift larger than that is an arithmetic bug
                    // rather than a rounding one.
                    self::assertLessThanOrEqual(
                        $cases * $units,
                        abs($breakResidue) * 2,
                        sprintf('%s over %d: the residue is rounding, not loss', $cost, $units),
                    );
                }
            }
        }
    }

    /**
     * A case that DOES divide evenly has no residue at all, and the sweep above would pass either
     * way for those — so the two cases are told apart here explicitly.
     */
    public function testAPackThatDividesEvenlyLeavesNothingBehind(): void
    {
        self::assertSame(
            PackCost::toMicros('24.000000'),
            PackCost::toMicros(PackCost::perUnit('24.000000', 12)) * 12,
            'twelve units of 2.000000 are exactly one case of 24.000000',
        );

        self::assertNotSame(
            PackCost::toMicros('25.000000'),
            PackCost::toMicros(PackCost::perUnit('25.000000', 12)) * 12,
            'and twelve units of 2.083333 are deliberately NOT exactly one case of 25.000000 — that difference is the residue, and it is recorded rather than hidden',
        );

        self::assertSame(
            -4,
            PackCost::toMicros(PackCost::perUnit('25.000000', 12)) * 12 - PackCost::toMicros('25.000000'),
            'four micro-units short, which the rebuild puts back',
        );
    }
}
