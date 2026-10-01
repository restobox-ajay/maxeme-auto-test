<?php

declare(strict_types=1);

namespace App\Status;

use App\Contract\Status\StatusVocabularyProviderInterface;

/**
 * The status vocabularies for the documents core owns (handoff sections 3 and 4).
 *
 * Core is an ordinary provider here, not a hard-coded case the loader knows about. That is what
 * makes the loader's "whatever registered" behaviour testable: there is no special branch left for
 * an eleventh document type to fail to be added to. `DocumentPrefixCatalogue` made the same move
 * for the same reason.
 *
 * ## AMENDED — the `transitions` table is GONE, and this file is the record of that
 *
 * Every vocabulary below used to carry a `'transitions' => [from => [allowed targets]]` grid, and
 * the docblocks used to say each one had been *transcribed* from the verbs. The owner has ruled the
 * whole idea out (`STATUS-SEAM-HANDOFF.md`, ruling R4):
 *
 * > *"Map was a problem you invented. No one asked for it; no one wanted it, it does not work."*
 * > *"The logic was always bespoke because every status is so specific."*
 *
 * It was wrong on the evidence, in both directions at once. `sales_order` allowed
 * `Closed -> Approved`, which `approve()` refused; `estimate` carried `'Accepted' => []`, which made
 * an accepted quote unmovable — a restriction nobody asked for and which did not exist before the
 * quote was wired to this file. And it had no consumers outside the status layer: no screen read
 * `allowedTransitions()`, no controller read `canTransitionTo()`. Its only effect was to make
 * `setStatus()` refuse things.
 *
 * **A `from -> to` grid cannot express a rule that depends on the document's own facts**, and those
 * are most of them — an invoice holding payments, a credit note with applications against it, a
 * quote whose lines are not all priced, an order only a human may approve and only from Draft. The
 * authority is now the bespoke logic in each document's `setStatus()`, and there is no second
 * source.
 *
 * ## What this file IS, now that the rules have left it
 *
 * **A LIST OF FACTS.** Which statuses exist, what each one is called, and whether the deriver may
 * write it. Those are real and used — `statusLabel()`, `listStatuses()`, the status badge and the
 * status column, and `applyDerivedStatus()`'s refusal to write a status not marked `derived`.
 *
 * The list of facts stays. The table of rules is gone.
 *
 * **Scope is shape only.** No status is renamed and no status value changes. Nothing below has been
 * added or removed by the deletion of the grid — the statuses are exactly the ones that shipped.
 *
 * ## What is deliberately NOT here
 *
 * **Any rule at all.** Not "an invoice holding payments cannot be cancelled", not "only a Draft
 * order may be approved", not "a quote is not Priced until every line is". They live in the one
 * gate, `setStatus()`, on the document that owns them.
 *
 * **The initial state.** The entities keep their hardcoded field initialiser and there is no
 * `initial` key — the loader is a dictionary, not logic.
 *
 * **Master data.** `ProductCore`, `Company`, `Warehouse`, `Vendor`, `PriceList` and the other 20 are
 * out of scope and pick up a seam later, opportunistically, when somebody is next in that file.
 */
final class CoreStatusVocabularyProvider implements StatusVocabularyProviderInterface
{
    public function statusVocabularies(): array
    {
        return [
            'invoice' => $this->invoice(),
            'sales_order' => $this->salesOrder(),
            'estimate' => $this->estimate(),
            'credit_memo' => $this->creditMemo(),
            'sales_return' => $this->salesReturn(),
            'admin_user' => $this->adminUser(),
            'customer_user' => $this->customerUser(),
            'company' => $this->company(),
        ];
    }

    /**
     * The six statuses an invoice can hold.
     *
     * Nothing here is derived. `Invoice::$paymentStatus` IS derived, by
     * `InvoicePaymentStatusDeriver`, but it is a separate column answering a separate question —
     * this one is about whether the goods have gone, that one about whether the money arrived — and
     * it is not part of this vocabulary.
     *
     * Which moves between them are legal is NOT stated here and never will be again. It is in
     * `Invoice`'s own methods, which is the only place that can see the payment rows, the
     * over-invoicing guard and the from-state the caller meant.
     */
    private function invoice(): array
    {
        return [
            'statuses' => [
                'Draft' => 'Draft',
                'Pending' => 'Pending',
                'On Hold' => 'On Hold',
                'Processing' => 'Processing',
                'Completed' => 'Completed',
                'Cancelled' => 'Cancelled',
            ],
        ];
    }

    /**
     * The six statuses an order can hold, five of which the deriver may write.
     *
     * `SalesOrderStatus::derivable()` is the same five, and `SalesOrder::deriveStatus()` is what
     * computes which of them an order's invoices imply. **Void is not derived and is never derived
     * out of**: it is a judgement about the order that nothing about its invoices may undo, which is
     * why `deriveStatus()` answers null for a void order rather than merely declining to write Void.
     *
     * ## A note for whoever builds the button bar
     *
     * `Approved` is flagged derived AND is what a human sets by hand through
     * `setStatus('Approved', ...)`. A control that hid every move whose target is flagged derived
     * would therefore hide the Approve button, which is the one human transition on this document.
     * The flag means "the deriver may write this", not "no human may". See {@see StatusVocab}.
     *
     * The rules — only a Draft may be approved, a void order is final, an already-void order may not
     * be voided again — are in {@see \App\Entity\SalesOrder::setStatus()}. They were never
     * expressible here: the deriver has to be able to write `Approved` from every live state, so a
     * grid that permitted that could not also say "by a human, and only from Draft".
     */
    private function salesOrder(): array
    {
        return [
            'statuses' => [
                'Draft' => ['label' => 'Draft', 'derived' => true],
                'Approved' => ['label' => 'Approved', 'derived' => true],
                'Partially Invoiced' => ['label' => 'Partially Invoiced', 'derived' => true],
                'Invoiced' => ['label' => 'Invoiced', 'derived' => true],
                'Closed' => ['label' => 'Closed', 'derived' => true],
                'Void' => ['label' => 'Void'],
            ],
        ];
    }

    /**
     * The five statuses a quote can hold. Nothing is derived: every move is somebody pressing
     * something.
     *
     * **`Accepted` is movable again, and that matters.** When this file carried a grid it said
     * `'Accepted' => []`, which made an accepted quote unmovable by any means — a restriction that
     * did not exist before the quote was wired to the vocabulary, that nobody asked for, and that
     * broke a real test. Removing the grid restores what was true before it: the entity refuses
     * nothing, and what a quote may do is decided by the screens that own those decisions —
     * `Admin\EstimateController::updateStatus()` still declines to move an accepted quote from a
     * dropdown, which is where that judgement belongs and where it always was.
     *
     * The "you may not mark it Priced until every line is priced" rule is likewise not here. It is a
     * fact about the lines and it stays in the two controller guards that enforce it today.
     */
    private function estimate(): array
    {
        return [
            'statuses' => [
                'Draft' => 'Draft',
                'Submitted' => 'Submitted',
                'Priced' => 'Priced',
                'Accepted' => 'Accepted',
                'Rejected' => 'Rejected',
            ],
        ];
    }

    /**
     * The four statuses a credit note can hold.
     *
     * **Open and Closed are derived**, and by the same argument `SalesOrderStatus::derivable()`
     * makes: a credit note is a document with a BALANCE, and the balance decides which of the two it
     * is. `CreditMemo::settle()` is called from every method that can move the balance, so there is
     * no way to apply a credit and leave the status saying the money is still available.
     *
     * "A note with applications or refunds against it cannot be voided" is a fact about the
     * allocation rows and stays in `CreditMemo`'s own code.
     */
    private function creditMemo(): array
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
     * The five statuses a sales return can hold. Nothing is derived.
     *
     * Closed and Declined are both terminal (`SalesReturnStatus::isTerminal()`), and that fact lives
     * on the enum and in `SalesReturn`'s own methods rather than in a grid here.
     */
    private function salesReturn(): array
    {
        return [
            'statuses' => [
                'Requested' => 'Requested',
                'Authorised' => 'Authorised',
                'Received' => 'Received',
                'Closed' => 'Closed',
                'Declined' => 'Declined',
            ],
        ];
    }

    /**
     * The two statuses an admin staff account can hold. Nothing is derived — nothing on this
     * vocabulary is written by anything but an admin action.
     */
    private function adminUser(): array
    {
        return [
            'statuses' => [
                'Active' => 'Active',
                'Inactive' => 'Inactive',
                'Deleted' => 'Deleted',
            ],
        ];
    }

    /** The two statuses a customer account can hold. Same shape as admin_user, kept separate: a
     * customer's status and an admin's answer to different screens and, eventually, different rules
     * (a customer account is also gated by its company's status; an admin account never is).
     */
    private function customerUser(): array
    {
        return [
            'statuses' => [
                'Active' => 'Active',
                'Inactive' => 'Inactive',
            ],
        ];
    }

    /**
     * The three statuses a company can hold. `Review` is the interim state a self-registered
     * company sits in until an admin approves it (Customer\AuthController::register()) — a company
     * created by an admin directly skips it and starts Active.
     */
    private function company(): array
    {
        return [
            'statuses' => [
                'Active' => 'Active',
                'Inactive' => 'Inactive',
                'Review' => 'Review',
            ],
        ];
    }
}
