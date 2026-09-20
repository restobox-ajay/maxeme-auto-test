<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * A credit note's state (#586). Zoho Books' four, and deliberately not a fifth.
 *
 * The pair that carries the meaning is Open/Closed, and neither is typed in: a credit note is a
 * document with a BALANCE, and the balance decides which of the two it is. `CreditMemo::settle()`
 * is the one place that flips them, called from every method that can move the balance, so there
 * is no way to apply a credit and leave the status saying the money is still available.
 *
 * There is no Cancelled here, unlike InvoiceStatus, and no Deleted. A credit note that should not
 * have existed is Voided, which is the accounting word for it: the number is kept, the document
 * stays readable, and it holds nothing and applies nothing.
 *
 * There is also no Refunded. A refund is one of the two ways a balance leaves — applying it to an
 * invoice is the other — and both land in the same place, which is Closed. A status per exit route
 * would be a second, hand-maintained answer to a question the rows already answer.
 */
enum CreditMemoStatus: string
{
    /**
     * Written but not issued. Holds nothing and credits nothing.
     *
     * The counterpart of InvoiceStatus::Draft, and inert in exactly the same way: a draft note does
     * not net out of the invoice's inventory hold, does not put stock back, and cannot be applied
     * or refunded. Editing lines is only allowed here.
     */
    case Draft = 'Draft';

    /** Issued, with a balance still to spend. */
    case Open = 'Open';

    /** Issued, and its balance fully applied to invoices, refunded, or a mix of the two. */
    case Closed = 'Closed';

    /**
     * Reversed. Holds nothing, applies nothing, credits no quantity back.
     *
     * Terminal, and refused outright once anything has been applied or refunded — the same rule
     * Invoice::cancel() applies to an invoice with money against it, for the same reason: an
     * allocation that has happened is credited or refunded back, never withdrawn by a status
     * change that leaves the other document still believing it received something.
     */
    case Void = 'Void';

    /**
     * May a credit note in this state still be edited (`HasStatus::canEditOnStatus()`)?
     *
     * Draft only, per this class's own docblock: "Editing lines is only allowed here." Open and
     * Closed both mean the note has been issued — a balance is being tracked against it, or was —
     * and Void is terminal by construction.
     */
    public function allowsEditing(): bool
    {
        return $this === self::Draft;
    }
}
