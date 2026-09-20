<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DisplayNumber;
use App\Service\DisplayNumberContext;
use App\Service\Uom\LineDenomination;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The three rules, at every edge that has ever produced a wrong figure on a screen.
 *
 * The cases are not decoration. `2.5` reading "3" is the defect that was fixed three times on three
 * screens and kept coming back; `0.917` reading "0.92" is a unit price that stops explaining its own
 * line total; and a null price reading "0.00" would turn "not quoted yet" into "free" on a document
 * a customer receives.
 */
final class DisplayNumberTest extends TestCase
{
    private DisplayNumber $numbers;

    protected function setUp(): void
    {
        $this->numbers = new DisplayNumber();
    }

    // ── qty: up to four places, none when whole ─────────────────────────────────────────────────

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function quantities(): iterable
    {
        yield 'a whole number shows no decimal point' => ['3', '3'];
        yield 'the column scale is not the display scale' => ['3.0000', '3'];
        yield 'the defect this filter ends: a half case stays a half case' => ['2.5000', '2.5'];
        yield 'and is not rounded up to 3' => ['2.5', '2.5'];
        yield 'zero is a quantity' => ['0', '0'];
        yield 'zero at column scale is still just zero' => ['0.0000', '0'];
        yield 'trailing zeros are trimmed, not padded' => ['2.5000', '2.5'];
        yield 'a fourth place survives' => ['39.9167', '39.9167'];
        yield 'a fifth place cannot exist in decimal(14,4) and is rounded to the column' => ['2.55555', '2.5556'];
        yield 'negatives keep their sign' => ['-5.25', '-5.25'];
        yield 'no thousands separator — a quantity is not money' => ['1234.5', '1234.5'];
        yield 'nor on a whole one' => ['1000000', '1000000'];
        yield 'the width of decimal(14,4): ten integer digits and four decimals' => ['1234567890.1234', '1234567890.1234'];
    }

    #[DataProvider('quantities')]
    public function testQuantity(string $stored, string $expected): void
    {
        self::assertSame($expected, $this->numbers->qty($stored));
    }

    /**
     * The same figure arrives in two spellings and must render as one.
     *
     * `quantity_entered` is `NUMERIC(14,4)`, and SQLite's numeric affinity drops trailing zeros on
     * the way out. So an entity still in the EntityManager's identity map hands the template
     * `"2.5000"` while the same row re-read from the database hands it `"2.5"` — which is why
     * `AdminUomLineEntryAndDisplayCest` can assert `= 480 EA` on one screen while a freshly created
     * in-process fixture renders `30.0000`. A rule that only trimmed one of the two spellings would
     * be half a rule, and the half it was missing would be the half that shows up in tests.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function sameQuantityBothWaysRound(): iterable
    {
        yield 'half a case' => ['2.5000', '2.5'];
        yield 'three' => ['3.0000', '3'];
        yield 'four hundred and eighty' => ['480.0000', '480'];
        yield 'thirty' => ['30.0000', '30'];
        yield 'a fourth place' => ['39.9167', '39.9167'];
    }

    #[DataProvider('sameQuantityBothWaysRound')]
    public function testAQuantityRendersTheSameFromMemoryAsFromSqlite(string $inMemory, string $fromSqlite): void
    {
        self::assertSame(
            $this->numbers->qty($inMemory),
            $this->numbers->qty($fromSqlite),
            'the column scale must not decide what the screen shows',
        );
        self::assertSame($fromSqlite, $this->numbers->qty($inMemory));
    }

    // ── price: a unit price, two to six places ──────────────────────────────────────────────────

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unitPrices(): iterable
    {
        yield 'money floors at two places even when whole' => ['12', '12.00'];
        yield 'and when the column carries six' => ['12.000000', '12.00'];
        yield 'a plain cent price is unchanged' => ['12.50', '12.50'];
        yield 'sub-cent precision is kept — this is what |amount would lose' => ['0.917', '0.917'];
        yield 'a resolved per-base rate keeps all six' => ['0.833333', '0.833333'];
        yield 'a third place is not rounded away' => ['1234.5678', '1,234.5678'];
        yield 'thousands separator, because it is money' => ['1234.5', '1,234.50'];
        yield 'zero is a price this application stores (#458)' => ['0', '0.00'];
        yield 'and a negative one too (#462)' => ['-5.25', '-5.25'];
        yield 'a negative sub-cent keeps its sign' => ['-0.001', '-0.001'];
        yield 'the sixth place, the scale decimal(18,6) holds' => ['0.000001', '0.000001'];
    }

    #[DataProvider('unitPrices')]
    public function testUnitPrice(string $stored, string $expected): void
    {
        self::assertSame($expected, $this->numbers->price($stored));
    }

    // ── amount: a money total, always exactly two ───────────────────────────────────────────────

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function amounts(): iterable
    {
        yield 'always two places' => ['12', '12.00'];
        yield 'never trimmed, even when the cents are zero' => ['12.00', '12.00'];
        yield 'thousands separator' => ['1234.5', '1,234.50'];
        yield 'millions' => ['1234567.89', '1,234,567.89'];
        yield 'a sub-cent total is rounded to the cent — it is a total, not a rate' => ['0.917', '0.92'];
        yield 'zero' => ['0', '0.00'];
        yield 'negative' => ['-5.25', '-5.25'];
        yield 'a negative rounding to nothing loses the sign, as number_format has always done'
            => ['-0.001', '0.00'];
        yield 'the width of decimal(12,2): ten integer digits and two decimals'
            => ['1234567890.12', '1,234,567,890.12'];
    }

    #[DataProvider('amounts')]
    public function testMoneyTotal(string $stored, string $expected): void
    {
        self::assertSame($expected, $this->numbers->amount($stored));
    }

    // ── null: not zero, and not a placeholder either ────────────────────────────────────────────

    /**
     * An unpriced quote line (#254/#255) must never read `0.00`, which on a customer-facing
     * document is indistinguishable from a real price of zero — a figure this application does
     * legitimately store.
     */
    public function testNullIsBlankAndNeverZero(): void
    {
        self::assertSame('', $this->numbers->qty(null));
        self::assertSame('', $this->numbers->price(null));
        self::assertSame('', $this->numbers->amount(null));
        self::assertSame('', $this->numbers->price(''));
        self::assertSame('', $this->numbers->amount(''));
    }

    /**
     * Zero is a figure and null is not. The pair is the whole point of the rule above.
     */
    public function testZeroIsNotNull(): void
    {
        self::assertSame('0.00', $this->numbers->amount('0'));
        self::assertSame('0.00', $this->numbers->amount(0));
        self::assertSame('', $this->numbers->amount(null));
    }

    /**
     * The placeholder is the template's to choose — `TBD` on a quote, `No pricing` on the estimate
     * form, an em dash on a fee row — so the filter renders nothing and the template's own
     * `is not null` guard decides. What it must never do is invent a number.
     */
    public function testAValueThatIsNotANumberIsReturnedRatherThanBecomingZero(): void
    {
        self::assertSame('TBD', $this->numbers->amount('TBD'));
        self::assertSame('n/a', $this->numbers->price('n/a'));
    }

    // ── contexts ────────────────────────────────────────────────────────────────────────────────

    public function testTheCompactContextDropsTheDecimalsAndKeepsTheSeparator(): void
    {
        self::assertSame('12,346', $this->numbers->amount('12345.67', DisplayNumberContext::Compact));
        self::assertSame('12,346', $this->numbers->amount('12345.67', 'compact'));
        self::assertSame('500', $this->numbers->amount('500', 'compact'));
    }

    /**
     * Passing nothing must stay the common case, or the argument has replaced the rule.
     */
    public function testTheDefaultIsTheRuleItself(): void
    {
        self::assertSame('12,345.67', $this->numbers->amount('12345.67'));
        self::assertSame('2.5', $this->numbers->qty('2.5'));
        self::assertSame('0.917', $this->numbers->price('0.917'));
    }

    /**
     * A mistyped context is the same class of silently-wrong figure this service exists to end, so
     * it throws and names the contexts that exist rather than falling back to the default.
     */
    public function testAnUnknownContextThrowsAndSaysWhatIsAvailable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown number formatting context "pdf"');
        $this->expectExceptionMessage('"compact"');

        $this->numbers->amount('1', 'pdf');
    }

    // ── the rules are the columns' own scales ───────────────────────────────────────────────────

    /**
     * The three maxima are `LineDenomination`'s scale constants, which are what the columns are
     * declared at. Asserted here so that widening a column and forgetting the display is a failing
     * test rather than a figure quietly rounded at the old scale.
     */
    public function testTheRulesTrackTheColumnScales(): void
    {
        // A value with one more decimal than the column holds is rounded to the column, never past.
        self::assertSame(4, LineDenomination::QUANTITY_SCALE);
        self::assertSame('0.1235', $this->numbers->qty('0.12345'));

        self::assertSame(6, LineDenomination::RATE_SCALE);
        self::assertSame('0.123457', $this->numbers->price('0.1234567'));

        self::assertSame(2, LineDenomination::MONEY_SCALE);
        self::assertSame('0.12', $this->numbers->amount('0.123'));
    }

    /**
     * Where the float underneath stops being exact, pinned rather than hoped for.
     *
     * `decimal(18,6)` can hold eighteen significant digits and a PHP float carries about fifteen, so
     * a price at the very top of the column renders as the nearest double. Every price this
     * application has ever stored is many orders of magnitude below it, and every existing call site
     * already went through `number_format((float) $value, …)`, so this is the behaviour that was
     * there before — recorded here so nobody discovers it as a surprise.
     */
    public function testThePrecisionLimitOfTheFloatIsWhereTheColumnOutrunsADouble(): void
    {
        // Nine integer digits and six decimals — fifteen significant figures — is still exact.
        self::assertSame('999,999,999.999999', $this->numbers->price('999999999.999999'));

        // Eighteen is not: the double rounds up to a round trillion, whose six decimal places are
        // then all zero and are trimmed to the two the money floor keeps.
        self::assertSame('1,000,000,000,000.00', $this->numbers->price('999999999999.999999'));
    }
}
