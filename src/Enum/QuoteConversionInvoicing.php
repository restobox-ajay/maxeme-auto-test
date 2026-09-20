<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Whether converting a quote also raises the invoice for the order it produces.
 *
 * The service used to decide this for everybody: convert() always called
 * OrderInvoicingService::invoiceInFull(), so every accept path billed the customer the moment the
 * quote became an order. That is right for the CUSTOMER's own accept — one click, one order, one
 * invoice, all in one transaction — and wrong for the admin side, where accepting a quote, raising
 * the sales order and billing it are three deliberate steps a staff member takes in order.
 *
 * So the caller says. Stated as an enum rather than a bool because `convert($estimate, $em, $name,
 * false)` says nothing at the call site about what is being switched off, and what is being
 * switched off here is a document the customer is expected to pay.
 *
 * This does NOT change WHERE the invoice is raised, only WHETHER. The call inside convert() keeps
 * its position — after the shortfall check has settled whether the order is approved, after the
 * backorder splits have been applied to the lines, persisted and never flushed — because that
 * ordering is what stops a held-back order billing for stock that is not there. See the comments
 * around EstimateConversionService::convert()'s invoicing block.
 */
enum QuoteConversionInvoicing
{
    /**
     * Bill the whole order as part of the conversion, in the same transaction (#539 stage 1).
     * The customer's own accept, and the default, so no caller that never thought about this
     * changes behaviour.
     */
    case RaiseInvoice;

    /**
     * Produce the order and stop there. Invoicing is a separate, later action someone takes against
     * the order itself — `admin_invoice_create (with order_id)` on the order screen.
     */
    case NoInvoice;
}
