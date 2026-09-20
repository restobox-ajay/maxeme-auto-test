<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\DisplayNumber;
use App\Service\DisplayNumberContext;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The three filters every template formats a figure with: `|qty`, `|price`, `|amount`.
 *
 * ```twig
 *   {{ line.quantityEntered|qty }}          2.5 -> "2.5"        3 -> "3"
 *   ${{ line.displayUnitPrice|price }}      12.5 -> "12.50"     0.917 -> "0.917"
 *   ${{ invoice.total|amount }}             1234.5 -> "1,234.50"
 * ```
 *
 * A thin registration over {@see DisplayNumber}, which owns the rules and is the thing to read. It
 * is a separate class from the service for the reason `CalendarDateExtension` is not
 * `BusinessDate`: the rules are asked for by PHP as well as by templates — `EmailNotifier` and the
 * PDF renderers among them — and a rule that lived inside a Twig extension could only be reached
 * through Twig.
 *
 * The optional second argument is a {@see DisplayNumberContext} value — `{{ x|amount('compact') }}`
 * — and an unrecognised one throws with the list of contexts that exist rather than silently
 * rendering the default.
 *
 * The `$` is deliberately NOT part of `|price` or `|amount`. Currency is a template's decision and
 * the templates already spell it: the procurement screens print `{{ memo.currency }} {{ … }}`, the
 * sell-side prints a literal `$`, and an unpriced quote line prints `TBD` with no symbol at all. A
 * filter that emitted one would have to be told not to on the third case, which is a branch of
 * behaviour inside a formatter — the thing this whole seam exists to avoid.
 */
final class DisplayNumberExtension extends AbstractExtension
{
    public function __construct(private readonly DisplayNumber $numbers) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('qty', [$this->numbers, 'qty']),
            new TwigFilter('price', [$this->numbers, 'price']),
            new TwigFilter('amount', [$this->numbers, 'amount']),
        ];
    }

    /**
     * `{{ qty_step() }}` — what to put in an `<input type="number" step="…">` for a quantity box.
     *
     * A function rather than a filter because it takes no value: it is the rule itself, not a
     * rendering of something. Templates hardcoded `step="1"`, which refused a fractional entry in
     * the browser whatever the column and the setting said.
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('qty_step', [$this->numbers, 'qtyStep']),
        ];
    }
}
