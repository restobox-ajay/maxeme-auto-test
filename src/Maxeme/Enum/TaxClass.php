<?php

declare(strict_types=1);

namespace App\Maxeme\Enum;

/**
 * Which sales taxes a charge carries. Recorded on labour and government fees; invoices still tax
 * every line at the shop's GST and PST until tax classes are specified (see the project notes).
 */
enum TaxClass: string
{
    case GstPst = 'gst_pst';
    case GstOnly = 'gst';
    case Exempt = 'exempt';

    public function label(): string
    {
        return match ($this) {
            self::GstPst => 'GST + PST',
            self::GstOnly => 'GST only',
            self::Exempt => 'Exempt',
        };
    }
}
