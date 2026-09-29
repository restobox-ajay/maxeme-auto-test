<?php

declare(strict_types=1);

namespace App\Maxeme\Enum;

/** How an invoice was paid (legacy renderPaymentMethod), in the builder's and the report's order. */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Visa = 'visa';
    case Master = 'master';
    case Debit = 'debit';
    case Cheque = 'cheque';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Visa => 'Visa',
            self::Master => 'Master',
            self::Debit => 'Debit',
            self::Cheque => 'Cheque',
        };
    }

    /** The Summary Report's tabs after "All" (legacy order: Cash, Debit, Visa, Master, Cheque). */
    public static function reportOrder(): array
    {
        return [self::Cash, self::Debit, self::Visa, self::Master, self::Cheque];
    }
}
