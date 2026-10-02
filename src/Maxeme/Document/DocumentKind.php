<?php

declare(strict_types=1);

namespace App\Maxeme\Document;

/**
 * The printable documents: an invoice, a repair order's quote and its work order (legacy
 * invoice_print / invoice_pdf and invoice_workorder / invoice_workorder_pdf). DocumentNumbers names
 * and numbers them; a legacy invoice's own page renders template(), a PrintedDocument
 * printedTemplate().
 */
enum DocumentKind: string
{
    case Invoice = 'invoice';
    case Quote = 'quote';
    case WorkOrder = 'work_order';

    public function title(): string
    {
        return match ($this) {
            self::Invoice => 'Invoice',
            self::Quote => 'Quote',
            self::WorkOrder => 'Repair Order',
        };
    }

    /** What an email subject and a menu call it: "Invoice", "Quote", "Work Order". */
    public function emailTitle(): string
    {
        return $this === self::WorkOrder ? 'Work Order' : $this->title();
    }

    /** The app_setting holding its number prefix (Config › Settings › Doc Prefixes); see DocumentNumbers. */
    public function prefixKey(): string
    {
        return match ($this) {
            self::Invoice => 'invoice_number_prefix',
            self::Quote => 'quote_number_prefix',
            self::WorkOrder => MaxemeDocumentPrefixProvider::WORK_ORDER,
        };
    }

    /** The document's body template, shared by the page and the PDF. */
    public function template(): string
    {
        return match ($this) {
            self::Invoice, self::Quote => 'maxeme/invoice/_invoice_document.html.twig',
            self::WorkOrder => 'maxeme/invoice/_work_order_document.html.twig',
        };
    }

    /** A PrintedDocument's body template, shared by the page and the PDF. */
    public function printedTemplate(): string
    {
        return $this === self::WorkOrder ? 'maxeme/document/_work_order.html.twig' : 'maxeme/document/_sales_document.html.twig';
    }

    /** What the email says: "the attached invoice" / "the attached workorder" (legacy wording). */
    public function emailNoun(): string
    {
        return match ($this) {
            self::Invoice => 'invoice',
            self::Quote => 'quote',
            self::WorkOrder => 'workorder',
        };
    }
}
