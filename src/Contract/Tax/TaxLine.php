<?php

declare(strict_types=1);

namespace App\Contract\Tax;

final class TaxLine
{
    /** Produced by a tax calculator from the configured SalesTax rates. */
    public const SOURCE_AUTO_CALC = 'auto-calc';

    /** Entered by an admin against one document — a one-off with no tax rate behind it. */
    public const SOURCE_MANUAL = 'manual';

    /**
     * @param ?float  $rate   Null for a manual adjustment: the admin typed a dollar amount, not a
     *                        percentage, so there is no rate to show and nothing to recompute from.
     * @param ?string $slug   Stable key for reporting — the SalesTax row's slug (gst, bc-pst, …)
     *                        for a calculated line, the sluggified label for a manual one. Null
     *                        only on lines frozen before this field existed.
     * @param string  $source Whether a calculator produced this line or an admin typed it. This is
     *                        what keeps a manual "GST adjustment" from being rolled into the real
     *                        GST line: same label, entirely different thing.
     */
    public function __construct(
        public readonly string $label,
        public readonly ?float $rate,
        public readonly float $amount,
        public readonly ?string $slug = null,
        public readonly string $source = self::SOURCE_AUTO_CALC,
    ) {}
}
