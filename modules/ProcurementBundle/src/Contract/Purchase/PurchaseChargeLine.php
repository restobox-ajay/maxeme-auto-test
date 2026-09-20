<?php

declare(strict_types=1);

namespace ProcurementBundle\Contract\Purchase;

/**
 * One charge frozen onto a purchase document — freight, brokerage, duty, a fuel surcharge, a
 * one-off adjustment (#655, #658).
 *
 * ## Why this is not `App\Contract\Fee\FeeLine`
 *
 * The owner's ruling of 2026-09-11, recorded in `docs/feature_list.json`: **tax is shared with the
 * sell side and fees are not.** Tax is the same fact in both directions — a taxable good is taxable
 * whether you buy it or sell it — so purchase documents call the very same
 * `App\Contract\Tax\TaxCalculatorInterface` implementations through the very same resolver. A fee
 * is not the same fact: a sell-side fee is money we CHARGE and a buy-side charge is money we are
 * CHARGED, answering to different rules, different registrations and different people. The sell
 * side's seam is welded to its own family anyway — `App\Contract\Fee\FeeContext::fromDocument()`
 * takes an `AbstractSalesDocument`.
 *
 * So this is the sales seam's SHAPE and none of its code, and nothing under `src/Contract/Fee/` is
 * read, implemented or touched from here.
 *
 * ## Field for field with `FeeLine`, minus two
 *
 *  - **no `feeId`** — `FeeLineSnapshot` does not round-trip it either: a frozen row records what
 *    was charged, not which definition produced it;
 *  - **no `quantity`** — #539 stage 5 quantified sell-side charges so an invoice could bill 0.4 of
 *    one. Nothing on the buy side part-bills a charge; a vendor's bill states its own freight line.
 *    Adding it later is the same widening it was there, and absent will still mean "all of it".
 *
 * `$amount` is what this ROW charges in total, never a per-unit rate. Every reader sums; none
 * multiplies.
 */
final class PurchaseChargeLine
{
    /** Produced by a registered purchase fee calculator. Nothing ships one yet — see the resolver. */
    public const SOURCE_AUTO_CALC = 'auto-calc';

    /** Typed by an admin against one document: a one-off with no definition behind it. */
    public const SOURCE_MANUAL = 'manual';

    /**
     * Inbound freight.
     *
     * `freight` and not `shipping`, which is the sell side's word for the same position in the
     * document. On a purchase the goods come TO us and the word a buyer uses is freight; the two
     * vocabularies are parallel, so nothing has to translate between them.
     */
    public const TYPE_FREIGHT = 'freight';

    /** Everything else a vendor adds: brokerage, duty, a fuel surcharge, a pallet fee. */
    public const TYPE_FEE = 'fee';

    /** Inside the goods subtotal, charged before tax and itself taxable. */
    public const PLACEMENT_MAIN_LINE = 'main_line';

    /** Its own row above the tax block. Presentational — it is taxed by its own class like any other. */
    public const PLACEMENT_BEFORE_TAX_LINE = 'before_tax_line';

    /**
     * Added to the grand total after tax is settled, and therefore never taxed.
     *
     * The same string value the sell side's `Fee::PLACEMENT_AFTER_TAX` carries ('after_tax_line'),
     * so the two snapshots read alike to a human comparing them side by side.
     */
    public const PLACEMENT_AFTER_TAX = 'after_tax_line';

    public const PLACEMENTS = [self::PLACEMENT_MAIN_LINE, self::PLACEMENT_BEFORE_TAX_LINE, self::PLACEMENT_AFTER_TAX];

    public function __construct(
        public readonly string $slug,
        public readonly string $label,
        /** 'E', 'G' or 'S' — the SHARED tax vocabulary, read by the shared calculators. */
        public readonly string $taxClass,
        public readonly float $amount,
        public readonly string $placement = self::PLACEMENT_MAIN_LINE,
        public readonly string $type = self::TYPE_FEE,
        public readonly string $source = self::SOURCE_AUTO_CALC,
    ) {}

    /**
     * Is this charge inside the figure tax is taken on.
     *
     * Only `PLACEMENT_AFTER_TAX` is not, and that is the one rule here with teeth rather than a
     * display choice: an after-tax charge is added once tax is already settled, so taxing it would
     * tax money the breakdown above it never saw.
     */
    public function isTaxable(): bool
    {
        return $this->placement !== self::PLACEMENT_AFTER_TAX && $this->taxClass !== 'E' && $this->amount !== 0.0;
    }
}
