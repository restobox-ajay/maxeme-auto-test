<?php

declare(strict_types=1);

namespace ProcurementBundle\Enum;

/**
 * One vendor's side of an RFQ (#637) — what makes "N quotes against one requirement" real. The RFQ
 * names the requirement once; each invited vendor gets one of these, priced independently.
 *
 * Invited   asked. No prices recorded yet.
 * Replied   the vendor's prices are in — every RfqVendorReplyLine has a unit cost — but nothing
 *           has been decided. Several replies may sit here at once; that is the whole point of a
 *           tender.
 * Accepted  this is the quote that won. Set only by RfqConversionService::convert(), atomically,
 *           alongside the RFQ itself moving to Accepted — see that service for why a second
 *           accepted reply on the same RFQ cannot happen even under a race.
 * Rejected  a losing quote. Written on every OTHER invited vendor's reply the moment one is
 *           accepted, which is the row #624's conducted test asserts explicitly: the vendor whose
 *           quote was NOT taken.
 * Declined  the vendor said no, or never responded and the tender closed without them. Distinct
 *           from Rejected: nobody chose against this reply, there was simply nothing to choose.
 */
enum RfqVendorReplyStatus: string
{
    case Invited = 'Invited';
    case Replied = 'Replied';
    case Accepted = 'Accepted';
    case Rejected = 'Rejected';
    case Declined = 'Declined';

    /** True while this vendor's prices may still be entered or changed. */
    public function allowsPricing(): bool
    {
        return $this === self::Invited || $this === self::Replied;
    }

    /** Terminal — nothing here is reachable a second time. */
    public function isTerminal(): bool
    {
        return $this === self::Accepted || $this === self::Rejected || $this === self::Declined;
    }
}
