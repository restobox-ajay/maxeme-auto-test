<?php

declare(strict_types=1);

namespace App\Maxeme\Enum;

/** A custom line under a repair order's subtotal, above tax. */
enum RepairOrderChargeKind: string
{
    case Fee = 'fee';
    case Discount = 'discount';

    public function label(): string
    {
        return match ($this) {
            self::Fee => 'Fee',
            self::Discount => 'Discount',
        };
    }
}
