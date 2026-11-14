<?php

declare(strict_types=1);

namespace App\Maxeme\Enum;

/** What an odometer reading is in (mockup 986d771): kilometres unless the vehicle says miles. */
enum MileageUnit: string
{
    case Km = 'km';
    case Miles = 'miles';

    /** "KM" / "MILES", as printed after the reading. */
    public function label(): string
    {
        return match ($this) {
            self::Km => 'KM',
            self::Miles => 'MILES',
        };
    }

    /** A posted or stored value, blank or unknown meaning KM. */
    public static function fromValue(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Km;
    }
}
