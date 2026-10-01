<?php

declare(strict_types=1);

namespace App\Maxeme\Enum;

/** A service category's status, as wholesale's product categories have it. */
enum CategoryStatus: string
{
    case Visible = 'Visible';
    case Hidden = 'Hidden';

    public function toggled(): self
    {
        return $this === self::Visible ? self::Hidden : self::Visible;
    }
}
