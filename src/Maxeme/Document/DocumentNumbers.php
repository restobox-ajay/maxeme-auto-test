<?php

declare(strict_types=1);

namespace App\Maxeme\Document;

use App\Maxeme\Entity\Invoice;
use App\Service\AppSettings;
use App\Service\Document\DocumentPrefixCatalogue;

/**
 * A document's number as shown, printed and emailed: its prefix (Config › Settings › Doc Prefixes)
 * and the invoice id padded to 8 digits, e.g. "INV-00001482" / "WO-00001482". The prefix is not
 * stored with the document, so changing it renumbers every document of that kind on screen.
 */
final class DocumentNumbers
{
    /** The Doc Prefixes screen's fields, in order. */
    public const PREFIX_KEYS = [
        MaxemeDocumentPrefixProvider::REPAIR_ORDER,
        MaxemeDocumentPrefixProvider::WORK_ORDER,
        'invoice_number_prefix',
        'quote_number_prefix',
    ];

    public function __construct(
        private readonly AppSettings $appSettings,
        private readonly DocumentPrefixCatalogue $prefixes,
    ) {
    }

    /** The prefix in force for an app_setting key: the saved one, else the registered default. */
    public function prefix(string $key): string
    {
        return $this->appSettings->get($key) ?: (string) $this->prefixes->get($key)?->defaultPrefix;
    }

    /** "INV-00001482"; blank while the invoice is not saved. */
    public function number(Invoice $invoice, DocumentKind $kind = DocumentKind::Invoice): string
    {
        return $invoice->isSaved() ? $this->prefix($kind->prefixKey()) . $invoice->getDisplayedId() : '';
    }

    /** "INV-00001482.pdf" */
    public function filename(Invoice $invoice, DocumentKind $kind): string
    {
        return sprintf('%s.pdf', $this->number($invoice, $kind));
    }

    /** "Invoice INV-00001482" / "Work Order WO-00001482" */
    public function emailSubject(Invoice $invoice, DocumentKind $kind): string
    {
        return sprintf('%s %s', $kind === DocumentKind::Invoice ? 'Invoice' : 'Work Order', $this->number($invoice, $kind));
    }
}
