<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\CalendarDateExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Extension\CoreExtension;
use Twig\Loader\ArrayLoader;

final class CalendarDateExtensionTest extends TestCase
{
    private function twig(string $displayTimezone): Environment
    {
        $twig = new Environment(new ArrayLoader([
            'calendar' => '{{ value|calendar_date }}',
            'calendar_with_format' => "{{ value|calendar_date('Y-m-d') }}",
            // The filter this replaces, rendered side by side so the difference between them is
            // the thing being asserted rather than something taken on trust.
            'builtin' => "{{ value|date('Y-m-d') }}",
        ]));
        // What DisplayTimezoneSubscriber does on every request and console command.
        $twig->getExtension(CoreExtension::class)->setTimezone($displayTimezone);
        $twig->addExtension(new CalendarDateExtension());

        return $twig;
    }

    public function testFormatsACalendarDateForReading(): void
    {
        self::assertSame('August 6, 2026', (new CalendarDateExtension())->format('2026-08-06'));
    }

    public function testTakesAnExplicitFormat(): void
    {
        self::assertSame('06/08/2026', (new CalendarDateExtension())->format('2026-08-06', 'd/m/Y'));
    }

    /**
     * The whole reason this filter exists.
     *
     * Rendered under a display timezone far from UTC, the date must come out as the date that was
     * stored. The zones below are the extremes an installation could plausibly be configured with,
     * and the value is midnight-adjacent by construction — a calendar date has no time of day, so
     * any implementation that gives it one and then converts lands on the day before or after.
     */
    #[DataProvider('displayTimezoneProvider')]
    public function testTheDateNeverShiftsUnderAnyDisplayTimezone(string $timezone): void
    {
        $rendered = $this->twig($timezone)->render('calendar_with_format', ['value' => '2026-08-06']);

        self::assertSame('2026-08-06', $rendered, 'shifted under ' . $timezone);
    }

    /** @return iterable<string, array{string}> */
    public static function displayTimezoneProvider(): iterable
    {
        yield 'UTC' => ['UTC'];
        yield 'the far west' => ['Pacific/Niue'];          // UTC-11
        yield 'north america' => ['America/Vancouver'];    // UTC-8/-7, observes DST
        yield 'a half-hour offset' => ['Asia/Kolkata'];    // UTC+5:30
        yield 'the far east' => ['Pacific/Kiritimati'];    // UTC+14
    }

    /**
     * And that the hazard being avoided is real, not theoretical.
     *
     * Twig's own |date filter, handed the same string under the same configured zone, is entitled
     * to move it — this pins the case that made the filter necessary rather than merely preferable.
     * If a future Twig stops converting naive strings this fails, which is the correct outcome: it
     * would mean the comment explaining why |date cannot be used has gone stale.
     */
    public function testTheBuiltInDateFilterIsTheThingBeingAvoided(): void
    {
        // A zone behind UTC, which is the direction that breaks: the stored midnight is still the
        // previous afternoon there, so the day printed is the day before the one that was stored.
        $twig = $this->twig('Pacific/Niue'); // UTC-11

        // Same date, same environment, one filter each — this is what the column used to hold on
        // the left and what it holds now on the right.
        $viaBuiltIn = $twig->render('builtin', ['value' => new \DateTimeImmutable('2026-08-06 00:00:00', new \DateTimeZone('UTC'))]);
        $viaFilter = $twig->render('calendar_with_format', ['value' => '2026-08-06']);

        self::assertSame('2026-08-05', $viaBuiltIn, '|date converted a UTC midnight into the display zone');
        self::assertSame('2026-08-06', $viaFilter);
    }

    #[DataProvider('blankProvider')]
    public function testABlankDateRendersAsNothing(?string $value): void
    {
        self::assertSame('', (new CalendarDateExtension())->format($value));
    }

    /** @return iterable<string, array{?string}> */
    public static function blankProvider(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'whitespace' => ["  \t "];
    }

    /**
     * A stored value that is not in the format is shown as it is, not reformatted into a plausible
     * different day. Someone reading the page should see the odd value, not a tidy lie about it.
     */
    public function testAnUnparseableValueIsShownVerbatim(): void
    {
        self::assertSame('not a date', (new CalendarDateExtension())->format('not a date'));
    }

    public function testTheFilterIsRegisteredUnderTheNameTemplatesUse(): void
    {
        $names = array_map(
            static fn (\Twig\TwigFilter $filter): string => $filter->getName(),
            (new CalendarDateExtension())->getFilters(),
        );

        self::assertSame(['calendar_date'], $names);
    }
}
