<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Clock\ClockInterface;

/**
 * What day it is, and where one day starts and ends, from the shop's point of view.
 *
 * Both of these used to live on AppSettings, next to timezone(), because that is where the zone
 * comes from. That was the wrong seam. AppSettings answers "what is configured" — it reads the
 * app_setting table through the EntityManager and a cache pool, and every one of its answers is a
 * value somebody typed into a settings box. "What is today's date" is not that question: it is a
 * reading of a clock, and the configured zone is only the frame it is read in.
 *
 * Two concrete things went wrong while the two were mixed:
 *
 *  - Every consumer of today's date had to depend on AppSettings, and therefore transitively on
 *    Doctrine and a cache pool, to ask what amounts to time(). SalesDocumentDateStamp is a Doctrine
 *    entity listener; dragging the EntityManager into one of those is how dependency cycles start.
 *  - The clock could not be frozen, so the behaviour that actually matters here — what happens in
 *    the hours where UTC and the display zone are on different calendar days — was untestable. The
 *    best a test could do was assert that two far-apart zones disagreed with each other, which says
 *    nothing about which date either one produced. The midnight boundary, the only place this can
 *    be wrong, had no coverage at all.
 *
 * So the clock arrives as ClockInterface and AppSettings is consulted for the zone and nothing
 * else. In production that is Symfony's native clock; in a test it is a MockClock pinned to a
 * chosen instant, and BusinessDateTest asserts exact calendar dates on either side of a UTC
 * midnight.
 */
final class BusinessDate
{
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly AppSettings $settings,
    ) {
    }

    /**
     * Today's calendar date in the display timezone, as a 'Y-m-d' string.
     *
     * For dating a document, not for measuring an instant. PHP's ambient default is pinned to UTC
     * (see Kernel), which is right for everything stored as a moment and wrong for this: a shop
     * displaying America/Vancouver is still on the 5th for seven hours after UTC has rolled over to
     * the 6th, and an order raised in that window should say the 5th, because that is the date the
     * person raising it is living in and the date they will see on the screen.
     *
     * Returns a string rather than a DateTimeImmutable on purpose — handing back a date object
     * would re-introduce an instant for a later layer to convert, which is the whole problem this
     * avoids. See AbstractSalesDocument::$documentDate.
     */
    public function today(): string
    {
        return $this->clock->now()->setTimezone($this->settings->timezone())->format('Y-m-d');
    }

    /**
     * The [start, end) UTC instant range covering one calendar day in the display timezone.
     *
     * $day's own time-of-day and timezone are ignored — only its Y-m-d date parts are used.
     * Every "filter by date" search UI needs this: the admin types (or picks) a calendar day as
     * displayed, which is in timezone()'s zone, but the column being queried is stored in UTC —
     * comparing the typed day directly against it, with no conversion, misses or double-counts
     * rows near the boundary between the two zones' midnights.
     *
     * Lives here rather than on AppSettings even though it never reads the clock: it is the same
     * question as today() seen from the other side — where the shop's day begins and ends — and
     * splitting the two across services would leave a caller having to know that one kind of
     * day-boundary arithmetic is configuration and the other is not.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    public function localDayRangeUtc(\DateTimeImmutable $day): array
    {
        $utc = new \DateTimeZone('UTC');
        $localStart = new \DateTimeImmutable($day->format('Y-m-d') . ' 00:00:00', $this->settings->timezone());

        return [
            $localStart->setTimezone($utc),
            $localStart->modify('+1 day')->setTimezone($utc),
        ];
    }

    /**
     * The moment to record for a calendar date somebody picked in a date box ("when did this
     * happen"), as a UTC instant: today's date is now, the actual moment it is being recorded;
     * any other date is that day's start in the display timezone. Null for something that is not
     * a date.
     *
     * A bare `new \DateTimeImmutable('2026-11-21')` is midnight in PHP's ambient zone (UTC, see
     * Kernel), which a shop displaying America/Vancouver shows as 16:00 on the 20th: the day
     * before the one that was picked.
     */
    public function momentForLocalDate(string $date): ?\DateTimeImmutable
    {
        $zone = $this->settings->timezone();
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($date), $zone);
        if ($day === false || $day->format('Y-m-d') !== trim($date)) {
            return null;
        }

        if ($day->format('Y-m-d') === $this->today()) {
            return \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'));
        }

        return $day->setTimezone(new \DateTimeZone('UTC'));
    }
}
