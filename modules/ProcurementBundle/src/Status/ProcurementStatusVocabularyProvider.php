<?php

declare(strict_types=1);

namespace ProcurementBundle\Status;

use App\Contract\Status\StatusVocabularyProviderInterface;

/**
 * The status vocabularies for the buy-side documents this bundle owns (handoff section 4).
 *
 * They live here rather than in a core file for the reason the handoff gives: *"If purchase-order
 * statuses lived in a core file, switching the bundle off would leave a vocabulary for documents
 * that no longer exist."* The bundle contributes by tagging this service in its OWN
 * `config/services.yaml`, which core already imports — so core is not touched to add a buy-side
 * vocabulary, and nor would it be for a third bundle's.
 *
 * `Rfq` is deliberately absent. It was hidden from the menu at `3f602264` and parked on GitHub #662
 * pending a proper tender model; it picks up a vocabulary when #662 rebuilds it. `RfqVendorReply`
 * is a different document and is here.
 *
 * ## AMENDED — the `transitions` table is GONE
 *
 * Every vocabulary below used to carry a `'transitions' => [from => [allowed targets]]` grid
 * transcribed from the entity's own guards. The owner has ruled the whole idea out
 * (`STATUS-SEAM-HANDOFF.md`, ruling R4): a `from -> to` grid cannot express a rule that depends on
 * the document's own facts — a purchase order with goods already received, a bill with payments
 * against it — and those are most of them. What is left here is a LIST OF FACTS: which statuses
 * exist, what each is called, and whether the deriver may write it. The rules are in each entity's
 * own methods, which is where they always were.
 *
 * See {@see \App\Status\CoreStatusVocabularyProvider} for the full statement of that ruling.
 *
 * None of these documents is on the status seam yet — `PurchaseOrder`, `VendorBill`, `DebitMemo`,
 * `VendorReturn` and `RfqVendorReply` do not implement `HasStatus` — so nothing here was consulting
 * the grid even before it was deleted. The labels and `derived` flags below are what is used.
 */
final class ProcurementStatusVocabularyProvider implements StatusVocabularyProviderInterface
{
    public function statusVocabularies(): array
    {
        return [
            'purchase_order' => $this->purchaseOrder(),
            'vendor_bill' => $this->vendorBill(),
            'debit_memo' => $this->debitMemo(),
            'vendor_return' => $this->vendorReturn(),
            'rfq_vendor_reply' => $this->rfqVendorReply(),
        ];
    }

    /**
     * The six statuses a purchase order can hold. The moves between them belong to
     * `PurchaseOrder`'s own methods and to `PurchaseOrderStatusDeriver`; what follows describes
     * them for the reader and declares none of them.
     *
     * `issue()` Draft -> Issued; `closeShort()` takes Issued, Partially Received or Received ->
     * Closed; `cancel()` takes Draft or Issued -> Cancelled.
     *
     * `applyDerivedStatus()` moves only among the three states `PurchaseOrderStatus::isDerivable()`
     * names — Issued, Partially Received, Received — and refuses if either end is outside them. So
     * the derived moves are all-to-all across those three and stop at the boundary of a decided
     * state: goods turning up against a Closed order record what arrived and do not reopen it.
     *
     * `Closed` is a decision and not something the deriver does. "210 of the 240 arrived and the rest
     * never will" is a judgement — chase it, cancel the remainder, write it off — and a system that
     * closed the PO on its own would be hiding money.
     *
     * `cancel()` refusing an order that already has receipts is NOT here. That is a fact about the
     * receipt rows, and it stays in `cancel()`.
     *
     * As on `SalesOrder`, `Issued` is both flagged derived and written by hand by `issue()`.
     */
    private function purchaseOrder(): array
    {
        return [
            'statuses' => [
                'Draft' => 'Draft',
                'Issued' => ['label' => 'Issued', 'derived' => true],
                'Partially Received' => ['label' => 'Partially Received', 'derived' => true],
                'Received' => ['label' => 'Received', 'derived' => true],
                'Closed' => 'Closed',
                'Cancelled' => 'Cancelled',
            ],
        ];
    }

    /**
     * The statuses a vendor bill can hold. The moves between them belong to `VendorBill`'s own
     * methods and to `VendorBillStatusDeriver`; what follows describes them and declares none.
     *
     * `approve()` takes Draft or Disputed -> Open; `dispute()` takes the three states
     * `VendorBill::DISPUTABLE_FROM` names — Draft, Open, Partially Paid -> Disputed; `void()` takes
     * Draft, Open or Disputed -> Void.
     *
     * `applyDerivedStatus()` moves only among the three `VendorBillStatus::isDerivable()` names —
     * Open, Partially Paid, Paid — which is why a part payment against a DISPUTED bill leaves it
     * disputed: paying some of a bill does not settle an argument about it.
     *
     * Void is terminal. Paid has no human move out of it and only the deriver's, which is correct:
     * a bill goes back to Partially Paid if a payment is deleted, and there is no verb that
     * un-pays one.
     */
    private function vendorBill(): array
    {
        return [
            'statuses' => [
                'Draft' => 'Draft',
                'Open' => ['label' => 'Open', 'derived' => true],
                'Partially Paid' => ['label' => 'Partially Paid', 'derived' => true],
                'Paid' => ['label' => 'Paid', 'derived' => true],
                'Disputed' => 'Disputed',
                'Void' => 'Void',
            ],
        ];
    }

    /**
     * The statuses a debit memo can hold — the mirror of `CreditMemo`, with the
     * same shape and the same argument behind it.
     *
     * `issue()` Draft -> Open; `void()` any non-Void -> Void; `settle()` moves between Open and
     * Closed and accepts only those two as a starting point, because the BALANCE decides which of
     * the two it is.
     */
    private function debitMemo(): array
    {
        return [
            'statuses' => [
                'Draft' => 'Draft',
                'Open' => ['label' => 'Open', 'derived' => true],
                'Closed' => ['label' => 'Closed', 'derived' => true],
                'Void' => 'Void',
            ],
        ];
    }

    /**
     * The statuses a vendor return can hold. The moves are `VendorReturn`'s own.
     *
     * The buy-side mirror of `sales_return`, with one case renamed for the direction the goods
     * travel: the sell side calls its stock-moving state Received because goods arrive at our
     * building; here they leave it, so the same state is Shipped.
     *
     * `authorise()` Requested -> Authorised; `ship()` Authorised -> Shipped; `close()` Shipped ->
     * Closed; `decline()` takes any non-terminal state -> Declined. Closed and Declined are terminal.
     */
    private function vendorReturn(): array
    {
        return [
            'statuses' => [
                'Requested' => 'Requested',
                'Authorised' => 'Authorised',
                'Shipped' => 'Shipped',
                'Closed' => 'Closed',
                'Declined' => 'Declined',
            ],
        ];
    }

    /**
     * The statuses an RFQ vendor reply can hold. The moves are `RfqVendorReply`'s own.
     *
     * Prices may be taken from an Invited or an already-Replied reply, both landing on Replied — a
     * vendor may revise a quote until one is accepted. `decline()` and `markRejected()` both refuse
     * a terminal reply (`RfqVendorReplyStatus::isTerminal()`); `markAccepted()` is written only by
     * `RfqConversionService`, in the same atomic operation that claims the RFQ header, and
     * `markRejected()` is then written on every OTHER invited vendor's reply.
     *
     * Accepted, Rejected and Declined are terminal. Accepted is deliberately reachable from Invited
     * as well as Replied: `markAccepted()` carries no from-state guard of its own today, and this
     * map transcribes what is legal rather than tightening it.
     */
    private function rfqVendorReply(): array
    {
        return [
            'statuses' => [
                'Invited' => 'Invited',
                'Replied' => 'Replied',
                'Accepted' => 'Accepted',
                'Rejected' => 'Rejected',
                'Declined' => 'Declined',
            ],
        ];
    }
}
