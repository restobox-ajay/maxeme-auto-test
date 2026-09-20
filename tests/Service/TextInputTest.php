<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\TextInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextInputTest extends TestCase
{
    public function testStripsAnEmbeddedNulByteWithoutTruncatingTheRest(): void
    {
        self::assertSame('POPO-123', TextInput::nullableString("PO\0PO-123"));
    }

    public function testStripsOtherC0ControlCharacters(): void
    {
        // \x01 (SOH), \x08 (backspace), \x1F (unit separator), \x7F (DEL)
        self::assertSame('abcd', TextInput::nullableString("a\x01b\x08c\x1Fd\x7F"));
    }

    /**
     * Only in the middle of the string: trim() already strips tab/newline/CR from the ends, exactly
     * as it did before this change — that isn't new behavior, so it isn't what this test is about.
     */
    public function testKeepsTabNewlineAndCarriageReturnInTheMiddleOfTheString(): void
    {
        self::assertSame("line one\r\nline two\ttabbed", TextInput::nullableString("line one\r\nline two\ttabbed"));
    }

    public function testTrimsSurroundingWhitespaceAfterStrippingControlCharacters(): void
    {
        self::assertSame('trimmed', TextInput::nullableString("  trimmed  \n"));
    }

    public function testNullForNullInput(): void
    {
        self::assertNull(TextInput::nullableString(null));
    }

    public function testNullForEmptyOrWhitespaceOnlyInput(): void
    {
        self::assertNull(TextInput::nullableString(''));
        self::assertNull(TextInput::nullableString('   '));
    }

    public function testNullWhenOnlyControlCharactersRemain(): void
    {
        self::assertNull(TextInput::nullableString("\x00\x01\x02"));
    }

    public function testCoercesNonStringScalars(): void
    {
        self::assertSame('123', TextInput::nullableString(123));
    }

    /**
     * A tampered post can turn a repeated field into a nested array (e.g. `lines[0][sku][]=x`
     * instead of `lines[0][sku]=x`). Casting an array straight to string is a PHP warning that some
     * environments promote to an uncaught error and a stack-trace 500 (#395), so this treats it the
     * same as a field that never arrived rather than stringifying it.
     */
    public function testNullForArrayInput(): void
    {
        self::assertNull(TextInput::nullableString(['unexpected', 'array']));
    }

    public function testNullableStringMaxTruncatesToTheGivenLength(): void
    {
        self::assertSame(str_repeat('a', 80), TextInput::nullableStringMax(str_repeat('a', 500), 80));
    }

    public function testNullableStringMaxLeavesAShorterValueUntouched(): void
    {
        self::assertSame('PO-123', TextInput::nullableStringMax('PO-123', 80));
    }

    public function testNullableStringMaxStillStripsControlCharactersBeforeTruncating(): void
    {
        self::assertSame('POPO-123', TextInput::nullableStringMax("PO\0PO-123", 80));
    }

    public function testNullableStringMaxIsNullForBlankInput(): void
    {
        self::assertNull(TextInput::nullableStringMax('   ', 80));
    }

    public function testOneLineStringMaxStripsAnEmbeddedLineFeed(): void
    {
        self::assertSame('PO123', TextInput::oneLineStringMax("PO\n123", 80));
    }

    public function testOneLineStringMaxStripsAnEmbeddedCarriageReturn(): void
    {
        self::assertSame('PO123', TextInput::oneLineStringMax("PO\r123", 80));
    }

    public function testOneLineStringMaxStripsAnEmbeddedCrlfPair(): void
    {
        self::assertSame('PO123', TextInput::oneLineStringMax("PO\r\n123", 80));
    }

    public function testOneLineStringMaxStillKeepsTab(): void
    {
        // Only newlines are single-line-hostile; a tab is still ordinary content, same as
        // nullableString() -- this method narrows one behavior, not the whole control-character set.
        self::assertSame("PO\t123", TextInput::oneLineStringMax("PO\t123", 80));
    }

    public function testOneLineStringMaxStillStripsControlCharactersAndTruncates(): void
    {
        self::assertSame('POPO-123', TextInput::oneLineStringMax("PO\0PO-123\nextra", 8));
    }

    public function testOneLineStringMaxIsNullForBlankInput(): void
    {
        self::assertNull(TextInput::oneLineStringMax("\n\r  ", 80));
    }

    /**
     * Pins the number itself. Every call site asserts against the constant rather than a literal,
     * which is what makes them robust to the value changing — and is exactly why one test has to
     * fail when it does, so a change to a limit the owner set is a deliberate edit here and not a
     * silent one somewhere else.
     */
    public function testDeliveryInstructionsCapIsFiveHundred(): void
    {
        self::assertSame(500, TextInput::DELIVERY_INSTRUCTIONS_MAX_LENGTH);
    }

    public function testCalendarDateAcceptsAWellFormedDate(): void
    {
        self::assertSame('2026-08-06', TextInput::calendarDate('2026-08-06'));
        self::assertSame('2026-08-06', TextInput::calendarDate('  2026-08-06  '));
    }

    /**
     * The reason this round-trips the parse instead of just checking createFromFormat() succeeded.
     *
     * createFromFormat('Y-m-d', '2026-02-31') does not fail — it rolls the overflow forward and
     * hands back March 3rd. Stored, that is not a rejected typo, it is a real date the admin never
     * chose, on an invoice, indistinguishable afterwards from one they did.
     *
     * @param string $value a date-shaped string naming a day that does not exist
     */
    #[DataProvider('impossibleCalendarDateProvider')]
    public function testCalendarDateRejectsADayThatDoesNotExist(string $value): void
    {
        self::assertNull(TextInput::calendarDate($value));
    }

    /** @return iterable<string, array{string}> */
    public static function impossibleCalendarDateProvider(): iterable
    {
        yield 'february 31st' => ['2026-02-31'];
        yield 'february 29th of a common year' => ['2026-02-29'];
        yield 'the 13th month' => ['2026-13-01'];
        yield 'day zero' => ['2026-08-00'];
    }

    #[DataProvider('nonCalendarDateProvider')]
    public function testCalendarDateRejectsAnythingNotInTheStoredFormat(mixed $value): void
    {
        self::assertNull(TextInput::calendarDate($value));
    }

    /** @return iterable<string, array{mixed}> */
    public static function nonCalendarDateProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace' => ["  \t "];
        yield 'null' => [null];
        yield 'american order' => ['08/06/2026'];
        yield 'unpadded' => ['2026-8-6'];
        yield 'a datetime' => ['2026-08-06 14:30:00'];
        yield 'prose' => ['tomorrow'];
        // Same reason nullableString() checks: a tampered form field arrives as an array, and
        // casting one to string is a warning and a 500, not a value (#395).
        yield 'an array' => [['2026-08-06']];
    }

    /** February 29th of a year that has one is a real date and must survive the strictness above. */
    public function testCalendarDateAcceptsALeapDay(): void
    {
        self::assertSame('2028-02-29', TextInput::calendarDate('2028-02-29'));
    }
}
