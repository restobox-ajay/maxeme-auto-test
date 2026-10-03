<?php

declare(strict_types=1);

namespace App\Maxeme\Enum;

use App\Maxeme\Dto\InvoiceData;

/**
 * Which invoice builder button saved the form (legacy saveInvoiceAndGoToPage): it decides the
 * status, as the legacy JS did by ticking or unticking Paid before posting.
 */
enum InvoiceSaveIntent: string
{
    /** Save: unpaid, back to the builder. */
    case Save = 'save';
    /** Complete: paid, to the printable invoice. */
    case Complete = 'complete';
    /** Work Order: the Paid box as it is, to the work order. */
    case WorkOrder = 'work_order';
    /** Save as Quote: the Paid box as it is, then the quote PDF (QUOTE on top). */
    case Quote = 'quote';

    public function status(InvoiceData $data): InvoiceStatus
    {
        return match ($this) {
            self::Save => InvoiceStatus::Unpaid,
            self::Complete => InvoiceStatus::Paid,
            self::WorkOrder, self::Quote => $data->paid ? InvoiceStatus::Paid : InvoiceStatus::Unpaid,
        };
    }
}
