<?php

declare(strict_types=1);

namespace App\Maxeme\Enum;

/** How the call to the customer about a reminder went (mockup aa322bb): kept on the reminder or queue entry. */
enum PhoneStatus: string
{
    case NotCalled = 'not_called';
    case NoAnswer = 'no_answer';
    case Voicemail = 'voicemail';
    case Reached = 'reached';
    case WrongNumber = 'wrong_number';

    public function label(): string
    {
        return match ($this) {
            self::NotCalled => 'Not called',
            self::NoAnswer => 'No answer',
            self::Voicemail => 'Left voicemail',
            self::Reached => 'Reached',
            self::WrongNumber => 'Wrong number',
        };
    }
}
