<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Contract\Document\LockableDocument;
use App\Contract\Document\LockableDocumentProviderInterface;
use App\Entity\CustomFieldValueOrder;
use App\Entity\DocumentLock;
use App\Entity\Estimate;
use App\Entity\EstimateAddress;
use App\Entity\EstimateLine;
use App\Entity\Invoice;
use App\Entity\InvoiceAddress;
use App\Entity\InvoiceLine;
use App\Entity\InvoicePaymentApplication;
use App\Entity\SalesOrder;
use App\Entity\SalesOrderAddress;
use App\Entity\SalesOrderLine;

/**
 * Core's own three lockable documents, declared the same way a bundle declares its own (#759).
 *
 * This is `DocumentLockService::TYPES` and `::OWNERS` collapsed into one, going through the seam
 * rather than around it — the point being that there is no hardcoded branch left for a fourth core
 * document, or a bundle's document, to be missing from.
 *
 * `DocumentLock::TYPE_ESTIMATE`/`TYPE_SALES_ORDER`/`TYPE_INVOICE` stay as the stored constants —
 * they are `document_lock.document_type` values written into existing rows, not something this
 * provider is free to rename.
 */
final class CoreLockableDocumentProvider implements LockableDocumentProviderInterface
{
    /** @return list<LockableDocument> */
    public function lockableDocuments(): array
    {
        return [
            new LockableDocument(
                typeKey: DocumentLock::TYPE_ESTIMATE,
                documentClass: Estimate::class,
                labelPrefix: 'Quote',
                numberAccessor: 'getDocumentNumber',
                owners: [
                    EstimateLine::class => 'getEstimate',
                    EstimateAddress::class => 'getEstimate',
                ],
            ),
            new LockableDocument(
                typeKey: DocumentLock::TYPE_SALES_ORDER,
                documentClass: SalesOrder::class,
                labelPrefix: 'Order',
                // Not 'getDocumentNumber' — SalesOrder's own printed number is getOrderNumber(),
                // matching what DocumentLockService::labelFor() called before this provider existed.
                numberAccessor: 'getOrderNumber',
                owners: [
                    SalesOrderLine::class => 'getOrder',
                    SalesOrderAddress::class => 'getOrder',
                    CustomFieldValueOrder::class => 'getOrder',
                ],
            ),
            new LockableDocument(
                typeKey: DocumentLock::TYPE_INVOICE,
                documentClass: Invoice::class,
                labelPrefix: 'Invoice',
                numberAccessor: 'getDocumentNumber',
                owners: [
                    InvoiceLine::class => 'getInvoice',
                    InvoiceAddress::class => 'getInvoice',
                    // InvoicePaymentApplication, not InvoicePayment (#708): a payment pool can span
                    // several invoices, so the claim against ONE invoice — not the pool itself — is
                    // what changes what THAT invoice is. See DocumentLockService's own docblock.
                    InvoicePaymentApplication::class => 'getInvoice',
                ],
            ),
        ];
    }
}
