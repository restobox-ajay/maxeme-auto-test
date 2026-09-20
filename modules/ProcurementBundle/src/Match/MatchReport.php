<?php

declare(strict_types=1);

namespace ProcurementBundle\Match;

use ProcurementBundle\Entity\VendorBill;

/**
 * The verdict on one bill (#555): every row, what disagrees, and whether a human is needed.
 *
 * Recomputed on demand and never stored — see MatchLine for why.
 */
final class MatchReport
{
    /**
     * @param list<MatchLine> $lines
     */
    public function __construct(
        public readonly VendorBill $bill,
        public readonly array $lines,
        /** True when the bill has no purchase order to match against at all. */
        public readonly bool $withoutPurchaseOrder,
        /** Bills already on file carrying the same vendor invoice number — the duplicate guard. */
        public readonly bool $duplicateVendorInvoiceNumber,
        /**
         * Warehouses goods actually arrived at that this bill does not name — empty when they
         * agree, which is the normal answer. See ThreeWayMatchService::receivedAtOtherWarehouses().
         *
         * @var list<string>
         */
        public readonly array $receivedAtOtherWarehouses,
        public readonly string $quantityTolerancePercent,
        public readonly string $priceTolerancePercent,
    ) {
    }

    /**
     * Can this be approved without anyone thinking about it.
     *
     * A bill whose every line matches its PO line in quantity and price, and has been received, is
     * approvable on sight. **Everything else needs a human** — and that deliberately includes a
     * bill with no purchase order, a bill whose number we have seen before, and a bill whose goods
     * arrived at a warehouse it does not name; none of the three is a line-level exception and all
     * three are exactly the cases an AP clerk must look at.
     *
     * The third is the newest and the one with money directly behind it: the warehouse a bill names
     * is the only input to its frozen tax province, so a bill pointing at the wrong building was
     * taxed at the wrong province's rates. Auto-approving that would authorise the payment of a
     * figure the receipt already contradicts.
     */
    public function isAutoApprovable(): bool
    {
        if ($this->withoutPurchaseOrder || $this->duplicateVendorInvoiceNumber || $this->receivedElsewhere()) {
            return false;
        }

        foreach ($this->lines as $line) {
            if (!$line->isClean()) {
                return false;
            }
        }

        return true;
    }

    /** Did any of this bill's goods land somewhere the bill does not name. */
    public function receivedElsewhere(): bool
    {
        return $this->receivedAtOtherWarehouses !== [];
    }

    /**
     * The warehouse disagreement as a sentence, or '' when there is none.
     *
     * Built here rather than in the template so the bill screen and the timeline entry written at
     * approval say the same thing — the two places that report a match have disagreed about a
     * finding before, and this is the finding whose whole point is that two records disagree.
     */
    public function describeReceivedElsewhere(): string
    {
        if (!$this->receivedElsewhere()) {
            return '';
        }

        return sprintf(
            'Goods on this bill were received at %s, but the bill names %s. The warehouse a bill '
                . 'names is the only thing its tax province is derived from, so this bill may be '
                . 'taxed at the wrong province.',
            implode(' and ', $this->receivedAtOtherWarehouses),
            $this->bill->getWarehouse()?->getName() ?? 'no warehouse',
        );
    }

    /** @return list<MatchLine> */
    public function exceptions(): array
    {
        return array_values(array_filter($this->lines, static fn (MatchLine $line): bool => !$line->isClean()));
    }

    public function exceptionCount(): int
    {
        return \count($this->exceptions());
    }

    /**
     * The one-line summary written onto the bill's timeline when it is approved.
     *
     * Written at approval time and not recomputed later on purpose: it records what the match said
     * *when the money was authorised*, which is the question an audit asks. A late delivery can
     * make today's match disagree with it, and that disagreement is information rather than an
     * error.
     */
    public function summary(): string
    {
        if ($this->withoutPurchaseOrder) {
            return sprintf('Matched with no purchase order to match against; %d line(s) reviewed.', \count($this->lines));
        }

        $exceptions = $this->exceptionCount();

        // Appended rather than folded into the count: it is a fact about the document, not one of
        // the line exceptions, and a reader of the timeline a year from now needs to see it stated
        // rather than inferred from a number that would silently include it.
        $warehouse = $this->receivedElsewhere() ? ' ' . $this->describeReceivedElsewhere() : '';

        if ($exceptions === 0) {
            return sprintf(
                'Three-way match clean across %d line(s), within %s%% quantity and %s%% price tolerance.%s',
                \count($this->lines),
                $this->quantityTolerancePercent,
                $this->priceTolerancePercent,
                $warehouse,
            );
        }

        return sprintf(
            'Three-way match found %d exception(s) across %d line(s): %s%s',
            $exceptions,
            \count($this->lines),
            implode(' ', array_map(static fn (MatchLine $line): string => $line->description . ' — ' . $line->describeExceptions(), $this->exceptions())),
            $warehouse,
        );
    }
}
