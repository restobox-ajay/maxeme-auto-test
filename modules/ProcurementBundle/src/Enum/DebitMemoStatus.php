<?php

declare(strict_types=1);

namespace ProcurementBundle\Enum;

/**
 * A debit memo's state (#638) — column-for-column the mirror of App\Enum\CreditMemoStatus, because
 * a debit memo IS a credit note in reverse: money the VENDOR owes back to US, instead of money we
 * owe back to a customer.
 *
 * Draft   written but not issued. Holds nothing, and is applied against no vendor bill.
 * Open    issued, with a balance still to collect.
 * Closed  issued, and its balance fully applied to vendor bills, refunded, or a mix of the two.
 * Void    reversed. Holds nothing, applies nothing. Refused once anything has been applied or
 *         refunded — DebitMemo::void()'s guard is byte-for-byte CreditMemo::void()'s.
 *
 * See CreditMemoStatus for why there is no fifth case and no separate "Refunded": both exits from a
 * balance land in Closed, and a status per exit route would be a second, hand-maintained answer to
 * a question the application/refund rows already answer.
 */
enum DebitMemoStatus: string
{
    case Draft = 'Draft';
    case Open = 'Open';
    case Closed = 'Closed';
    case Void = 'Void';
}
