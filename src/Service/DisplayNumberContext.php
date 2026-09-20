<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The named contexts {@see DisplayNumber}'s three methods accept as a second argument.
 *
 * An enum and not a free-form string so that a context which does not exist is a `ValueError` at the
 * call site rather than a figure that quietly renders with the default rule — a mistyped `'compct'`
 * printing a full-precision total on a dashboard tile is the same silently-wrong-number problem
 * `DisplayNumber` was written to end.
 *
 * A case here names a difference in DATA — decimal places, separators — carried in
 * `DisplayNumber::CONTEXT_OVERRIDES` and merged over the rule's own row. It is never a difference in
 * behaviour: the moment a context would need logic of its own, it is a fourth method rather than a
 * fourth case.
 *
 * Only contexts with a caller live here. There is exactly one today, and if a second never turns up
 * that is the honest answer rather than a gap.
 */
enum DisplayNumberContext: string
{
    /**
     * Whole units, separators kept: `$12,345` rather than `$12,345.00`.
     *
     * Three callers, all of them money figures that were already rendered this way before
     * `DisplayNumber` existed, and all three for the same reason — the cents are noise at the size
     * the figure is being read at:
     *
     *  - `templates/admin/_main/dashboard.html.twig` — the Revenue column of the top-companies tile,
     *    a `SUM(total)` over a company's orders read at a glance beside an order count.
     *  - `templates/customer/cart/index.html.twig` and
     *    `templates/customer/checkout/index.html.twig` — the free-shipping threshold, which appears
     *    inside a sentence ("Free shipping over $500") rather than in a money column, and is a
     *    configured round number rather than a computed total.
     *
     * This is the only divergence the inventory across all 388 formatting sites turned up. PDFs,
     * emails and screens were checked against each other specifically and format money and
     * quantities identically, so none of them is a context.
     */
    case Compact = 'compact';
}
