<?php

declare(strict_types=1);

namespace App\Maxeme\Enum;

/**
 * What an appointment is for (mockup 689a4a1, decided by Ken): a repair order's visit, or a direct
 * booking with no repair order (Bodywork, Buying/selling cars, Other). A repair appointment is
 * coloured by its status; the others always show their own colour, completed or not.
 */
enum AppointmentType: string
{
    case Repair = 'repair';
    case Bodywork = 'bodywork';
    case BuySell = 'buy_sell';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Repair => 'Repair order',
            self::Bodywork => 'Bodywork',
            self::BuySell => 'Buying/selling cars',
            self::Other => 'Other',
        };
    }

    /** The block colour on the calendar; null for a repair appointment, which takes its status colour. */
    public function colour(): ?string
    {
        return match ($this) {
            self::Repair => null,
            self::Bodywork => '#c58f9a',
            self::BuySell => '#728fae',
            self::Other => '#a3aab4',
        };
    }

    /** Only a repair appointment schedules a repair order. */
    public function hasRepairOrder(): bool
    {
        return $this === self::Repair;
    }

    /** Buying/selling cars and Other need no vehicle (mockup 9ba2951). */
    public function needsVehicle(): bool
    {
        return $this === self::Repair || $this === self::Bodywork;
    }
}
