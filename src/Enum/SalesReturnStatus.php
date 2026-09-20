<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * A sales return's state (#596). Dynamics' Sales Return Order lifecycle, with the receipt kept as a
 * status rather than promoted to a second document.
 *
 * ## Why five, and why this shape
 *
 * The whole reason #596 exists is that #586 could only express ONE point on this line: goods came
 * back AND a credit was issued, simultaneously, because `returned` rows existed only as a side
 * effect of `credit_memo.restock`. Every state below is a case that was previously inexpressible:
 *
 *   Requested   the customer says they want to send something back. Nobody has agreed yet, and no
 *               goods have moved. This is the ORDINARY case and it had no representation at all.
 *   Authorised  we agreed. This is the number the customer writes on the box, which is the entire
 *               practical purpose of the document — a parcel arriving with no reference is a parcel
 *               nobody can match to anything.
 *   Received    the goods are physically in the building, in `returned`, not sellable.
 *   Closed      the matter is finished. Whatever was going to happen to the money has happened.
 *   Declined    we are not crediting this. Out of warranty, not our fault, wrong item.
 *
 * ## Declined is reachable from three places and that is the point
 *
 * From Authorised, because a return can be refused before anything ships — the customer describes
 * the fault, it is plainly not covered, and the RMA is closed off without a parcel ever moving.
 *
 * From Received, because the goods arriving is not agreement that they are creditable. This is the
 * case #596 names explicitly: the item turns up, somebody opens the box, and it is the wrong item
 * or plainly customer damage. The units are then physically present, in `quarantine`, ours to store
 * and not ours to sell — and declining does NOT invent a disposition for them. See
 * SalesReturn::decline().
 *
 * From Requested, which was added later and is the abandon route for a draft. Declining originally
 * started at Authorised, which left a Requested return — agreed with nobody, moving nothing — with
 * exactly one exit: authorise it, then decline it. An RMA typed against the wrong customer had to be
 * formally agreed with that customer before anybody was allowed to kill it, which is a worse outcome
 * than the typo. Every other draft in this application can be abandoned where it stands:
 * `CreditMemo::void()` takes Draft, `Rfq::cancel()` takes Draft, `PurchaseOrder::cancel()` takes
 * Draft, `VendorBill::void()` takes Draft.
 *
 * The empty return is why this is a defect rather than an inconvenience. `authorise()` refuses a
 * return with no units on it, and `SalesReturnLine::getUnits()` rounds — so a line typed as 0.40
 * passes the editor's "at least one line" check and still sums to nothing. That document could not
 * be authorised, therefore could not be declined, therefore could not be closed, and there is no
 * delete route anywhere in this application for a document. It was permanent.
 *
 * The buy side's `VendorReturnStatus` had the identical shape and was fixed first, quoting the "no
 * Cancelled" argument below from here. This is the sell side closing that loop — core was not
 * already right and being conformed to.
 *
 * ## There is no "Credited"
 *
 * A credit note is a document with its own number, its own status and its own balance (#586). A
 * status here saying money happened would be a second, hand-maintained answer to a question
 * `credit_memo.sales_return_id` already answers, and the two would disagree the first time a note
 * was voided. Closed says the RETURN is finished; what the money did is the credit note's to say.
 *
 * ## There is no "Cancelled" either
 *
 * A return the customer changed their mind about is Declined, with the reason saying so. Two
 * terminal not-crediting states would put the operator in front of a choice with no consequence
 * attached to it, which is how a status set stops meaning anything.
 *
 * That argument is why declining from Requested was the fix rather than adding "Cancelled" for the
 * draft case. `SalesReturn::decline()` records the from-state in `notes`, so "was Requested" — never
 * agreed, nothing moved — and "was Received" — refused with the goods in the building — stay legible
 * as the different events they are without a second terminal case to choose between.
 */
enum SalesReturnStatus: string
{
    /** Asked for, not yet agreed. No goods have moved and none may be received. */
    case Requested = 'Requested';

    /** Agreed. The customer may ship, quoting this document's number. */
    case Authorised = 'Authorised';

    /** The goods are here, in `returned`, and not sellable until somebody rules on them. */
    case Received = 'Received';

    /** Finished. Terminal. */
    case Closed = 'Closed';

    /** Not happening — dropped as a draft, or refused before or after the goods arrived. Terminal. */
    case Declined = 'Declined';

    /**
     * Can lines still be edited?
     *
     * Only while nobody has agreed to anything. The moment a return is authorised its lines are a
     * promise to the customer about what they may send, and after receipt they are a record of what
     * arrived — editing either is rewriting history rather than correcting a draft.
     */
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
