<?php

declare(strict_types=1);

namespace App\Maxeme\Enum;

/**
 * Which invoice builder button saved the form: it decides only where the builder goes next. The
 * status follows the invoice's payments (Add payment, InvoicePayments::sync()), as in the mockup
 * (invoice-builder.html keeps the status on every button): no button marks an invoice paid.
 */
enum InvoiceSaveIntent: string
{
    /** Save: back to the builder (the printed invoice once paid). */
    case Save = 'save';
    /** Complete: to the printable invoice. */
    case Complete = 'complete';
    /** Work Order: to the work order. */
    case WorkOrder = 'work_order';
    /** Save as Quote: then the quote PDF (QUOTE on top). */
    case Quote = 'quote';
}
