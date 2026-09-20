<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Contract\Document\DocumentPrefix;
use App\Contract\Document\DocumentPrefixProviderInterface;
use App\Service\CreditMemoNumberGenerator;
use App\Service\EstimateNumberGenerator;
use App\Service\InvoiceNumberGenerator;
use App\Service\OrderNumberGenerator;
use App\Service\SalesReturnNumberGenerator;

/**
 * Core's own five document prefixes, declared the same way a bundle declares its own (#615).
 *
 * Core going through the seam rather than around it is the whole point. While the Document Prefixes
 * screen had a hard-coded branch for order, quote and invoice, "add a document type" meant "and
 * remember to edit ConfigController", which is what credit memos and sales returns both forgot —
 * they shipped a working `*_number_prefix` setting that no screen could set.
 *
 * **Every default is read from the generator that uses it**, never repeated here.
 * InvoiceNumberGenerator's docblock and ConfigController both used to carry a comment warning that
 * the two copies must be kept in step; a warning like that only exists because there are two
 * copies. There is now one, so there is nothing to keep in step.
 *
 * The `settingName` and `settingDescription` strings are the ones ConfigController already wrote,
 * carried over verbatim so that saving an existing prefix leaves the row exactly as it was.
 * "Order Prefix" is the one deliberate wording change (#615): with purchase orders in the system
 * "Order" is ambiguous, and the PO prefix is the very next field a user looks for.
 */
final class CoreDocumentPrefixProvider implements DocumentPrefixProviderInterface
{
    /** @return list<DocumentPrefix> */
    public function documentPrefixes(): array
    {
        return [
            new DocumentPrefix(
                key: 'order_number_prefix',
                label: 'Sales Order Prefix',
                defaultPrefix: OrderNumberGenerator::DEFAULT_PREFIX,
                documentPlural: 'sales orders',
                settingName: 'Order Number Prefix',
                settingDescription: 'Prefix for new order numbers (e.g. SO-123). Not retroactive - existing order numbers keep their prefix.',
            ),
            new DocumentPrefix(
                key: 'quote_number_prefix',
                label: 'Quote Prefix',
                defaultPrefix: EstimateNumberGenerator::DEFAULT_PREFIX,
                documentPlural: 'quotes',
                settingName: 'Quote Number Prefix',
                settingDescription: 'Prefix for new quote numbers (e.g. QO-123). Not retroactive - existing quote numbers keep their prefix.',
            ),
            new DocumentPrefix(
                key: 'invoice_number_prefix',
                label: 'Invoice Prefix',
                defaultPrefix: InvoiceNumberGenerator::DEFAULT_PREFIX,
                documentPlural: 'invoices',
                settingName: 'Invoice Number Prefix',
                settingDescription: 'Prefix for new invoice numbers (e.g. INV-123). Not retroactive - existing invoice numbers keep their prefix.',
            ),
            new DocumentPrefix(
                key: 'credit_memo_number_prefix',
                label: 'Credit Note Prefix',
                defaultPrefix: CreditMemoNumberGenerator::DEFAULT_PREFIX,
                documentPlural: 'credit notes',
                settingName: 'Credit Memo Number Prefix',
                settingDescription: 'Prefix for new credit note numbers (e.g. CN-123). Not retroactive - existing credit note numbers keep their prefix.',
            ),
            new DocumentPrefix(
                key: 'sales_return_number_prefix',
                label: 'Sales Return Prefix',
                defaultPrefix: SalesReturnNumberGenerator::DEFAULT_PREFIX,
                documentPlural: 'sales returns',
                settingName: 'Sales Return Number Prefix',
                settingDescription: 'Prefix for new sales return numbers (e.g. RMA-123). Not retroactive - existing sales return numbers keep their prefix.',
            ),
        ];
    }
}
