<?php

declare(strict_types=1);

namespace App\Maxeme\Enum;

/**
 * An appointment's progress (legacy Appointment::STATUS_*). The value is also the calendar event's
 * CSS class, which carries its colour (--mx-appt-* in maxeme-theme.css).
 */
enum AppointmentStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case Complete = 'complete';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::InProgress => 'In Progress',
            self::Complete => 'Complete',
        };
    }

    /** Not complete yet: the profile's Pending Appointments, and the ones that can be deleted. */
    public function isPending(): bool
    {
        return $this !== self::Complete;
    }

    /** @return list<self> */
    public static function pending(): array
    {
        return [self::New, self::InProgress];
    }
}
