<?php

declare(strict_types=1);

namespace ProcurementBundle\Tests\Match;

use PHPUnit\Framework\TestCase;
use ProcurementBundle\Match\MatchLine;

/**
 * What a match exception is WORTH (item 49) — the figure the exceptions screen ranks by.
 *
 * The screen's whole complaint was that a $5 problem and a $5,000 one looked identical, so the
 * arithmetic behind the column is the part worth pinning down: each finding's exposure is a
 * different expression of quantity and price, and a row carrying several of them reports ONE number
 * rather than their sum.
 *
 * Everything here is exercised through `MatchLine::of()` with the same decimal strings Doctrine
 * stores — quantities to two places, unit costs to four — because the rounding is the point. Cents
 * are asserted as integers alongside the formatted string, since the integer is what gets sorted
 * and the string is only what gets displayed.
 */
final class MatchLineAmountAtRiskTest extends TestCase
{
    /**
     * @param list<string> $exceptions
     */
    private static function line(
        string $ordered,
        string $received,
        string $billed,
        ?string $orderedCost,
        ?string $billedCost,
        array $exceptions,
    ): MatchLine {
        return MatchLine::of(
            null,
            null,
            'Widget',
            $ordered,
            $received,
            $billed,
            $orderedCost,
            $billedCost,
            $exceptions,
            '0.00',
            '0.0000',
        );
    }

    /** Eighty units charged for that no receipt supports, at the price being charged for them. */
    public function testBilledButNotReceivedIsTheUnsupportedUnitsAtTheBilledPrice(): void
    {
        $line = self::line('100.00', '20.00', '100.00', '5.0000', '5.0000', [MatchLine::EXCEPTION_BILLED_NOT_RECEIVED]);

        self::assertSame(40000, $line->amountAtRiskCents());
        self::assertSame('400.00', $line->amountAtRisk());
    }

    /**
     * A bill with no purchase order behind it has no receipts at all, so the whole line is the
     * exposure. `ThreeWayMatchService` builds exactly this row: no order line, nothing received.
     */
    public function testABillWithNoOrderBehindItRisksItsWholeLine(): void
    {
        $line = self::line('0.00', '0.00', '3.00', null, '7.0000', [MatchLine::EXCEPTION_BILLED_NOT_RECEIVED]);

        self::assertSame('21.00', $line->amountAtRisk());
    }

    /** The overcharge, across every unit billed — half a dollar on ten of them. */
    public function testPriceVarianceIsTheOverchargeAcrossTheBilledQuantity(): void
    {
        $line = self::line('10.00', '10.00', '10.00', '5.0000', '5.5000', [MatchLine::EXCEPTION_PRICE_VARIANCE]);

        self::assertSame(500, $line->amountAtRiskCents());
        self::assertSame('5.00', $line->amountAtRisk());
    }

    /**
     * The gap between what was ordered and what turned up, at the agreed price. Not about the bill:
     * it is goods still owed to us, or goods nobody ordered.
     */
    public function testQuantityVarianceIsTheShipmentGapAtTheAgreedPrice(): void
    {
        $short = self::line('240.00', '210.00', '210.00', '5.0000', '5.0000', [MatchLine::EXCEPTION_QUANTITY_VARIANCE]);
        $over = self::line('240.00', '250.00', '250.00', '5.0000', '5.0000', [MatchLine::EXCEPTION_QUANTITY_VARIANCE]);

        self::assertSame('150.00', $short->amountAtRisk());
        // Over-shipped is the same size of problem in the other direction, never a negative figure:
        // a minus sign here would sort ten unordered units below every clean row on the screen.
        self::assertSame('50.00', $over->amountAtRisk());
    }

    /** Nothing supports any of it, so the exposure is the whole charge. */
    public function testNotOnThePurchaseOrderRisksTheWholeCharge(): void
    {
        $line = self::line('0.00', '0.00', '3.00', null, '7.0000', [MatchLine::EXCEPTION_UNMATCHED]);

        self::assertSame('21.00', $line->amountAtRisk());
    }

    /** The accrual: units that arrived and are not yet charged for, at the price we agreed. */
    public function testReceivedButNotBilledIsTheUnbilledUnitsAtTheAgreedPrice(): void
    {
        $line = self::line('100.00', '40.00', '0.00', '2.5000', null, [MatchLine::EXCEPTION_RECEIVED_NOT_BILLED]);

        self::assertSame('100.00', $line->amountAtRisk());
    }

    /**
     * The rule the screen turns on: a row with several findings reports the LARGEST, never the sum.
     *
     * This line is short-shipped at 210 against an order for 240, billed for all 240, and billed at
     * 5.50 against a quoted 5.00. Three findings, and two of them are about the SAME thirty units —
     * so the sum, 435.00, would claim nearly three times the money that is actually at stake.
     */
    public function testARowWithSeveralFindingsReportsTheLargestAndNotTheirSum(): void
    {
        $line = self::line('240.00', '210.00', '240.00', '5.0000', '5.5000', [
            MatchLine::EXCEPTION_BILLED_NOT_RECEIVED,
            MatchLine::EXCEPTION_QUANTITY_VARIANCE,
            MatchLine::EXCEPTION_PRICE_VARIANCE,
        ]);

        self::assertSame(16500, $line->amountForCents(MatchLine::EXCEPTION_BILLED_NOT_RECEIVED));
        self::assertSame(15000, $line->amountForCents(MatchLine::EXCEPTION_QUANTITY_VARIANCE));
        self::assertSame(12000, $line->amountForCents(MatchLine::EXCEPTION_PRICE_VARIANCE));

        self::assertSame('165.00', $line->amountAtRisk());
        self::assertNotSame('435.00', $line->amountAtRisk());
    }

    /** A finding the row does not carry is worth nothing, so no caller can report somebody else's. */
    public function testAFindingThisRowDoesNotCarryIsWorthNothing(): void
    {
        $line = self::line('10.00', '10.00', '10.00', '5.0000', '5.5000', [MatchLine::EXCEPTION_PRICE_VARIANCE]);

        self::assertSame(0, $line->amountForCents(MatchLine::EXCEPTION_BILLED_NOT_RECEIVED));
        self::assertSame(0, $line->amountForCents(MatchLine::EXCEPTION_QUANTITY_VARIANCE));
    }

    /** A clean row is worth nothing and says so, rather than leaving the cell empty. */
    public function testACleanRowIsWorthNothing(): void
    {
        $line = self::line('10.00', '10.00', '10.00', '5.0000', '5.0000', []);

        self::assertSame(0, $line->amountAtRiskCents());
        self::assertSame('0.00', $line->amountAtRisk());
    }

    /**
     * Unit costs carry four decimal places and money carries two, so the fraction of a cent has to
     * land somewhere. It lands once, on the row's total, rather than on each unit: three at 0.3333
     * is 0.9999, which is a dollar, not ninety-nine cents times three.
     */
    public function testTheFractionOfACentIsRoundedOnceOnTheRowAndNotPerUnit(): void
    {
        $line = self::line('3.00', '0.00', '3.00', '0.3333', '0.3333', [MatchLine::EXCEPTION_BILLED_NOT_RECEIVED]);

        self::assertSame(100, $line->amountAtRiskCents());
        self::assertSame('1.00', $line->amountAtRisk());
    }

    /**
     * The sortable figure is an integer, and the cell it is displayed in is not.
     *
     * `'1000.00'` sorts BEFORE `'9.00'` as text, which would put a thousand-dollar exception below
     * a nine-dollar one. Worth being exact about where that bite comes from in PHP, because it is
     * not where it looks: PHP 8 compares two NUMERIC strings numerically, so `'1000.00' <=> '9.00'`
     * is already the right answer by luck. The lexical order is what you get from `strcmp()`, from
     * `sort(..., SORT_STRING)`, from an ORDER BY on a TEXT column, from a client-side sort — and,
     * above all, from the moment the value stops being a bare number, which it does the instant the
     * currency is prefixed for display. `'CAD 1000.00'` against `'CAD 9.00'` is not two numeric
     * strings and PHP will compare it character by character, cheerfully.
     *
     * So the screen sorts on cents and never on what it renders. These are the two figures the
     * conducted Cest orders on the real screen, chosen because they invert under text ordering.
     */
    public function testTheSortableFigureIsAnIntegerAndNotTheStringInTheCell(): void
    {
        $small = self::line('9.00', '0.00', '9.00', '1.0000', '1.0000', [MatchLine::EXCEPTION_BILLED_NOT_RECEIVED]);
        $large = self::line('1000.00', '0.00', '1000.00', '1.0000', '1.0000', [MatchLine::EXCEPTION_BILLED_NOT_RECEIVED]);

        self::assertSame(900, $small->amountAtRiskCents());
        self::assertSame(100000, $large->amountAtRiskCents());
        self::assertLessThan($large->amountAtRiskCents(), $small->amountAtRiskCents());

        // And the inversion these same two produce as text, stated so it is on the record rather
        // than asserted about — this is the ordering the integer above exists to avoid.
        self::assertLessThan(0, strcmp($large->amountAtRisk(), $small->amountAtRisk()));
        self::assertLessThan(0, strcmp('CAD ' . $large->amountAtRisk(), 'CAD ' . $small->amountAtRisk()));
    }
}
