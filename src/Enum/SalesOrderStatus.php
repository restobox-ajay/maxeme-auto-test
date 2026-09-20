<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * A sales order's invoicing state (#539 stage 2).
 *
 * The cases this enum used to carry — Pending, On Hold, Processing, Completed, Cancelled — were
 * never really the order's. They answered "have the goods gone" and "has the money arrived", which
 * are questions about an invoice, and they moved to InvoiceStatus in stage 1. What is left here is
 * the one question the order itself answers: how much of it has been invoiced.
 *
 * Only two of these are ever set by a human, through SalesOrder::setStatus('Approved', ...) and
 * SalesOrder::setStatus('Void', ...) — the one gate. The approve() and void() verbs that used to
 * carry those rules are gone; the rules moved into that gate (STATUS-SEAM-HANDOFF.md, R1 and R2).
 * The other four are DERIVED from the order's invoice set by SalesOrderStatusDeriver and
 * recalculated on every write to the order or to any of its invoices. A caller that wants one of
 * those does not name it — it calls `applyDerivedStatus()` and lets the order work it out, which is
 * the second of the two doors. See the SalesOrder docblock.
 *
 * SalesOrder.status stays a plain string column, not enum-typed at the database level, exactly as
 * it was before: rows carrying a value this enum no longer knows (the quote-era 'Waiting for
 * Quote'/'Accepted Quotes' strings, the pre-enum 'Approved'/'APPROVED' strays, and now the retired
 * fulfilment cases) still hydrate rather than blowing up on load. getStatusEnum() absorbs that by
 * returning null, and the deriver treats an unrecognised value as Draft.
 */
enum SalesOrderStatus: string
{
    /** Being written up. Not accepted, holds no stock, and nothing may be invoiced against it. */
    case Draft = 'Draft';

    /**
     * Accepted, nothing counting as invoiced yet. The one status a human sets to move an order
     * forward, and the gate that separates "still being typed up" from "we owe these goods".
     */
    case Approved = 'Approved';

    /** One or more counting invoices, but some ordered quantity is still uninvoiced. */
    case PartiallyInvoiced = 'Partially Invoiced';

    /** Every ordered quantity is invoiced, and at least one of those invoices is not fully paid. */
    case Invoiced = 'Invoiced';

    /** Fully invoiced and every invoice fully paid. There is nothing left to do. */
    case Closed = 'Closed';

    /**
     * Soft delete — out of reporting totals, never backdated away. Never derived: only a caller
     * naming it
     * writes it, and once written nothing derives the order back out of it.
     */
    case Void = 'Void';

    /**
     * Everything from Approved onward counts as approved, which is what lets "was this order
     * approved?" be answered from the status alone rather than from a second column that could
     * disagree with it.
     *
     * Void is deliberately excluded. A voided order WAS approved, but asking this question of one
     * only ever precedes deciding what to derive it to, and a voided order is never re-derived.
     */
    public function isApprovedOrLater(): bool
    {
        return $this !== self::Draft && $this !== self::Void;
    }

    /**
     * May an order in this state still be edited (`HasStatus::canEditOnStatus()`)?
     *
     * Closed and Void only — the same pair `OrderController::isOrderLockedForEditing()` used to
     * hardcode as lowercased strings. An Invoiced order is deliberately NOT here: a line can still
     * be added to it, which is precisely how it becomes Partially Invoiced again.
     */
    public function allowsEditing(): bool
    {
        return $this !== self::Closed && $this !== self::Void;
    }

    /**
     * The statuses SalesOrderStatusDeriver is allowed to write. Void is absent because voiding is a
     * judgement about the order rather than a consequence of its invoices, and Draft is present
     * because an order that has not been approved derives back to it.
     *
     * @return list<self>
     */
    public static function derivable(): array
    {
        return [self::Draft, self::Approved, self::PartiallyInvoiced, self::Invoiced, self::Closed];
    }
}
