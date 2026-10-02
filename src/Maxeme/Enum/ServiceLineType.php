<?php

declare(strict_types=1);

namespace App\Maxeme\Enum;

/** What a service line is: the five things a service can be made of. */
enum ServiceLineType: string
{
    case Labour = 'labour';
    case Part = 'part';
    case Sublet = 'sublet';
    case GovtFee = 'govt_fee';
    case Discount = 'discount';

    public function label(): string
    {
        return match ($this) {
            self::Labour => 'Labour',
            self::Part => 'Parts',
            self::Sublet => 'Sublet',
            self::GovtFee => 'Gvt Fees',
            self::Discount => 'Discount',
        };
    }

    /** A discount is not a record: it is one negative amount. */
    public function hasItem(): bool
    {
        return $this !== self::Discount;
    }

    /** What the quantity counts. */
    public function quantityLabel(): string
    {
        return $this === self::Labour ? 'Hours' : 'Qty';
    }
}
