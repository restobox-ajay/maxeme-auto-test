<?php

declare(strict_types=1);

namespace App\Maxeme\Enum;

/** A part's type (legacy PartsFormType choices). The value is the stored key the grid shows. */
enum PartType: string
{
    case Unit = 'unit';
    case Kit = 'kit';
    case Consumables = 'consumables';

    public function label(): string
    {
        return match ($this) {
            self::Unit => 'Unit',
            self::Kit => 'Kit',
            self::Consumables => 'Consumables',
        };
    }
}
