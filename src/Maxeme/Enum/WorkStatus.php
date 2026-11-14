<?php

declare(strict_types=1);

namespace App\Maxeme\Enum;

/** How far the work on one service of a repair order is (mockup 169b599), set on the repair order page. */
enum WorkStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Done = 'done';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not started',
            self::InProgress => 'In progress',
            self::Done => 'Done',
        };
    }
}
