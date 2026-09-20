<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AppSetting;
use App\Service\AppSettings;
use App\Service\BusinessDate;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

/**
 * The reason this class exists rather than these methods staying on AppSettings: with the clock
 * injected, the instant can be pinned, so the tests below can stand at the exact place the
 * behaviour is decided — the hour on either side of UTC midnight — instead of describing it from a
 * distance.
 *
 * The previous tests, on AppSettings, could only assert that two far-apart zones disagreed with
 * each other. That catches an implementation that ignores the setting entirely and nothing else: it
 * never states which date either zone should have produced, so an off-by-one, a conversion applied
 * in the wrong direction, or a date read before the zone was applied would all still pass. With a
 * frozen clock the expected strings are just written down.
 *
 * AppSettings is final, so it is built for real over a stubbed repository rather than doubled —
 * the same approach tests/Service/AppSettingsTest.php and tests/Twig/AppSettingsExtensionTest.php
 * take. Only timezone() is ever reached through it here.
 */
final class BusinessDateTest extends TestCase
{
    /**
     * Two zones on opposite sides of UTC, 25 hours apart, so they are never on the same calendar
     * date at any instant whatsoever.
     */
    private const FAR_EAST = 'Pacific/Kiritimati'; // UTC+14
    private const FAR_WEST = 'Pacific/Niue';       // UTC-11

    /**
     * 30 minutes before UTC rolls from the 6th to the 7th of August 2026 — the window the whole
     * refactor is about, and the one no test could previously reach.
     *
     * At this instant the shop is already on the 7th if it displays Kiritimati and still on the 6th
     * if it displays Niue, and an implementation that read the clock in UTC (or in PHP's ambient
     * default, which Kernel pins to UTC) would date every document the 6th in both. That is exactly
     * the production bug: a document stamped with a date the person raising it is not living in and
     * will not see on the screen.
     */
    private const NEAR_UTC_MIDNIGHT = '2026-08-06 23:30:00';

    public function testTodayIsTheDayAfterTheFrozenUtcDateInAZoneAheadOfUtc(): void
    {
        $businessDate = $this->businessDate(self::NEAR_UTC_MIDNIGHT, self::FAR_EAST);

        // 23:30 UTC on the 6th is 13:30 on the 7th in Kiritimati (UTC+14).
        self::assertSame('2026-08-07', $businessDate->today());
    }

    public function testTodayIsStillTheFrozenUtcDateInAZoneBehindUtc(): void
    {
        $businessDate = $this->businessDate(self::NEAR_UTC_MIDNIGHT, self::FAR_WEST);

        // 23:30 UTC on the 6th is 12:30 on the same day in Niue (UTC-11).
        self::assertSame('2026-08-06', $businessDate->today());
    }

    /**
     * The mirror image, taken half an hour later, so neither half of the boundary is asserted only
     * in the direction that happens to agree with UTC: once UTC is on the 7th, the zone behind it
     * is the one that disagrees.
     */
    public function testTodayIsTheDayBeforeTheFrozenUtcDateOnceUtcHasRolledOver(): void
    {
        // 00:30 UTC on the 7th is 13:30 on the 6th in Niue, and 14:30 on the 7th in Kiritimati.
        self::assertSame('2026-08-06', $this->businessDate('2026-08-07 00:30:00', self::FAR_WEST)->today());
        self::assertSame('2026-08-07', $this->businessDate('2026-08-07 00:30:00', self::FAR_EAST)->today());
    }

    /**
     * The same boundary as a real shop meets it. America/Vancouver is seven hours behind UTC in
     * August, so for those seven hours every evening the two are on different dates — this is the
     * window in which the constructor-stamped `new \DateTimeImmutable('today')` dated orders
     * tomorrow.
     */
    public function testTodayIsYesterdaysUtcDateForAShopDisplayingVancouverInTheEvening(): void
    {
        $businessDate = $this->businessDate('2026-08-07 04:30:00', 'America/Vancouver');

        // 04:30 UTC on the 7th is 21:30 on the 6th in Vancouver (PDT, UTC-7).
        self::assertSame('2026-08-06', $businessDate->today());
    }

    /**
     * The clock's own timezone is not the answer, even when it is a plausible one.
     *
     * A MockClock carries a zone and hands back a DateTimeImmutable in it, exactly as the native
     * clock hands back one in PHP's default. Both must be converted rather than formatted as they
     * arrive, or the date would follow whatever zone the clock happened to be constructed with.
     */
    public function testTodayConvertsTheClocksInstantRatherThanFormattingItInTheClocksOwnZone(): void
    {
        $clock = new MockClock(
            new \DateTimeImmutable(self::NEAR_UTC_MIDNIGHT, new \DateTimeZone('UTC')),
            new \DateTimeZone('America/Vancouver'),
        );
        $businessDate = new BusinessDate($clock, $this->settings([$this->makeSetting('timezone', self::FAR_EAST)]));

        self::assertSame('2026-08-07', $businessDate->today());
    }

    public function testTodayFallsBackToUtcWhenTheZoneIsUnset(): void
    {
        $businessDate = new BusinessDate($this->clock(self::NEAR_UTC_MIDNIGHT), $this->settings([]));

        self::assertSame('2026-08-06', $businessDate->today());
    }

    public function testTodayIsAPlainCalendarDateStringWithNoTimeOfDay(): void
    {
        $businessDate = $this->businessDate(self::NEAR_UTC_MIDNIGHT, 'America/Vancouver');

        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $businessDate->today());
    }

    public function testLocalDayRangeUtcConvertsAPositiveOffsetZone(): void
    {
        $businessDate = $this->businessDate(self::NEAR_UTC_MIDNIGHT, 'Asia/Kolkata');

        [$from, $to] = $businessDate->localDayRangeUtc(new \DateTimeImmutable('2026-08-06'));

        // Midnight IST (UTC+5:30) on the 6th is 18:30 UTC on the 5th.
        self::assertSame('2026-08-05 18:30:00', $from->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-06 18:30:00', $to->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $from->getTimezone()->getName());
    }

    /**
     * Proves the conversion uses real IANA DST rules rather than a fixed offset: the same zone's
     * UTC offset for midnight differs by an hour between these two dates (PST vs PDT).
     */
    public function testLocalDayRangeUtcHonoursDaylightSavingAcrossTheTransition(): void
    {
        $businessDate = $this->businessDate(self::NEAR_UTC_MIDNIGHT, 'America/Vancouver');

        [$winterFrom] = $businessDate->localDayRangeUtc(new \DateTimeImmutable('2026-01-15'));
        [$summerFrom] = $businessDate->localDayRangeUtc(new \DateTimeImmutable('2026-07-15'));

        self::assertSame('2026-01-15 08:00:00', $winterFrom->format('Y-m-d H:i:s')); // PST, UTC-8
        self::assertSame('2026-07-15 07:00:00', $summerFrom->format('Y-m-d H:i:s')); // PDT, UTC-7
    }

    public function testLocalDayRangeUtcIgnoresTheInputsOwnTimeOfDayAndTimezone(): void
    {
        $businessDate = $this->businessDate(self::NEAR_UTC_MIDNIGHT, 'UTC');

        $withTimeOfDay = new \DateTimeImmutable('2026-08-06 23:59:59', new \DateTimeZone('America/Vancouver'));
        [$from, $to] = $businessDate->localDayRangeUtc($withTimeOfDay);

        self::assertSame('2026-08-06 00:00:00', $from->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-07 00:00:00', $to->format('Y-m-d H:i:s'));
    }

    /**
     * The day range answers the configured zone and not the clock's: it never reads the clock at
     * all, and a frozen instant a year away from the day being asked about must not change it.
     */
    public function testLocalDayRangeUtcDoesNotDependOnTheCurrentInstant(): void
    {
        $expected = ['2026-08-06 07:00:00', '2026-08-07 07:00:00'];

        foreach (['2020-01-01 00:00:00', '2026-08-06 23:30:00', '2031-12-31 12:00:00'] as $now) {
            [$from, $to] = $this->businessDate($now, 'America/Vancouver')
                ->localDayRangeUtc(new \DateTimeImmutable('2026-08-06'));

            self::assertSame($expected, [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]);
        }
    }

    private function businessDate(string $nowUtc, string $timezone): BusinessDate
    {
        return new BusinessDate(
            $this->clock($nowUtc),
            $this->settings([$this->makeSetting('timezone', $timezone)])
        );
    }

    private function clock(string $nowUtc): MockClock
    {
        return new MockClock(new \DateTimeImmutable($nowUtc, new \DateTimeZone('UTC')));
    }

    /** @param list<AppSetting> $rows */
    private function settings(array $rows): AppSettings
    {
        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findBy')->willReturn($rows);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        return new AppSettings($em, new ArrayAdapter());
    }

    private function makeSetting(string $key, ?string $value): AppSetting
    {
        return (new AppSetting())->setSettingKey($key)->setName($key)->setSettingValue($value);
    }
}
