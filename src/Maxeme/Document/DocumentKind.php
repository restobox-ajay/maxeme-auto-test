<?php

declare(strict_types=1);

namespace App\Maxeme\Document;

use App\Maxeme\Entity\Invoice;

/**
 * The two printable documents of an invoice (legacy invoice_print / invoice_pdf and
 * invoice_workorder / invoice_workorder_pdf), and how each is named and emailed.
 */
enum DocumentKind: string
{
    case Invoice = 'invoice';
    case WorkOrder = 'work_order';

    public function title(): string
    {
        return match ($this) {
            self::Invoice => 'Invoice',
            self::WorkOrder => 'Repair Order',
        };
    }

    /** "Invoice-00001482.pdf" / "Workorder-00001482.pdf" */
    public function filename(Invoice $invoice): string
    {
        return sprintf('%s-%s.pdf', $this === self::Invoice ? 'Invoice' : 'Workorder', $invoice->getDisplayedId());
    }

    /** "Invoice #00001482" / "Work Order #00001482" */
    public function emailSubject(Invoice $invoice): string
    {
        return sprintf('%s #%s', $this === self::Invoice ? 'Invoice' : 'Work Order', $invoice->getDisplayedId());
    }

    /** The document's body template, shared by the page and the PDF. */
    public function template(): string
    {
        return match ($this) {
            self::Invoice => 'maxeme/invoice/_invoice_document.html.twig',
            self::WorkOrder => 'maxeme/invoice/_work_order_document.html.twig',
        };
    }

    /** What the email says: "the attached invoice" / "the attached workorder" (legacy wording). */
    public function emailNoun(): string
    {
        return $this === self::Invoice ? 'invoice' : 'workorder';
    }
}
