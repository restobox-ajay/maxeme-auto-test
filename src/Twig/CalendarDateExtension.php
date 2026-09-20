<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Formats a calendar date — a plain 'Y-m-d' string — for display, without converting it.
 *
 * Twig's own |date filter cannot be used on these. DisplayTimezoneSubscriber sets the configured
 * display timezone on Twig's core extension, and |date applies it to whatever it is handed: a value
 * that never had a timezone acquires one, and a date rendered as anything other than 'Y-m-d' can
 * come out a day either side of its own midnight. Passing `false` as |date's timezone argument does
 * suppress that, but it puts the correctness of every calendar date on every template remembering
 * an argument, and a template that forgets is wrong only near midnight and only for some zones —
 * the kind of bug that is invisible in a test suite and reported months later as "the date is off
 * by one sometimes".
 *
 * So the conversion is not suppressed here, it is absent. The DateTimeImmutable is built in UTC and
 * formatted in the same expression; setTimezone() is never called on it and it never escapes this
 * method. There is no conversion step for a caller to get wrong.
 *
 * Blank in, blank out: an unset date renders as nothing rather than as a formatted epoch, which is
 * what |date does with an empty string. An unparseable value is returned verbatim — a stored value
 * that is somehow not 'Y-m-d' should be visible on the page, not silently reformatted into a
 * plausible-looking different day.
 */
final class CalendarDateExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('calendar_date', [$this, 'format']),
        ];
    }

    public function format(?string $date, string $format = 'F j, Y'): string
    {
        $date = trim((string) $date);
        if ($date === '') {
            return '';
        }

        // The `|` resets the parsed time to midnight, so a format string carrying one cannot pick
        // up whatever the current clock happens to read.
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d|', $date, new \DateTimeZone('UTC'));

        return $parsed instanceof \DateTimeImmutable ? $parsed->format($format) : $date;
    }
}
