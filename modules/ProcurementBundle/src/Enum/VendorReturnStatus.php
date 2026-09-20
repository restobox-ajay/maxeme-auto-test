<?php

declare(strict_types=1);

namespace ProcurementBundle\Enum;

/**
 * A vendor return's state (#638) — the buy-side mirror of App\Enum\SalesReturnStatus, direction
 * reversed: goods are leaving OUR warehouse for the vendor's, not arriving at ours.
 *
 * The state SHAPE is identical on purpose — four of these five case names are the sales side's own
 * words — but "Received" would say the opposite of what happens here, so the physical-movement
 * state is named for what it actually is: Shipped.
 *
 * Requested   we intend to send something back. Nobody outside this building has agreed to
 *             anything and no goods have moved.
 * Authorised  the vendor has agreed to take it back — an RMA number obtained by phone or email,
 *             recorded in $notes. This is the number that goes on the outbound package.
 * Shipped     the goods have physically left. This is the stock-decrementing event — the mirror of
 *             SalesReturn::receive() — and $warehouse is a PARAMETER to ship(), not a setter called
 *             beforehand, for the identical reason: "the goods left" and "they left from here" are
 *             one fact.
 * Closed      the matter is finished. Whatever the debit memo was going to do has been decided.
 * Declined    this return is not happening. Reachable from Requested, Authorised and Shipped —
 *             see below for why the first of those three had to be added.
 *
 * There is no "Received" here and there will not be one added: whether the vendor's own warehouse
 * accepted the parcel is a fact about THEIR building, which this document has no way to observe and
 * must not fabricate.
 *
 * ## Declined is reachable from Requested, and that is the abandon route for a draft
 *
 * Declined originally started at Authorised, which left a Requested return — a DRAFT, agreed with
 * nobody, moving nothing — with exactly one exit: authorise it, then decline it. A document typed
 * against the wrong vendor or the wrong quantity had to be formally agreed with that vendor before
 * anybody was allowed to kill it, which is a worse outcome than the typo. Every other draft in this
 * application can be abandoned where it stands: PurchaseOrder::cancel() takes Draft, Rfq::cancel()
 * takes Draft, VendorBill::void() takes Draft, and on the sell side CreditMemo::void() takes Draft.
 *
 * There is deliberately no separate "Cancelled" for the draft case, for SalesReturnStatus' own
 * stated reason: two terminal not-happening states would put the operator in front of a choice with
 * no consequence attached to it. decline() records the from-state in $notes, so "was Requested"
 * (abandoned before anyone agreed) and "was Shipped" (refused at the vendor's dock) stay legible as
 * the different events they are without needing a second terminal case.
 *
 * What is NOT loosened: ship() still demands Authorised, so a draft can never move stock, and
 * close() still demands Shipped.
 */
enum VendorReturnStatus: string
{
    case Requested = 'Requested';
    case Authorised = 'Authorised';
    case Shipped = 'Shipped';
    case Closed = 'Closed';
    case Declined = 'Declined';

    /** Can lines still be edited? Only while nobody has agreed to anything. */
    public function allowsLineEditing(): bool
    {
        return $this === self::Requested;
    }

    /** Terminal states, which nothing moves out of. */
    public function isTerminal(): bool
    {
        return $this === self::Closed || $this === self::Declined;
    }
}
