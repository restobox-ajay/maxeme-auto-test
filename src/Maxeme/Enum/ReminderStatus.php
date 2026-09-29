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
