<?php

declare(strict_types=1);

namespace App\Contract\Document;

use Doctrine\Common\Collections\Collection;

/**
 * What every commercial document has in common, whichever side of the business raised it (#555).
 *
 * The buy side and the sell side store their headers separately and deliberately:
 * `AbstractSalesDocument` is keyed to a `Company` and carries price list, fulfillment region and a
 * customer identity snapshot; a purchase order's counterparty is a `Vendor` and none of those
 * fields mean anything to it. A mapped superclass cannot parametrize `targetEntity` — which is
 * already why `lines`, `logs` and `payments` are excluded from `AbstractSalesDocument` — so a
 * shared ancestor could hold the scalars and specifically NOT the relationship that decides what
 * the document is. Thin base, full coupling.
 *
 * `poNumber` settles it on its own. On a sales document it is *the customer's* purchase order
 * reference; on a purchase order the PO number is the document's own identity. Same word, two
 * families.
 *
 * So: parallel storage, shared interface. Anything cross-cutting — PDF rendering, document
 * numbering, email, a unified document search — takes this rather than a concrete class, with no
 * Doctrine constraints and no pretending a vendor is a company. That is the house pattern; see the
 * rest of `src/Contract/`.
 *
 * ## The sell side joined in #636
 *
 * For its first year this was declared in core and implemented only by the two purchase documents:
 * core wrote the contract and never signed it. That asymmetry is what #636 closed. Nothing was
 * added to the database to do it — every method below is answered from state that was already
 * stored, which is why this is an interface change and not a migration.
 *
 * What each of the six maps onto on the sell side:
 *
 *  - `getDocumentNumber()` — three of the four `AbstractSalesDocument` subtypes already called it
 *    exactly that, and so does `SalesReturn`. `SalesOrder` alone stores `$orderNumber` (the column
 *    name is load-bearing: see the DQL footgun recorded on `AbstractSalesDocument`), so it, and
 *    only it, carries a one-line adapter.
 *  - `getDocumentDate()` / `getTotal()` / `getLines()` — already on `AbstractSalesDocument`.
 *  - `getCounterpartyName()` — the `CompanyIdentity` snapshot's name. A snapshot, exactly as the
 *    buy side's `$vendorName` is, so neither family answers this with a live join. `SalesReturn`
 *    is the one exception and says so on its own method: it has no snapshot to read.
 *  - `getCurrency()` — the one the sell side genuinely cannot answer. See the method.
 *
 * ## What is NOT a commercial document
 *
 * **A cart.** `Cart` shares `AbstractSalesDocument` with the four documents, so the adapters land
 * on it too, but it does not implement this and must not: it has no number, and its date is null
 * until something raises it as a document. The interface therefore sits on the concrete classes
 * rather than on the shared base — which is also the only way `getDocumentDate(): string` can be
 * promised at all, since the base's is nullable for the cart's sake.
 *
 * **A document that only moves goods.** `PurchaseReceipt` and `TransferOrder` have a number, a date
 * and lines and state no money owed by anybody: a transfer's two ends are warehouses this company
 * owns, so there is no counterparty, no currency and no total to report, and a receipt's money is
 * the `VendorBill` it will be matched against. Both are named, argued exclusions in
 * `App\Tests\Architecture\EveryDocumentDeclaresItsContractTest` — not silence, and not a '0.00'
 * invented to satisfy an interface. A goods/logistical contract to hold them (which a receipt would
 * implement ALONGSIDE this one, since goods received and not yet billed is a real liability) is
 * parked, not declined; that exclusion list is where it attaches.
 */
interface CommercialDocument
{
    /** This document's own identity — a PO number, a bill number, an invoice number. */
    public function getDocumentNumber(): string;

    /**
     * The calendar date the document is dated, as a plain 'Y-m-d' string.
     *
     * A string and not a date, for the reason AbstractSalesDocument::$documentDate is one: a
     * calendar date has no instant, and anything given a midnight it never had is entitled to be
     * converted across a timezone boundary and come back a day out.
     */
    public function getDocumentDate(): string;

    /**
     * The other party, as it was named when the document was raised — a snapshot, never a live join.
     *
     * `SalesReturn` is the one document that cannot keep that promise: #596 gave it a company
     * foreign key and no identity snapshot, and freezing one is a column. It says so on its own
     * method rather than quietly reading live where every other document reads frozen.
     */
    public function getCounterpartyName(): string;

    /**
     * ISO 4217, three letters — or null when the document does not state one of its own.
     *
     * Null is the sell side's honest answer and the reason this is nullable at all (#636). A
     * purchase document copies the vendor's currency onto itself when it is raised, so it always
     * knows; a sales document has no such column, because the sell side is single-currency and
     * names that currency once, app-wide, in the `base_currency` AppSetting. An entity cannot read
     * a setting, and the two answers available without one are both worse than null: a hardcoded
     * 'CAD' would state a currency the document was never raised in — and would disagree with the
     * 'USD' that `base_currency` actually defaults to — while a new column would be a schema
     * change, which #636 forbids and which would then need writing to on rows that already exist.
     *
     * So a consumer resolves null against `base_currency`, and the interface says which documents
     * it has to resolve for instead of pretending none of them do.
     */
    public function getCurrency(): ?string;

    /**
     * Decimal string, two places. Money is never a float here.
     *
     * Null where the document states no amount, which is two cases and neither of them is zero:
     *
     *  - an `Estimate` still being priced. Its subtotal/tax/total are nullable precisely so that
     *    "TBD" and "$0.00" stay different states while an admin prices a quote line by line;
     *  - a `SalesReturn`, permanently. An RMA authorises goods coming back and never states money —
     *    that is the `CreditMemo`, which may not exist yet, may never exist, and may be raised in a
     *    later period.
     *
     * Reporting '0.00' for either would say the document settled at zero, which is exactly what a
     * declined return and an unpriced quote would then be indistinguishable from.
     */
    public function getTotal(): ?string;

    /** @return Collection<int, object> the document's rows, in document order */
    public function getLines(): Collection;
}
