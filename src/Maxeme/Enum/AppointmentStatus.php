<?php

declare(strict_types=1);

namespace App\Maxeme\Enum;

/**
 * An appointment's progress. The value is also the calendar event's CSS class, which carries its
 * colour (--mx-appt-* in maxeme-theme.css). Complete keeps the legacy value "complete" (shown as Completed).
 */
enum AppointmentStatus: string
{
    /** Booked. */
    case New = 'new';
    /** The vehicle was dropped off (Check-in). */
    case InProgress = 'in_progress';
    /** The vehicle needs to stay longer than expected, after it arrived. */
    case Extended = 'extended';
    /** The customer did not come. */
    case NoShow = 'no_show';
    /** Called off. */
    case Cancelled = 'cancelled';
    /** Set by hand, or when its repair order's work is completed. */
    case Complete = 'complete';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::InProgress => 'In Progress',
            self::Extended => 'Extended',
            self::NoShow => 'No Show',
            self::Cancelled => 'Cancelled',
            self::Complete => 'Completed',
        };
    }

    /** What it means, shown beside the status select. */
    public function description(): string
    {
        return match ($this) {
            self::New => 'booked',
            self::InProgress => 'vehicle dropped off',
            self::Extended => 'vehicle needs to stay longer than expected',
            self::NoShow => "didn't come",
            self::Cancelled => 'called it off',
            self::Complete => 'work done',
        };
    }

    /** Still to happen or under way: the profile's Pending Appointments. */
    public function isPending(): bool
    {
        return in_array($this, self::pending(), true);
    }

    /** Finished without work (cancelled, no-show): a repair order completing leaves it so. */
    public function isCalledOff(): bool
    {
        return $this === self::Cancelled || $this === self::NoShow;
    }

    /** @return list<self> */
    public static function pending(): array
    {
        return [self::New, self::InProgress, self::Extended];
    }
}
