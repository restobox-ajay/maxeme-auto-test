<?php

declare(strict_types=1);

namespace App\Maxeme\Enum;

/** Where a queued service reminder email stands (Schedule › Service Reminder Queue). */
enum ServiceReminderQueueStatus: string
{
    /** Waiting for its day; app:maxeme:send-service-reminders sends it then. */
    case Queued = 'queued';
    case Sent = 'sent';
    /** Another reminder of the same repair order goes out within the window: not sent. */
    case Skipped = 'skipped';
    /** Could not be sent (no email address, or the mailer failed). */
    case Error = 'error';
    /** The customer booked a new appointment for the vehicle: not sent. */
    case Booked = 'booked';
    /** Dealt with by hand. */
    case Resolved = 'resolved';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** The badge colour class (core's .badge variants). */
    public function badge(): string
    {
        return match ($this) {
            self::Queued => 'info',
            self::Sent, self::Booked, self::Resolved => 'success',
            self::Skipped => 'warn',
            self::Error => 'danger',
        };
    }
}
