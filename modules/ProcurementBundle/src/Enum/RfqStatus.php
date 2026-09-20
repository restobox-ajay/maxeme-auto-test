<?php

declare(strict_types=1);

namespace ProcurementBundle\Enum;

/**
 * An RFQ's state (#637) — the buy-side mirror of EstimateStatus, adjusted for the one real
 * structural difference: an estimate prices one company, an RFQ tenders to several vendors at
 * once, so "priced" is a property of a REPLY (see RfqVendorReplyStatus) and not of the RFQ header.
 *
 * Draft    the requirement is being built: what is needed, how much. No vendor has seen it.
 * Sent     tendered to one or more vendors. Each invited vendor gets its own RfqVendorReply,
 *          starting Invited, and the RFQ itself sits here until one reply is accepted.
 * Accepted one reply was converted into a purchase order. Terminal — see
 *          RfqConversionService::claimForConversion(), the atomic claim that makes this true by
 *          construction rather than by convention.
 * Cancelled withdrawn before any reply was accepted. A vendor's own reply may still show Invited
 *          or Replied; cancelling the RFQ does not visit them, because nothing here emails a vendor
 *          and there is nothing to notify them of but a phone call already covers.
 * Expired  nobody replied, or nobody was accepted, in time. A status an admin sets by hand — this
 *          app has no scheduled sweep for it, matching how #625's own reordering rules ship inert
 *          until a human turns them on.
 */
enum RfqStatus: string
{
    case Draft = 'Draft';
    case Sent = 'Sent';
    case Accepted = 'Accepted';
    case Cancelled = 'Cancelled';
    case Expired = 'Expired';

    /** True while the requirement lines may still be edited — before anybody has been asked. */
    public function allowsLineEditing(): bool
    {
        return $this === self::Draft;
    }

    /** Terminal states, which nothing moves out of. */
    public function isTerminal(): bool
    {
        return $this === self::Accepted || $this === self::Cancelled || $this === self::Expired;
    }
}
