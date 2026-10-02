<?php

declare(strict_types=1);

namespace App\Maxeme\Enum;

/** A custom line under a document's subtotal, above tax (a repair order's, an invoice's). */
enum DocumentChargeKind: string
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
