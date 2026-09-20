<?php

declare(strict_types=1);

namespace ProcurementBundle\Enum;

/**
 * Where a purchase order is in its life (#555).
 *
 * The buy-side mirror of `App\Enum\InvoiceStatus`, and typed at the column for the same reason
 * that one is: nothing predates this enum, so there are no legacy strings for the database to have
 * to hydrate.
 *
 * Two of the six are **derived** and four are **decided**, which is the distinction that matters:
 *
 *  - `PartiallyReceived` and `Received` are computed from the lines' received quantities by
 *    PurchaseOrderStatusDeriver. Nobody types them, and there is no action that sets them — the
 *    goods arriving is the event, and the status is a projection of it.
 *  - `Draft`, `Issued`, `Closed` and `Cancelled` are named actions on the entity. Each is a
 *    decision somebody makes, and each carries an actor and writes its own timeline entry.
 *
 * `Closed` on a short shipment is deliberately in the second group. "210 of the 240 arrived and the
 * rest never will" is a decision — chase it, cancel the remainder, write it off — and a system that
 * closed the PO on its own would be hiding money. See the plan's "Easy to get wrong".
 */
enum PurchaseOrderStatus: string
{
    /** Being written. Nothing has been sent to anybody and nothing is expected in. */
    case Draft = 'Draft';

    /** Sent to the vendor. This is what makes the goods expected, and what the arrivals view lists. */
    case Issued = 'Issued';

    /** Some lines have arrived, or some of some lines has. Derived, never typed. */
    case PartiallyReceived = 'Partially Received';

    /** Every line has had its full ordered quantity received. Derived, never typed. */
    case Received = 'Received';

    /**
     * Finished with, whatever arrived. The remainder is written off — a deliberate act, taken by a
     * person, with a reason on the timeline.
     */
    case Closed = 'Closed';

    /** Withdrawn before it was fulfilled. Terminal; the PO keeps its number forever. */
    case Cancelled = 'Cancelled';

    /**
     * Is this order still *expecting* goods — the question the screens ask.
     *
     * Drives the "Receive against this" button and the receiving screen's picker. Deliberately
     * narrower than refusesReceipts() below: a closed order is finished with, so nothing offers to
     * receive against it, and a late delivery is still *recordable* against it if one turns up.
     */
    public function acceptsReceipts(): bool
    {
        return $this === self::Issued || $this === self::PartiallyReceived || $this === self::Received;
    }

    /**
     * Is booking goods in against this order a contradiction — the question the service asks.
     *
     * Only two states are, and for different reasons:
     *
     *  - **Draft**: nothing has been ordered from anybody yet, so there is nothing for goods to
     *    have arrived against.
     *  - **Cancelled**: the document says nothing was ever ordered. PurchaseOrder::cancel() refuses
     *    once anything has arrived, so a cancelled order provably has no receipts, and attaching
     *    one now would make it contradict itself.
     *
     * **Closed is not one of them**, and that is the deliberate part. 210 of 240 arrived, the rest
     * was written off, and then the last 30 turn up anyway — which happens. Refusing would leave
     * the receiver recording real goods against no order at all, losing the attribution the
     * three-way match needs. Recording it does not reopen anything: applyDerivedStatus() refuses to
     * move into or out of a decided state, so the order stays Closed and the write-off stays a
     * write-off. What changes is that the line now says what actually arrived.
     */
    public function refusesReceipts(): bool
    {
        return $this === self::Draft || $this === self::Cancelled;
    }

    /** Is this order finished with — nothing further expected, whether or not everything came. */
    public function isFinal(): bool
    {
        return $this === self::Closed || $this === self::Cancelled;
    }

    /**
     * May an order in this state still be edited (`HasStatus::canEditOnStatus()`)? Everything but
     * final — `PurchaseOrder::isLockedForEditing()` used to state this pair directly; it now
     * delegates here, the same as the sell-side counterpart it was always deliberately mirroring.
     */
    public function allowsEditing(): bool
    {
        return !$this->isFinal();
    }

    /** The states the deriver is allowed to move between. Everything else it leaves alone. */
    public function isDerivable(): bool
    {
        return $this === self::Issued || $this === self::PartiallyReceived || $this === self::Received;
    }
}
