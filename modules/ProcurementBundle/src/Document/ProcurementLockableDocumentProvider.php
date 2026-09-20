<?php

declare(strict_types=1);

namespace ProcurementBundle\Document;

use App\Contract\Document\LockableDocument;
use App\Contract\Document\LockableDocumentProviderInterface;
use ProcurementBundle\Entity\PurchaseOrder;
use ProcurementBundle\Entity\PurchaseOrderAddress;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\VendorBill;
use ProcurementBundle\Entity\VendorBillAddress;
use ProcurementBundle\Entity\VendorBillLine;
use ProcurementBundle\Entity\VendorBillPaymentApplication;

/**
 * Makes `PurchaseOrder` and `VendorBill` lockable documents (#759), following
 * `App\Contract\Document\LockableDocumentProviderInterface`'s four-step recipe: this declares them,
 * `PurchaseOrderController`/`VendorBillController` add the lock/unlock routes, and each document's
 * own `_tab_nav.html.twig` wires the control in.
 *
 * The buy-side mirror of Order and Invoice, not of every purchase document — `DebitMemo` and
 * `RfqVendorReply` also extend `AbstractPurchaseDocument` and are deliberately absent, the same way
 * `CreditMemo` and `SalesReturn` stay off the sell side's lock despite extending
 * `AbstractSalesDocument`. Lockability was never inherited from the abstract superclass on either
 * side; it is named per document, on purpose, here.
 *
 * `document_lock` is one shared table keyed by `(document_type, document_id)` — no migration, no new
 * table, exactly the same reason a fifth sell-side document would not need one either.
 */
final class ProcurementLockableDocumentProvider implements LockableDocumentProviderInterface
{
    /** @return list<LockableDocument> */
    public function lockableDocuments(): array
    {
        return [
            new LockableDocument(
                typeKey: 'purchase_order',
                documentClass: PurchaseOrder::class,
                labelPrefix: 'PO',
                numberAccessor: 'getDocumentNumber',
                owners: [
                    PurchaseOrderLine::class => 'getPurchaseOrder',
                    PurchaseOrderAddress::class => 'getPurchaseOrder',
                ],
            ),
            new LockableDocument(
                typeKey: 'vendor_bill',
                documentClass: VendorBill::class,
                labelPrefix: 'Bill',
                numberAccessor: 'getDocumentNumber',
                owners: [
                    VendorBillLine::class => 'getBill',
                    VendorBillAddress::class => 'getBill',
                    // VendorBillPaymentApplication, not VendorBillPayment — the buy-side mirror of
                    // InvoicePaymentApplication in CoreLockableDocumentProvider, for the identical
                    // reason: a payment pool can span several bills, so the claim against ONE bill —
                    // not the pool itself — is what changes what THAT bill is.
                    VendorBillPaymentApplication::class => 'getBill',
                ],
            ),
        ];
    }
}
