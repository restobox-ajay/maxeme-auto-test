<?php

declare(strict_types=1);

namespace ProcurementBundle\Receiving;

use ProcurementBundle\Entity\ShortDatedReceipt;

/**
 * What is wrong with one delivery line's expiry date — either of two things, or both (item 69).
 *
 * Only ever built when something IS wrong: {@see MinimumShelfLife::findingFor()} returns null
 * otherwise, so the existence of one of these is the finding. A plain value object with no entity
 * manager, the same shape as `MovementRequest` and `ReceivingRequest`: built by whoever measured,
 * read by the refusal that names it and by the exception row that records it, so the message a
 * receiver sees and the row written afterwards cannot report different numbers.
 *
 * ## Two triggers, one object — and why this is not a second type
 *
 * | trigger                 | fires when                                        |
 * |-------------------------|---------------------------------------------------|
 * | **already expired**     | `expiry < today`, whatever any minimum says        |
 * | **short of the minimum**| a minimum is set and the date is inside it         |
 *
 * This class was `ShelfLifeShortfall` and carried `isAlreadyExpired()` from the day it was
 * written — a correct predicate that **could not be reached** for the case it named. The object was
 * only ever built when the MINIMUM trigger fired, so with the global minimum at 0 (the default) an
 * expired pallet produced no object, no warning and no record: it booked in clean and stayed
 * invisible until `ExpireLotsCommand` swept it out of available stock that night. The predicate was
 * right; its reachability was the defect.
 *
 * So the fix is a second TRIGGER, not a second TYPE. A second type would mean two messages, two
 * records, two reason boxes and two mechanisms for a receiver to learn — for one decision, taken
 * once, about one pallet. Both facts are stored on one {@see ShortDatedReceipt} row and both are
 * recoverable from it exactly: `remaining_days < 0` is "expired", and `minimum_days > remaining_days`
 * with a non-zero minimum is "short". Neither needed a column, because neither is a third fact.
 *
 * ## Expiry is the LAST USABLE DAY
 *
 * The convention throughout #550 — `InventoryLot::isExpired()` compares `expiry < $on`, and
 * `ExpireLotsCommand` sweeps on the same strict comparison. Goods dated TODAY are usable today and
 * are not expired. `MinimumShelfLife::daysBetween()` produces 0 for that day, so this agrees with
 * the sweep by construction rather than by a second copy of the rule.
 */
final class ShelfLifeFinding
{
    public function __construct(
        /** The last usable day the goods carry. */
        public readonly \DateTimeImmutable $expiry,
        /** Whole days of shelf life left on arrival. Negative means it arrived already expired. */
        public readonly int $remainingDays,
        /** What the minimum was, at this moment, for this product. Zero means none was set. */
        public readonly int $minimumDays,
        /** Which setting the minimum came from — a ShortDatedReceipt::SOURCE_* value. */
        public readonly string $source,
    ) {
    }

    /**
     * The goods were past their last usable day before they were unloaded.
     *
     * Independent of every minimum, including a per-product override of ZERO. "Exempt from a
     * minimum shelf life" is a statement about a THRESHOLD — how much life a buyer insists on — and
     * it cannot mean "accepts goods that are already dead", because dead stock is not a short
     * threshold, it is unsellable on arrival by this application's own convention. Booking it in as
     * `available` writes a number the next expiry sweep immediately takes away again.
     */
    public function isAlreadyExpired(): bool
    {
        return $this->remainingDays < 0;
    }

    /** A minimum was set for this product, and the date is inside it. */
    public function isShortOfMinimum(): bool
    {
        return $this->minimumDays > 0 && $this->remainingDays < $this->minimumDays;
    }

    /**
     * How many days short of the minimum, or 0 when no minimum applied.
     *
     * Zero rather than a number nobody asked for: with no minimum set, `minimumDays - remainingDays`
     * still evaluates to something — 3, for a pallet 3 days expired — and reporting that as "3 days
     * short of a 0-day minimum" is a figure with no meaning attached to a threshold that does not
     * exist. The days-past-expiry figure is {@see self::daysPastExpiry()} and is a different number
     * for a different reason.
     */
    public function shortfallDays(): int
    {
        return $this->isShortOfMinimum() ? $this->minimumDays - $this->remainingDays : 0;
    }

    /** How many days past its last usable day the delivery already was, or 0 if it was not. */
    public function daysPastExpiry(): int
    {
        return $this->remainingDays < 0 ? -$this->remainingDays : 0;
    }

    /** The minimum that applied came from this product rather than from the global setting. */
    public function isFromProductOverride(): bool
    {
        return $this->source === ShortDatedReceipt::SOURCE_PRODUCT;
    }

    /**
     * The finding in ONE sentence, used by the refusal on both receiving screens.
     *
     * **One message, not two, even when both triggers fire.** The receiver is taking ONE decision —
     * accept this pallet or refuse it — writes ONE reason in ONE box, and it is recorded as ONE row.
     * Two messages would say there are two things to decide, and the second would be read as a
     * second thing to answer.
     *
     * When both apply, **expired leads**. It is strictly the worse news and the one that decides the
     * pallet; "60 day(s) short of a 90-day minimum" is arithmetic about a threshold that has already
     * been overtaken. The minimum is still named, in a trailing clause, because a receiver disputing
     * the printed date — "that is the production date, not the expiry" — needs to know the delivery
     * would be refused on the minimum even if they are right.
     */
    public function describe(): string
    {
        if ($this->isAlreadyExpired()) {
            return sprintf(
                'ARRIVED EXPIRED: its last usable day was %s, %d day(s) before it was delivered. These units are not sellable now, and the expiry sweep will move them straight out of available stock.%s',
                $this->expiry->format('Y-m-d'),
                $this->daysPastExpiry(),
                $this->isShortOfMinimum()
                    ? sprintf(' It also misses the %d day(s) minimum shelf life %s.', $this->minimumDays, $this->whoseMinimum())
                    : '',
            );
        }

        return sprintf(
            'is short-dated: its last usable day is %s, which leaves %d day(s) of shelf life against a minimum of %d day(s) %s — %d day(s) short',
            $this->expiry->format('Y-m-d'),
            $this->remainingDays,
            $this->minimumDays,
            $this->whoseMinimum(),
            $this->shortfallDays(),
        );
    }

    private function whoseMinimum(): string
    {
        return $this->isFromProductOverride() ? 'set on this product' : 'set for every product';
    }
}
