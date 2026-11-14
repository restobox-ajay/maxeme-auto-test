<?php

declare(strict_types=1);

namespace App\Maxeme\Enum;

/** Where a follow-up reminder stands (legacy Reminder::STATUS_*). */
enum ReminderStatus: string
{
    case New = 'new';
    case Delayed = 'delayed';
    case Unavailable = 'unavailable';
    case Booked = 'booked';
    case Declined = 'declined';
    /** Dealt with (the Resolved button, mockup 1b7f972); Booked is a reminder an appointment took care of. */
    case Resolved = 'resolved';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** The badge colour class (core's .badge variants). */
    public function badge(): string
    {
        return match ($this) {
            self::New => 'info',
            self::Unavailable => 'danger',
            self::Delayed => 'warn',
            self::Booked, self::Resolved => 'success',
            self::Declined => 'plain',
        };
    }

    /** Still on the Reminder Todo's list. */
    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }

    /** @return list<self> */
    public static function open(): array
    {
        return [self::New, self::Unavailable, self::Delayed];
    }
}
