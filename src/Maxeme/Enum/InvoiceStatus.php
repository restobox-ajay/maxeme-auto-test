<?php

declare(strict_types=1);

namespace App\Maxeme\Enum;

/**
 * Where an invoice is (legacy Invoice::STATUS_* plus Cancelled): issued (not paid yet), paid, or
 * cancelled. Paying it completes its appointment. The issued value stays "unpaid", the legacy one.
 */
enum InvoiceStatus: string
{
    case Unpaid = 'unpaid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Issued',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
        };
    }

    /** The badge colour class (core's .badge variants). */
    public function badge(): string
    {
        return match ($this) {
            self::Unpaid => 'warn',
            self::Paid => 'success',
            self::Cancelled => 'danger',
        };
    }
}
