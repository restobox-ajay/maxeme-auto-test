<?php

declare(strict_types=1);

namespace ProcurementBundle\Match;

use App\Service\QuantityScale;
use ProcurementBundle\Entity\PurchaseOrderLine;
use ProcurementBundle\Entity\VendorBillLine;

/**
 * One row of a three-way match (#555): ordered ↔ received ↔ charged, and what disagrees.
 *
 * A value object with no persistence. The match is recomputed from the three documents every time
 * it is asked for, deliberately: storing its verdict would give a second answer to a question the
 * documents already answer, and it would go stale the moment a late delivery arrived or a bill line
 * was corrected — which are exactly the events that change the verdict.
 */
final class MatchLine
{
    public const EXCEPTION_BILLED_NOT_RECEIVED = 'billed_not_received';
    public const EXCEPTION_RECEIVED_NOT_BILLED = 'received_not_billed';
    public const EXCEPTION_PRICE_VARIANCE = 'price_variance';
    public const EXCEPTION_QUANTITY_VARIANCE = 'quantity_variance';
    public const EXCEPTION_UNMATCHED = 'unmatched';

    /**
     * @param list<string> $exceptions
     */
    private function __construct(
        public readonly ?VendorBillLine $billLine,
        public readonly ?PurchaseOrderLine $orderLine,
        public readonly string $description,
        public readonly string $quantityOrdered,
        public readonly string $quantityReceived,
        public readonly string $quantityBilled,
        public readonly ?string $unitCostOrdered,
        public readonly ?string $unitCostBilled,
        public readonly array $exceptions,
        public readonly string $quantityVariance,
        public readonly string $priceVariance,
    ) {
    }

    /**
     * @param list<string> $exceptions
     */
    public static function of(
        ?VendorBillLine $billLine,
        ?PurchaseOrderLine $orderLine,
        string $description,
        string $quantityOrdered,
        string $quantityReceived,
        string $quantityBilled,
        ?string $unitCostOrdered,
        ?string $unitCostBilled,
        array $exceptions,
        string $quantityVariance,
        string $priceVariance,
    ): self {
        return new self(
            $billLine,
            $orderLine,
            $description,
            $quantityOrdered,
            $quantityReceived,
            $quantityBilled,
            $unitCostOrdered,
            $unitCostBilled,
            $exceptions,
            $quantityVariance,
            $priceVariance,
        );
    }

    /**
     * How much money this row puts in question, in cents — the figure the exception screen sorts on.
     *
     * ## Why there is one figure per row rather than one per finding
     *
     * A line can carry several exceptions at once, and they OVERLAP: a line short-shipped at 210
     * against an order for 240 and billed for all 240 is both "billed but not received" and a
     * quantity variance, and both of those sentences are about the SAME thirty units. Adding them
     * would report sixty units of exposure where thirty exist, which on a screen whose whole job is
     * to rank problems by size is worse than no figure at all.
     *
     * So the row reports the LARGEST single exposure among its findings. It never double-counts,
     * and it is never zero while a real exception stands — which matters, because a zero sorts to
     * the bottom and reads as "nothing at stake here". The trade is that a line carrying two
     * genuinely separate exposures (an overcharge on the units that did arrive, plus units that did
     * not) is reported at the bigger of the two rather than their sum. That understates by the
     * smaller one; the alternative overstates by the overlap on the much commoner case.
     *
     * Everything is integer arithmetic: quantities in hundredths, unit costs in ten-thousandths,
     * their product in millionths, divided back to cents. Cents, not a formatted string, because
     * `'1000'` sorts before `'9'` as text and this column exists to be sorted.
     */
    public function amountAtRiskCents(): int
    {
        $largest = 0;

        foreach ($this->exceptions as $exception) {
            $amount = $this->amountForCents($exception);
            if ($amount > $largest) {
                $largest = $amount;
            }
        }

        return $largest;
    }

    /** The same figure as money, two decimals, for the cell. Pair it with the document's currency. */
    public function amountAtRisk(): string
    {
        return self::money($this->amountAtRiskCents());
    }

    /**
     * What ONE finding puts in question, in cents.
     *
     * Each is the money that finding is about, and nothing else:
     *
     *  - **Billed but not received** — the units charged for that no receipt supports, at the price
     *    we are being CHARGED. That is the cheque that would go out for goods not on the shelf. A
     *    bill with no purchase order behind it has no receipts at all, so the whole line qualifies.
     *  - **Price variance** — the overcharge per unit across every unit billed. Compared against
     *    what was ordered, because "are they charging what they quoted" is a question about the
     *    agreement.
     *  - **Quantity variance** — the value of the gap between ordered and received, at the price we
     *    agreed. Not about this bill at all: it is goods owed to us, or goods we never ordered.
     *  - **Not on the purchase order** — the whole line. Nothing supports any of it.
     *  - **Received but not billed** — the accrual: units that arrived and are not yet charged for,
     *    at the agreed price.
     *
     * A finding this row does not carry returns 0, so a caller cannot accidentally report the
     * exposure of something that is not wrong with this line.
     */
    public function amountForCents(string $exception): int
    {
        if (!$this->has($exception)) {
            return 0;
        }

        $ordered = $this->quantityOrdered;
        $received = $this->quantityReceived;
        $billed = $this->quantityBilled;
        $orderedCost = $this->unitCostOrdered ?? '0';
        $billedCost = $this->unitCostBilled ?? '0';
        $hasOrderedCost = QuantityScale::compare($orderedCost, 0) !== 0;
        $hasBilledCost = QuantityScale::compare($billedCost, 0) !== 0;

        return match ($exception) {
            self::EXCEPTION_BILLED_NOT_RECEIVED => self::cents(
                self::atLeastZero(QuantityScale::sub($billed, $received)),
                $hasBilledCost ? $billedCost : $orderedCost,
            ),
            self::EXCEPTION_PRICE_VARIANCE => self::cents($billed, self::atLeastZero(QuantityScale::sub($billedCost, $orderedCost))),
            self::EXCEPTION_QUANTITY_VARIANCE => self::cents(
                self::absoluteDifference($received, $ordered),
                $hasOrderedCost ? $orderedCost : $billedCost,
            ),
            self::EXCEPTION_UNMATCHED => self::cents($billed, $billedCost),
            self::EXCEPTION_RECEIVED_NOT_BILLED => self::cents(
                self::atLeastZero(QuantityScale::sub($received, $billed)),
                $hasOrderedCost ? $orderedCost : $billedCost,
            ),
            default => 0,
        };
    }

    /** Quantity times unit cost, to whole cents — the fraction of a cent rounds once, here. */
    private static function cents(string $quantity, string $unitCost): int
    {
        return (int) round((float) QuantityScale::mul($quantity, $unitCost, 6) * 100);
    }

    private static function atLeastZero(string $quantity): string
    {
        return QuantityScale::compare($quantity, 0) > 0 ? $quantity : QuantityScale::canonical(0);
    }

    private static function absoluteDifference(string $a, string $b): string
    {
        return QuantityScale::compare($a, $b) >= 0 ? QuantityScale::sub($a, $b) : QuantityScale::sub($b, $a);
    }

    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /** Nothing disagrees. A row that is clean is approvable without anyone thinking about it. */
    public function isClean(): bool
    {
        return $this->exceptions === [];
    }

    public function has(string $exception): bool
    {
        return \in_array($exception, $this->exceptions, true);
    }

    /** What a person reads on the exception screen, in the order a person would ask it. */
    public function describeExceptions(): string
    {
        if ($this->exceptions === []) {
            return 'Matched.';
        }

        $labels = [
            self::EXCEPTION_BILLED_NOT_RECEIVED => 'billed but not received',
            self::EXCEPTION_RECEIVED_NOT_BILLED => 'received but not billed',
            self::EXCEPTION_QUANTITY_VARIANCE => 'quantity variance',
            self::EXCEPTION_PRICE_VARIANCE => 'price variance',
            self::EXCEPTION_UNMATCHED => 'not on the purchase order',
        ];

        $parts = [];
        foreach ($this->exceptions as $exception) {
            $parts[] = $labels[$exception] ?? $exception;
        }

        return ucfirst(implode(', ', $parts)) . '.';
    }
}
