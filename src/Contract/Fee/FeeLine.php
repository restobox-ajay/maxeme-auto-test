<?php

declare(strict_types=1);

namespace App\Contract\Fee;

final class FeeLine
{
    /** Produced by a calculator from configured rules. */
    public const SOURCE_AUTO_CALC = 'auto-calc';

    /** Entered by an admin against one document — a one-off with no definition behind it. */
    public const SOURCE_MANUAL = 'manual';

    public const TYPE_FEE = 'fee';
    public const TYPE_DISCOUNT = 'discount';
    public const TYPE_SHIPPING = 'shipping';

    /**
     * @param string $type   What kind of line this is. Deliberately a free string rather than an
     *                       enum: a bundle can introduce its own type without a core change, the
     *                       same way BundleDescriptorInterface::getType() is passed straight
     *                       through. Core recognises only what it must — TYPE_SHIPPING to derive
     *                       the shipping total, TYPE_DISCOUNT to report discounts separately — and
     *                       treats every other value, including an absent one on a line stored
     *                       before this field existed, as a generic fee.
     * @param string $source Whether a calculator produced this line or an admin typed it. Separate
     *                       from $type because a manual line and a calculated one can be the same
     *                       kind of thing; what differs is who decided the amount.
     * @param float  $quantity
     *                       How much of this charge the document bills (#539 stage 5).
     *
     *                       A charge is a quantified row like any other. A flat fee is a row of
     *                       quantity 1: bill 0.4 of it on one invoice and 0.6 remains — the same
     *                       uninvoiced-quantity derivation product lines already use, applied to
     *                       charges. Nothing apportions anything: the screen offers the remainder,
     *                       the person raising the invoice decides, and all that is enforced is
     *                       that the parts add back up to the order's own quantity.
     *
     *                       Defaults to 1, so every row frozen before this field existed and every
     *                       calculator with no reason to care reads back as the whole charge. That
     *                       is what makes this a widening rather than a migration — and it has to
     *                       cover calculators, not just admin-typed rows:
     *                       PayUponDeliverySurchargeFeeCalculator returns a flat SURCHARGE_AMOUNT
     *                       with no quantity in it at all.
     *
     *                       $amount stays exactly what it always was: what this ROW charges in
     *                       total, not a per-unit rate. Every reader of the snapshot — the totals
     *                       arithmetic, the tax breakdown, six templates — sums amounts and not one
     *                       of them multiplies, so a row billing 0.4 of a $10 shipping charge
     *                       carries amount 4.00 and quantity 0.4. unitAmount() recovers the other
     *                       figure, for the one screen that offers a remainder to bill.
     */
    public function __construct(
        public readonly ?int $feeId,
        public readonly string $slug,
        public readonly string $label,
        public readonly string $taxClass,
        public readonly float $amount,
        public readonly string $placement = 'main_line',
        public readonly string $type = self::TYPE_FEE,
        public readonly string $source = self::SOURCE_AUTO_CALC,
        public readonly float $quantity = 1.0,
    ) {}

    /**
     * What one whole unit of this charge costs — the figure a part-invoice screen multiplies by the
     * quantity being billed.
     *
     * A zero quantity has no unit price to recover, so it answers with the amount rather than
     * dividing by zero. That is also the right answer for the only way a zero-quantity row is
     * reachable in practice: a $0 row, whose unit price is $0 too.
     */
    public function unitAmount(): float
    {
        return $this->quantity === 0.0 ? $this->amount : $this->amount / $this->quantity;
    }

    /** The same charge, billed at a different quantity and amount. Everything else is carried over. */
    public function withQuantityAndAmount(float $quantity, float $amount): self
    {
        return new self(
            $this->feeId,
            $this->slug,
            $this->label,
            $this->taxClass,
            $amount,
            $this->placement,
            $this->type,
            $this->source,
            $quantity,
        );
    }
}
