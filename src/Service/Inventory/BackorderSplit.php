<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Service\QuantityScale;

/**
 * What one requested quantity breaks down into (#548): what ships now, what waits, and what cannot
 * be accepted at all.
 *
 * The three always sum back to the requested quantity, which is what makes a caller unable to lose
 * units by reading only one of them.
 *
 * ## The three are DECIMAL STRINGS, not physical units
 *
 * They used to be physical units, and that is what made every caller round its demand on the way
 * in: `(int) round((float) $line->getQuantity())`, so a line for 2.5 was split as 3 and a line for
 * 0.4 as nothing at all. Fractional quantities are settled policy here, so the split had to be able
 * to express one.
 *
 * A split is decided by comparison and subtraction, and those have to be exact — a demand a
 * ten-thousandth over what is available must read as short, and a float subtraction cannot promise
 * that. `App\Service\QuantityScale`'s bcmath helpers (`compare()`, `add()`, `sub()`) are how this
 * stays exact without going through a scaled-integer detour.
 */
final readonly class BackorderSplit
{
    public function __construct(
        /** Covered by stock on hand right now. */
        public string $fulfilled,
        /** Accepted as a promise against future stock. Always zero unless the SKU opted in. */
        public string $backordered,
        /** Nothing can cover — neither stock nor remaining backorder capacity. A refusal. */
        public string $uncovered,
    ) {
    }

    /** Everything asked for is accounted for, whether it ships now or later. */
    public function isFullyCovered(): bool
    {
        return QuantityScale::compare($this->uncovered, 0) <= 0;
    }

    /** What the order line may actually be written for. */
    public function accepted(): string
    {
        return QuantityScale::add($this->fulfilled, $this->backordered);
    }
}
