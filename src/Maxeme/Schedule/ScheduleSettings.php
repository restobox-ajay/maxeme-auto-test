<?php

declare(strict_types=1);

namespace App\Maxeme\Schedule;

use App\Service\AppSettings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The appointment calendar's working day (`maxeme.schedule`) in the shop's timezone (core's
 * `timezone` setting), and the conversions between shop-local calendar times and the UTC times
 * the database stores.
 */
final class ScheduleSettings
{
    public readonly string $dayStart;
    public readonly string $dayEnd;
    public readonly int $slotMinutes;
    public readonly int $busySlotCount;
    public readonly float $defaultDurationHours;

    /** @param array{day_start: string, day_end: string, slot_minutes: int, busy_slot_count: int, default_duration_hours: int|float} $schedule */
    public function __construct(
        #[Autowire(param: 'maxeme.schedule')]
        array $schedule,
        private readonly AppSettings $appSettings,
    ) {
        $this->dayStart = $schedule['day_start'];
        $this->dayEnd = $schedule['day_end'];
        $this->slotMinutes = (int) $schedule['slot_minutes'];
        $this->busySlotCount = (int) $schedule['busy_slot_count'];
        $this->defaultDurationHours = (float) $schedule['default_duration_hours'];
    }

    public function timezone(): \DateTimeZone
    {
        return $this->appSettings->timezone();
    }

    /** A calendar time ("2026-10-02 09:00:00" or ISO 8601, shop time unless it carries an offset) in UTC. */
    public function toUtc(string $localTime): \DateTimeImmutable
    {
        try {
            $time = new \DateTimeImmutable($localTime, $this->timezone());
        } catch (\Exception) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a date and time.', $localTime));
        }

        return $time->setTimezone(new \DateTimeZone('UTC'));
    }

    /** A stored (UTC) time as shop time. */
    public function toLocal(\DateTimeImmutable $time): \DateTimeImmutable
    {
        return $time->setTimezone($this->timezone());
    }

    /** Midnight at the start of $date (Y-m-d, shop time), in UTC. */
    public function dayStartsAt(string $date): \DateTimeImmutable
    {
        return $this->toUtc($date . ' 00:00:00');
    }
}
