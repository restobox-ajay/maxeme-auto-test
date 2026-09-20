<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Uom\LineDenomination;

/**
 * How this application prints a quantity, a unit price and a money total. One place, three rules.
 *
 * ## Why it exists
 *
 * The same three decisions were being taken independently in every template that showed a figure,
 * and the copies disagreed. A line stored as `2.5000` printed "3" on the sales order PDF, "2.5" on
 * the order detail screen and "3" in the confirmation email the customer received — three renderings
 * of one stored number, none of them agreeing, each one fixed on its own screen and each fix leaving
 * the other two wrong. Fourteen quantity sites were still rounding after three screens had been
 * conformed by hand, because a screen is not a rule and conforming screens one at a time never ends.
 *
 * So the rule stops living in the templates. `{{ line.quantityEntered|qty }}` is the whole of what a
 * template now decides, and the answer to "what does a quantity look like" is in this file or it is
 * nowhere.
 *
 * ## The rules are a TABLE, not method bodies
 *
 * {@see rules()} below is three rows of data — how many decimal places at most, how many at least,
 * and which separators — and {@see render()} is the one piece of arithmetic that reads them. There is
 * no `if` per rule and deliberately so: making the decimal places configurable was then a change of
 * where the table comes from, touching this class and no call site and no template. An `if` cannot be
 * replaced by configuration; a row of a table can. That change has since been made — see below — and
 * it is the reason `rules()` is a method rather than the `const` it started as.
 *
 * `min` and `max` decimals are what make one mechanism express three different-looking rules:
 *
 * ```
 *            max  min     2.5        3        0.833333    1234.5
 *   qty       4    0      "2.5"      "3"      "0.8333"    "1234.5"
 *   price     6    2      "2.50"     "3.00"   "0.833333"  "1,234.50"
 *   amount    2    2      "2.50"     "3.00"   "0.83"      "1,234.50"
 * ```
 *
 * Trailing zeros are trimmed from `max` down to `min` and no further. With `max == min` — which is
 * what `amount` is — nothing is trimmed and the rule reads as "always exactly two", without that
 * being a separate branch anywhere.
 *
 * ## The numbers come from the columns, not from an opinion
 *
 * The two money `max` values are {@see LineDenomination}'s scale constants, which are the scales the
 * database columns are actually declared at:
 *
 * ```
 *   price / cost      decimal(18, 6)   RATE_SCALE     = 6
 *   subtotal / total  decimal(12, 2)   MONEY_SCALE    = 2
 * ```
 *
 * Written as constants rather than as the literals 6 and 2 so that a column widened in one place
 * cannot leave the display rounding at the old scale — which is the exact failure this class exists
 * to end, one level down.
 *
 * ## The quantity `max` is CONFIGURED
 *
 * Quantity is the one rule whose precision is a global setting rather than a column constant: its
 * `max` is {@see QuantityScale::decimals()}, so a store set to three prints three AND stores three.
 * `QuantityScale` says what a quantity IS, this class says what it LOOKS LIKE.
 *
 * ## Why `price` and `amount` are separate rules and must stay separate
 *
 * `price` is a UNIT price and `amount` is a money TOTAL, and they round differently on purpose. A
 * unit price of `$0.917` is a real thing this application stores and sells at — see
 * `AdminSalesLineQuantityAndPriceRulesCest::anOrderAndAQuoteWithSubCentLineFractionsReachTheSameGrandTotal`,
 * which posts exactly that and asserts the cent is applied ONCE, at the document total. Collapsing
 * the two rules into one would print that line as `$0.92`, which multiplied back by its quantity is
 * not the number the document totals to. The line would silently stop explaining its own arithmetic.
 *
 * ## Thousands separators
 *
 * Money carries one, quantity does not, and both answers are read off what the application already
 * did rather than chosen here. Every one of the 313 money sites in the templates rendered with a
 * comma — 282 as `number_format(2)`, whose defaults are `'.'` and `','`, and 31 spelling those
 * defaults out. Quantity's one correct rendering, `{{ line.quantityEntered + 0 }}` on the three
 * detail screens, never produced one. A quantity is also the figure most likely to sit in a narrow
 * column beside a unit label, where `1,234.5 cases` reads worse than `1234.5 cases`.
 *
 * ## Null is not zero
 *
 * A null price on a quote means "not priced yet" (#254, #255) and the templates print `TBD` or
 * `No pricing` for it. This class renders null and the empty string as the EMPTY STRING and never as
 * `0.00`, because `0.00` would turn "we have not quoted this yet" into "this is free" — on a
 * customer-facing document, in a currency column, indistinguishable from a real price of zero, which
 * is itself a thing this application legitimately stores.
 *
 * It does not render the placeholder itself, and that is a decision rather than an omission. The
 * placeholder is not one string: it is `TBD` on a quote, `No pricing` on the estimate form, an em
 * dash on a fee row, and on the quote PDF it also puts a `tbd` class on the cell. A filter choosing
 * one of those would be wrong on the other three screens, and the cell class it cannot reach at all.
 * So the template keeps its own `is not null ?` guard — which every such template already had — and
 * this class's only promise about null is the one that matters: it will not invent a number.
 *
 * ## Contexts
 *
 * The optional second argument names a {@see DisplayNumberContext}, whose rows are MERGED over the
 * rule's own row. A context therefore changes DATA — decimal places, separators — and can never
 * become a branch of behaviour; if one ever needs an `if` of its own it is a different method, not a
 * new context. Unknown contexts throw rather than falling back, because a mistyped context that
 * silently rendered the default is the same class of quietly-wrong figure as the three disagreeing
 * copies above.
 *
 * ## What this class is NOT for
 *
 * Anything that posts. A figure rendered into an `<input value="...">`, an `<option>`, a hidden
 * field or a `data-` attribute is round-tripped back to a controller, and the sell-side forms
 * compare it against what the admin typed to tell "untouched" from "retyped" — see
 * {@see LineDenomination::boxUntouched()}. Re-rendering one of those through here would change the
 * comparison and let a save rewrite a stored figure that nobody touched. Those sites keep their own
 * explicit formatting and are deliberately not adopted.
 */
final class DisplayNumber
{
    /**
     * The qty row's `max` is asked for per render rather than compiled in, which is what makes the
     * setting reach the screens. Price and money stay constants — they are not quantities, and a
     * rate rounded to a quantity's scale would stop explaining a line's own arithmetic.
     *
     * @var array<string, array{max: int, min: int, point: string, separator: string}>
     */
    private function rules(): array
    {
        return [
            'qty' => [
                'max' => QuantityScale::decimals(),
                'min' => 0,
                'point' => '.',
                'separator' => '',
            ],
            'price' => [
                'max' => LineDenomination::RATE_SCALE,
                'min' => LineDenomination::MONEY_SCALE,
                'point' => '.',
                'separator' => ',',
            ],
            'amount' => [
                'max' => LineDenomination::MONEY_SCALE,
                'min' => LineDenomination::MONEY_SCALE,
                'point' => '.',
                'separator' => ',',
            ],
        ];
    }

    /**
     * What each context overrides, by context value. Merged over the rule's row; any key the context
     * does not name keeps the rule's own answer.
     *
     * @var array<string, array{max?: int, min?: int, point?: string, separator?: string}>
     */
    private const CONTEXT_OVERRIDES = [
        DisplayNumberContext::Compact->value => ['max' => 0, 'min' => 0],
    ];

    /**
     * A quantity: up to four places, and no decimal point at all when the figure is whole.
     *
     * `2.5` reads "2.5" and `3` reads "3" — never "3.0" and never "3.0000". This is the rule the
     * three sell-side detail screens were already following with `{{ value + 0 }}`, which produced
     * the same output for every quantity `decimal(14, 4)` can hold.
     */
    public function qty(mixed $value, string|DisplayNumberContext|null $context = null): string
    {
        return $this->render('qty', $value, $context);
    }

    /**
     * A UNIT price: at least two places, up to six, trailing zeros between them trimmed.
     *
     * `12.5` reads "12.50" because it is money, and `0.917` reads "0.917" because that is what the
     * line is actually priced at and the document's arithmetic does not work without it.
     */
    public function price(mixed $value, string|DisplayNumberContext|null $context = null): string
    {
        return $this->render('price', $value, $context);
    }

    /**
     * The smallest quantity a person may type, as an `<input type="number" step="…">` value.
     *
     * `"0.0001"` at four places, `"0.001"` at three, `"1"` at none. A form that hardcodes `step="1"`
     * refuses a fractional entry in every browser that honours it, however decimal the column is —
     * which is a rounding rule hiding in an HTML attribute, in the one place a person meets it. It
     * belongs here for the same reason the rest of the quantity rule does, and it reads the same
     * configured scale, so a store set to three places gets boxes that accept three.
     */
    public function qtyStep(): string
    {
        $decimals = QuantityScale::decimals();

        // 1, 0.1, 0.01 … built by moving the point rather than by `10 ** -$decimals`, which is a
        // float and prints as `1.0E-5` at the bottom of its range.
        return rtrim(rtrim(number_format(1 / (10 ** $decimals), $decimals, '.', ''), '0'), '.') ?: '1';
    }

    /**
     * A money TOTAL: always exactly two places, which is the scale the column holds.
     */
    public function amount(mixed $value, string|DisplayNumberContext|null $context = null): string
    {
        return $this->render('amount', $value, $context);
    }

    /**
     * The one piece of arithmetic. Reads a row of {@see RULES}, applies the context's overrides, and
     * formats. Every difference between the three methods is a difference between rows.
     */
    private function render(string $rule, mixed $value, string|DisplayNumberContext|null $context): string
    {
        $spec = $this->rules()[$rule];
        if ($context !== null) {
            $spec = self::CONTEXT_OVERRIDES[$this->resolveContext($context)->value] + $spec;
        }

        // Null and blank are "no figure", never zero — see the class docblock. A non-numeric value
        // is returned as it came rather than silently becoming 0.00: a stored value that is somehow
        // not a number should be visible on the page, which is the same choice
        // CalendarDateExtension makes for an unparseable date.
        if ($value === null || $value === '') {
            return '';
        }
        if (!is_numeric($value)) {
            return (string) $value;
        }

        $text = number_format((float) $value, $spec['max'], $spec['point'], $spec['separator']);
        if ($spec['max'] <= $spec['min']) {
            return $text;
        }

        // Trim trailing zeros down to `min` places and no further, then drop a decimal point left
        // with nothing after it. `min` of 0 is what lets a whole quantity print as "3".
        [$whole, $fraction] = explode($spec['point'], $text, 2);
        $fraction = rtrim($fraction, '0');
        $fraction = str_pad($fraction, $spec['min'], '0');

        return $fraction === '' ? $whole : $whole . $spec['point'] . $fraction;
    }

    /**
     * A context value becomes a case, or throws naming the ones that exist.
     *
     * `DisplayNumberContext::from()` alone would throw a `ValueError` reading `"pdf" is not a valid
     * backing value for enum DisplayNumberContext`, which says what is wrong and not what to write
     * instead. A template author gets the list.
     */
    private function resolveContext(string|DisplayNumberContext $context): DisplayNumberContext
    {
        if ($context instanceof DisplayNumberContext) {
            return $context;
        }

        return DisplayNumberContext::tryFrom($context) ?? throw new \InvalidArgumentException(sprintf(
            'Unknown number formatting context "%s". The contexts that exist are: %s.',
            $context,
            implode(', ', array_map(
                static fn(DisplayNumberContext $case): string => '"' . $case->value . '"',
                DisplayNumberContext::cases(),
            )),
        ));
    }
}
