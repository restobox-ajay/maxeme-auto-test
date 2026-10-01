<?php

declare(strict_types=1);

namespace App\Maxeme\Document;

/**
 * The two printable documents of an invoice (legacy invoice_print / invoice_pdf and
 * invoice_workorder / invoice_workorder_pdf). DocumentNumbers names and numbers them.
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

    /** The app_setting holding its number prefix (Config › Settings › Doc Prefixes); see DocumentNumbers. */
    public function prefixKey(): string
    {
        return match ($this) {
            self::Invoice => 'invoice_number_prefix',
            self::WorkOrder => MaxemeDocumentPrefixProvider::WORK_ORDER,
        };
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
