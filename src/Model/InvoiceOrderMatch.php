<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\SalesOrderLine;

/**
 * The answer to "can this invoice be attached to this order, and what would it draw down"
 * (#539 stage 5).
 *
 * One object rather than a bool and an exception, because the screen needs all three answers at
 * once: whether it may link, everything that is wrong if it may not, and — for the link itself —
 * which order line each invoice line bills. That last part is the whole point of the exercise:
 * uninvoiced quantity is derived from InvoiceLine::$salesOrderLine, so an invoice attached without
 * per-row attribution would draw down nothing at all.
 */
final class InvoiceOrderMatch
{
    /**
     * @param list<InvoiceOrderMismatch> $mismatches
     * @param array<int, SalesOrderLine> $attributions the order line each invoice line bills,
     *                                                 keyed by spl_object_id() of the invoice line
     *                                                 (an unsaved line has no id to key on)
     */
    public function __construct(
        public readonly array $mismatches,
        public readonly array $attributions,
    ) {
    }

    public function isLinkable(): bool
    {
        return $this->blockers() === [];
    }

    /** @return list<InvoiceOrderMismatch> */
    public function blockers(): array
    {
        return array_values(array_filter(
            $this->mismatches,
            static fn (InvoiceOrderMismatch $m): bool => $m->blocking,
        ));
    }

    /** @return list<InvoiceOrderMismatch> */
    public function notices(): array
    {
        return array_values(array_filter(
            $this->mismatches,
            static fn (InvoiceOrderMismatch $m): bool => !$m->blocking,
        ));
    }

    /**
     * Every blocking reason, as one sentence per reason.
     *
     * Joined with a space rather than a comma so each stays a whole sentence: a flash message is
     * where most people will read this, and a list of half-sentences is exactly the generic
     * "lines do not match" the issue asks not to produce.
     *
     * @return list<string>
     */
    public function blockerMessages(): array
    {
        return array_map(
            static fn (InvoiceOrderMismatch $m): string => $m->message,
            $this->blockers(),
        );
    }
}
