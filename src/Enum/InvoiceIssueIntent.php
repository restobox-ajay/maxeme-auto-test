<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What a caller wants done to the invoice it is raising (#539 stage 2).
 *
 * Stage 1 read this off the order's own status: an order at 'On Hold' produced an invoice awaiting
 * payment, one at 'Pending' produced an issued invoice. Stage 2 rewrote SalesOrderStatus so the
 * order no longer says anything about payment or fulfilment — those questions are the invoice's
 * now — which leaves nothing for OrderInvoicingService to read. So the caller states it.
 *
 * That is a better shape anyway: it is the checkout that knows a card payment has not arrived yet,
 * not the order row, and inferring it back out of a status was always a re-derivation of something
 * the caller already knew.
 */
enum InvoiceIssueIntent
{
    /** Leave it Draft: inert, holds no stock, does not count toward the order's invoiced quantity. */
    case KeepDraft;

    /** Issue it outright — the goods are to be worked whether or not the money has arrived. */
    case Issue;

    /**
     * Issue it On Hold: placed, but waiting on an up-front payment that has not arrived. Holds no
     * stock, and is the only state the stale-unpaid sweep looks at.
     */
    case AwaitingPayment;
}
