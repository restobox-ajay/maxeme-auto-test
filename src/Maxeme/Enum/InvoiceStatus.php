<?php

declare(strict_types=1);

namespace App\Maxeme\Enum;

/** Whether an invoice is paid (legacy Invoice::STATUS_*). Paying it completes its appointment. */
enum InvoiceStatus: string
{
    case Paid = 'paid';
    case Unpaid = 'unpaid';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Paid',
            self::Unpaid => 'Unpaid',
        };
    }
}
