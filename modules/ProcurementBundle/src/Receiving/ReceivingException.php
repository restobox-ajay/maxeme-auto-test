<?php

declare(strict_types=1);

namespace ProcurementBundle\Receiving;

/**
 * Something about this delivery cannot be booked in as described (#555).
 *
 * Its own class so a controller can catch "the paperwork is wrong" separately from
 * `InventoryDepthBundle\Movement\InsufficientStockException`, which means "the stock moved under
 * us". They read the same to a user and are completely different problems: one is fixed by
 * correcting the form, the other by looking again at what is on the shelf.
 *
 * Every message here is addressed to somebody standing at a receiving bay with a box in their
 * hands, so each one says what is missing and what to do about it — not which invariant failed.
 */
final class ReceivingException extends \DomainException
{
}
