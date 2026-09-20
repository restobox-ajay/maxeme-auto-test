<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Draft: admin-only, not customer-visible (mirrors SalesOrder's existing Draft convention).
 * Submitted: customer (or admin) created it; at least one line/shipping/tax is still unresolved.
 * Priced: admin filled in the missing amounts and sent it back — now awaiting the customer.
 * Accepted: the customer agreed to it, either by clicking Accept themselves or by telling staff who
 *           recorded it for them. Locked/historical either way — no further edits, no status changes.
 *           It does NOT imply a SalesOrder exists: the customer's own accept converts in the same
 *           request, an admin's does not, so an admin-accepted quote sits here with
 *           Estimate::$convertedOrder still null until somebody presses Convert to Sales Order.
 * Rejected: customer rejected — terminal, no order created.
 */
enum EstimateStatus: string
{
    case Draft = 'Draft';
    case Submitted = 'Submitted';
    case Priced = 'Priced';
    case Accepted = 'Accepted';
    case Rejected = 'Rejected';

    /**
     * May a quote in this state still be edited (`HasStatus::canEditOnStatus()`)?
     *
     * Accepted and Rejected only, both terminal: an accepted quote is locked/historical by the
     * status's own definition above, and a rejected one created no order and is never revisited.
     */
    public function allowsEditing(): bool
    {
        return $this !== self::Accepted && $this !== self::Rejected;
    }
}
