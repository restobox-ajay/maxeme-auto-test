<?php

declare(strict_types=1);

namespace App\Maxeme\Enum;

/** What a labour rate is charged per. */
enum LabourUnit: string
{
    case Hour = 'hour';
    case Each = 'each';

    public function label(): string
    {
        return match ($this) {
            self::Hour => 'Hours',
            self::Each => 'Ea',
        };
    }
}
