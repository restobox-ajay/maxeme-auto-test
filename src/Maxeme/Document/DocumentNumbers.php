<?php

declare(strict_types=1);

namespace App\Maxeme\Document;

use App\Maxeme\Entity\Invoice;
use App\Maxeme\Entity\RepairOrder;
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

    /**
     * "INV-00001482"; blank while the invoice is not saved. A number typed in replaces it: the
     * invoice's own for the invoice, its repair order's work order number for the work order.
     */
    public function number(Invoice $invoice, DocumentKind $kind = DocumentKind::Invoice): string
    {
        $custom = match ($kind) {
            DocumentKind::Invoice => $invoice->getCustomNumber(),
            DocumentKind::WorkOrder => $invoice->getRepairOrder()?->getWorkOrderNumber(),
            DocumentKind::Quote => null,
        };

        return $custom ?? ($invoice->isSaved() ? $this->automaticNumber($invoice, $kind) : '');
    }

    /** The number it has when none is typed in: "INV-00001482" / "WO-00001482". */
    public function automaticNumber(Invoice $invoice, DocumentKind $kind = DocumentKind::Invoice): string
    {
        return $this->prefix($kind->prefixKey()) . $invoice->getDisplayedId();
    }

    /**
     * "RO-00000042", or its quote's "QO-00000042" / work order's "WO-00000042" (a repair order has
     * one of each, numbered as it is; a work order number typed in replaces the automatic one); blank
     * while the repair order is not saved.
     */
    public function repairOrderNumber(RepairOrder $repairOrder, ?DocumentKind $kind = null): string
    {
        if ($repairOrder->getId() === null) {
            return '';
        }
        if ($kind === DocumentKind::WorkOrder && $repairOrder->getWorkOrderNumber() !== null) {
            return $repairOrder->getWorkOrderNumber();
        }

        return $this->automaticRepairOrderNumber($repairOrder, $kind);
    }

    /** The number it has when none is typed in: "RO-00000042" / "QO-00000042" / "WO-00000042". */
    public function automaticRepairOrderNumber(RepairOrder $repairOrder, ?DocumentKind $kind = null): string
    {
        return $this->prefix($kind?->prefixKey() ?? MaxemeDocumentPrefixProvider::REPAIR_ORDER) . str_pad((string) $repairOrder->getId(), 8, '0', \STR_PAD_LEFT);
    }

    /** "INV-00001482.pdf" */
    public function filename(Invoice $invoice, DocumentKind $kind): string
    {
        return sprintf('%s.pdf', $this->number($invoice, $kind));
    }

    /** "Invoice INV-00001482" / "Work Order WO-00001482" */
    public function emailSubject(Invoice $invoice, DocumentKind $kind): string
    {
        return sprintf('%s %s', $kind->emailTitle(), $this->number($invoice, $kind));
    }
}
