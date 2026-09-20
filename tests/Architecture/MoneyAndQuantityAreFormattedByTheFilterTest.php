<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Entity\SalesOrderLine;
use App\Service\DisplayNumber;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * No template formats a quantity or a money figure by hand. `|qty`, `|price` and `|amount` or
 * nothing.
 *
 * ## What went wrong without this
 *
 * The same three formatting decisions were taken separately in every template that showed a figure,
 * and the copies disagreed. A line stored as `2.5000` printed "3" on the sales order PDF, "2.5" on
 * the order detail screen and "3" in the confirmation email — one stored number, three renderings,
 * none agreeing. Each was fixed on its own screen; three screens were conformed by hand and
 * **fourteen quantity sites were still rounding** afterwards, because conforming screens one at a
 * time never reaches the end of a list nobody has.
 *
 * {@see DisplayNumber} is the one place the rules now live. This file is what stops the fifteenth
 * site being written: a screen added tomorrow that reaches for `number_format` on a money column
 * fails here, by name, with the filter to use instead.
 *
 * ## Why this derives its rule instead of listing files
 *
 * A list of offending paths passes forever while the next template quietly adds a hand-rolled
 * figure, and it goes stale the moment anybody moves a file — the habit
 * {@see \Tests\Functional\AdminListScreenConventionsCest} and
 * {@see EveryDocumentDeclaresItsContractTest} exist to break. So a site is judged by two pieces of
 * evidence about the site ITSELF, both read off the application rather than written down:
 *
 *  1. **It names a money or quantity COLUMN.** The expression mentions a property that Doctrine
 *     maps as a `decimal` whose precision and scale match one of the application's three sales
 *     columns. Those three shapes are not typed here either: they are read off
 *     `SalesOrderLine`'s own `quantity`, `price` and `subtotal` mappings, so widening a column —
 *     `#645` phase 2 will — moves the rule with it and nobody has to remember this file.
 *  2. **It prints a currency symbol.** A literal `$` immediately before the output tag, a
 *     `'$' ~` concatenation inside it, or a `{{ x.currency }}` tag right beside it, which is how
 *     the procurement screens spell the same thing. A template that puts a currency marker in
 *     front of a number has said the number is money, whatever the figure is called.
 *
 * Either is enough. Neither is a path.
 *
 * ## What it deliberately does NOT flag, and why that is derived too
 *
 *   - **Tax rates and percentages.** `{{ (taxLine.rate * 100)|number_format(0) }}%` names `rate`,
 *     which Doctrine maps at `decimal(8,3)` — not one of the three sales shapes — and prints a `%`
 *     rather than a `$`. It fails both tests without anybody deciding it should. The same goes for
 *     the fee bundles' rule values at `decimal(10,4)`.
 *   - **Row counts and page arithmetic.** `{{ (total / (currentLimit ?: 1))|round(0, 'ceil') }}`
 *     uses `total` as a bare variable holding a row count. Rule 1 requires a PROPERTY ACCESS —
 *     `order.total`, not `total` — precisely so that a count named after a money column is not
 *     mistaken for one.
 *   - **Anything inside an HTML start tag.** An `<input value="…">`, an `<option>`, a hidden field
 *     or a `data-` attribute is round-tripped back to a controller, and the sell-side forms compare
 *     what came back against what they sent to tell "untouched" from "retyped" — see
 *     {@see \App\Service\Uom\LineDenomination::boxUntouched()}. Re-rendering one through a filter
 *     would break that comparison and let a save rewrite a stored figure nobody touched. The scan
 *     tracks tag state across the whole file, with Twig regions blanked first, so a multi-line
 *     `<input … value="{{ x }}">` is recognised and a `<=` inside an expression is not mistaken for
 *     a tag.
 *
 * ## Where it cannot see, stated rather than papered over
 *
 * A figure that is neither column-named nor currency-marked is invisible here: a per-line tax
 * amount passed in as a plain array and printed into a bare `<td>` with no `$` would pass. Five such
 * sites existed in ProcurementBundle before this change and are adopted; a sixth written tomorrow
 * would not be caught. That is the honest edge of a derived rule, and it is a better edge than a
 * hand-kept list of the five, which would describe the tree as it was on the day somebody typed it.
 *
 * ## Why the exceptions are asserted to still be broken
 *
 * `NOT_YET_ADOPTED` is the `KNOWN_OFFENDERS` habit: every entry is asserted BELOW to still be a
 * live offender, so conforming one of those templates fails this file until its entry goes with it.
 * An exemption that has stopped being true reads as a considered decision while describing
 * something that is no longer the case, which is worse than no exemption at all.
 */
final class MoneyAndQuantityAreFormattedByTheFilterTest extends KernelTestCase
{
    /**
     * Templates held open by other work in flight, with the count of hand-formatted figures each
     * still carries.
     *
     * These are NOT an argument that hand-formatting is acceptable there. They are two templates a
     * concurrent change is mid-way through rewriting, left alone to avoid a collision, and the
     * count is asserted below so that adopting the filters in one of them fails this test until the
     * entry is deleted in the same commit.
     *
     * @var array<string, array{int, string}>
     */
    private const NOT_YET_ADOPTED = [
        'templates/admin/order/form.html.twig' => [2,
            'What is left here is the TOTALS BOX, and only that: the line cells this entry was'
            . ' written for moved into _partials/sales_line_row.html.twig when the three sell-side'
            . ' line rows became one, and that partial is conformed. The totals panel is a separate'
            . ' block whose figures each have a JavaScript twin recomputing them in place'
            . ' (.js-order-total-before-tax, .js-order-total-tax, .js-order-total-grand), so'
            . ' adopting the filters there is a change that has to answer for app.js as well and is'
            . ' not the line row\'s to make. Adopt |amount there and delete this entry. (Count'
            . ' dropped from 6 to 2 as sales_totals_footer.html.twig itself was conformed — see'
            . ' MoneyAndQuantityAreFormattedByTheFilterTest\'s other test; what remains here is'
            . ' this screen\'s own inline totals-box markup, not that shared partial.)'],
        'templates/admin/estimate/form.html.twig' => [2,
            'The same block on the quote, for the same reason. Note its figures are additionally'
            . ' guarded by a `is not null` ternary for the "No pricing"/TBD state (#254/#255), which'
            . ' the filters keep — |amount renders null as the empty string and never as 0.00.'
            . ' (Count dropped from 7 to 2 for the same reason as the order form\'s entry above.)'],
    ];

    /** The three filters, and what each is for, as the failure message spells them. */
    private const FILTERS = [
        'quantity' => '|qty',
        'unit price' => '|price',
        'money total' => '|amount',
    ];

    /**
     * Every hand-formatted money or quantity figure left in the templates.
     */
    public function testNoTemplateFormatsAMoneyOrQuantityFigureByHand(): void
    {
        $offenders = [];

        foreach ($this->handFormattedFigures() as $site) {
            if (isset(self::NOT_YET_ADOPTED[$site['file']])) {
                continue;
            }

            $offenders[] = sprintf(
                '%s:%d — %s applied to a %s (%s) — use %s',
                $site['file'],
                $site['line'],
                $site['construct'],
                $site['kind'],
                $site['because'],
                self::FILTERS[$site['kind']],
            );
        }

        sort($offenders);

        self::assertSame([], $offenders, sprintf(
            "These templates format a money or quantity figure by hand:\n\n  %s\n\n"
            . "Use the filters instead — App\\Service\\DisplayNumber owns the three rules and"
            . " App\\Twig\\DisplayNumberExtension registers them:\n\n"
            . "  {{ line.quantityEntered|qty }}    a quantity: up to 4 decimals, none when whole"
            . " (2.5 -> \"2.5\", 3 -> \"3\")\n"
            . "  {{ line.displayUnitPrice|price }} a UNIT price: 2 to 6 decimals, sub-cent"
            . " precision kept (0.917 -> \"0.917\")\n"
            . "  {{ invoice.total|amount }}        a money TOTAL: always exactly 2 decimals\n\n"
            . "The `\$` stays in the template, and so does any `is not null` guard: the filters"
            . " render null as the empty string, never as 0.00, so an unpriced quote line keeps"
            . " saying TBD rather than claiming to be free.\n\n"
            . "If the figure is NOT money or a quantity — a tax rate, a percentage, a row count, a"
            . " dashboard threshold — it should not be naming a money column or printing a currency"
            . " symbol, and fixing that is the fix. If it is a value posted back to a controller"
            . " (an <input>, an <option>, a hidden field, a data- attribute) it must keep its own"
            . " formatting: see LineDenomination::boxUntouched().",
            implode("\n  ", $offenders),
        ));
    }

    /**
     * The other half: a held template that has been conformed must lose its entry, or the list
     * slowly becomes a description of a tree that no longer exists.
     */
    public function testEveryHeldTemplateIsStillAnOffender(): void
    {
        $byFile = [];

        foreach ($this->handFormattedFigures() as $site) {
            $byFile[$site['file']] = ($byFile[$site['file']] ?? 0) + 1;
        }

        $stale = [];

        foreach (self::NOT_YET_ADOPTED as $file => [$count, $why]) {
            self::assertFileExists(
                $this->projectDir() . '/' . $file,
                sprintf('%s is named in NOT_YET_ADOPTED but no longer exists. Remove its entry.', $file),
            );
            self::assertNotSame('', trim($why), sprintf('%s is exempted with no reason beside it.', $file));

            $actual = $byFile[$file] ?? 0;

            if ($actual !== $count) {
                $stale[] = sprintf('%s — exempted for %d figure(s), found %d', $file, $count, $actual);
            }
        }

        sort($stale);

        self::assertSame([], $stale, sprintf(
            "These NOT_YET_ADOPTED entries no longer describe the templates:\n  %s\n\n"
            . "If the template has been conformed, delete its entry in the same commit so the"
            . " screen is held to the rule from here on. If figures were added to it, they were"
            . " added by hand and should have used the filters.",
            implode("\n  ", $stale),
        ));
    }

    /**
     * The discovery has to actually find the columns, or every assertion above is vacuously true.
     */
    public function testTheThreeColumnShapesAreDiscoveredFromTheSalesOrderLine(): void
    {
        $shapes = $this->columnShapes();

        self::assertNotSame([], $shapes['quantity'], 'no decimal fields share the quantity column shape');
        self::assertNotSame([], $shapes['unit price'], 'no decimal fields share the unit price column shape');
        self::assertNotSame([], $shapes['money total'], 'no decimal fields share the money total column shape');

        self::assertContains('quantityEntered', $shapes['quantity']);
        self::assertContains('displayUnitPrice', $shapes['unit price'], 'derived accessors are part of the subject set');
        self::assertContains('total', $shapes['money total']);

        // The rate columns must NOT be in any of them, or the rule sweeps percentages into money.
        foreach ($shapes as $kind => $fields) {
            self::assertNotContains('rate', $fields, sprintf('a tax rate must not read as a %s', $kind));
        }
    }

    // ── Discovery ───────────────────────────────────────────────────────────────────────────────

    /**
     * The property names that name a quantity, a unit price or a money total.
     *
     * Read from Doctrine: every mapped `decimal` field whose precision and scale match one of the
     * three shapes `SalesOrderLine` declares for `quantity`, `price` and `subtotal`. Plus the
     * derived accessors below, which are methods computing one of those columns at render and are
     * therefore the same kind of figure without being a column themselves.
     *
     * @return array<string, list<string>>
     */
    private function columnShapes(): array
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        $line = $em->getClassMetadata(SalesOrderLine::class);
        $shapeOf = static function (array|object $mapping): string {
            $precision = is_array($mapping) ? ($mapping['precision'] ?? null) : ($mapping->precision ?? null);
            $scale = is_array($mapping) ? ($mapping['scale'] ?? null) : ($mapping->scale ?? null);

            return sprintf('%d,%d', (int) $precision, (int) $scale);
        };

        $canonical = [
            'quantity' => $shapeOf($line->fieldMappings['quantity']),
            'unit price' => $shapeOf($line->fieldMappings['price']),
            'money total' => $shapeOf($line->fieldMappings['subtotal']),
        ];

        $shapes = ['quantity' => [], 'unit price' => [], 'money total' => []];

        foreach ($em->getMetadataFactory()->getAllMetadata() as $metadata) {
            foreach ($metadata->fieldMappings as $field => $mapping) {
                $type = is_array($mapping) ? ($mapping['type'] ?? null) : ($mapping->type ?? null);

                if ($type !== 'decimal') {
                    continue;
                }

                $kind = array_search($shapeOf($mapping), $canonical, true);

                if ($kind !== false && !in_array($field, $shapes[$kind], true)) {
                    $shapes[$kind][] = $field;
                }
            }
        }

        foreach (self::DERIVED_ACCESSORS as $kind => $accessors) {
            foreach ($accessors as $accessor) {
                if (!in_array($accessor, $shapes[$kind], true)) {
                    $shapes[$kind][] = $accessor;
                }
            }
        }

        return $shapes;
    }

    /**
     * Methods that compute one of the three columns at render time rather than storing it.
     *
     * Each is asserted to exist as a getter on a mapped entity by
     * {@see testEveryDerivedAccessorIsRealAndReturnsAFigure()}, so a renamed method fails here
     * rather than silently stopping the rule from applying to whatever it was guarding.
     *
     * @var array<string, list<string>>
     */
    private const DERIVED_ACCESSORS = [
        'quantity' => ['quantityEntered', 'invoicedQuantityInLineUnitFor', 'uninvoicedQuantityInLineUnitFor'],
        'unit price' => ['displayUnitPrice', 'baseUnitRate'],
        'money total' => ['shippingTotal', 'balance', 'amountPaid', 'lineTotal'],
    ];

    public function testEveryDerivedAccessorIsRealAndReturnsAFigure(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        $getters = [];

        foreach ($em->getMetadataFactory()->getAllMetadata() as $metadata) {
            foreach (get_class_methods($metadata->getName()) as $method) {
                // `getSubtotal()` is reached in Twig as `.subtotal`, and
                // `invoicedQuantityInLineUnitFor($line)` as `.invoicedQuantityInLineUnitFor(line)`.
                // Both spellings are how a template names a figure, so both count.
                $getters[lcfirst($method)] = true;

                if (str_starts_with($method, 'get')) {
                    $getters[lcfirst(substr($method, 3))] = true;
                }
            }
        }

        $missing = [];

        foreach (self::DERIVED_ACCESSORS as $kind => $accessors) {
            foreach ($accessors as $accessor) {
                if (!isset($getters[$accessor])) {
                    $missing[] = sprintf('%s (%s)', $accessor, $kind);
                }
            }
        }

        self::assertSame([], $missing, sprintf(
            "DERIVED_ACCESSORS names methods no mapped entity has:\n  %s\n\n"
            . "A renamed accessor silently stops this rule applying to every figure it guarded."
            . " Rename it here too, or delete it if the figure is gone.",
            implode("\n  ", $missing),
        ));
    }

    // ── Scanning ────────────────────────────────────────────────────────────────────────────────

    /**
     * Every site in every template that formats a money or quantity figure by hand.
     *
     * @return list<array{file: string, line: int, construct: string, kind: string, because: string}>
     */
    private function handFormattedFigures(): array
    {
        $shapes = $this->columnShapes();
        $found = [];

        foreach ($this->templates() as $path) {
            $file = substr($path, strlen($this->projectDir()) + 1);
            $source = (string) file_get_contents($path);
            $inTag = $this->tagMask($source);

            // `number_format(…)` and `round(…)` both render a figure; `+ 0` is the hand-rolled
            // spelling of the quantity rule the detail screens used to carry.
            preg_match_all(
                '/\|\s*(?:number_format|round)\s*\([^()]*\)|\+\s*0\s*(?=\}\})/',
                $source,
                $matches,
                PREG_OFFSET_CAPTURE,
            );

            foreach ($matches[0] as [$construct, $offset]) {
                if ($inTag[$offset] === '1') {
                    continue;
                }

                $expression = $this->expressionBefore($source, $offset);
                $kind = $this->kindOf($expression, $shapes);
                $because = $kind === null ? '' : sprintf('names %s', $this->fieldNamed($expression, $shapes[$kind]));

                if ($kind === null && $this->currencyMarked($source, $offset, $expression)) {
                    $kind = 'money total';
                    $because = 'printed with a currency symbol';
                }

                if ($kind === null) {
                    continue;
                }

                $found[] = [
                    'file' => $file,
                    'line' => substr_count($source, "\n", 0, $offset) + 1,
                    'construct' => trim($construct),
                    'kind' => $kind,
                    'because' => $because,
                ];
            }
        }

        return $found;
    }

    /**
     * Per character: '1' where that offset sits inside an HTML start tag.
     *
     * Twig regions are blanked first, so a `<=` inside an expression is not a tag opening and the
     * `}}` closing an attribute's own output tag is not a tag closing. State carries across lines,
     * which is what a multi-line `<input … value="{{ x }}">` needs.
     */
    private function tagMask(string $source): string
    {
        $blanked = (string) preg_replace_callback(
            '/\{[{%#].*?[}%#]\}/s',
            static fn(array $m): string => str_repeat(' ', strlen($m[0])),
            $source,
        );

        $mask = '';
        $inside = false;

        for ($i = 0, $len = strlen($blanked); $i < $len; $i++) {
            if ($blanked[$i] === '<') {
                $inside = true;
            } elseif ($blanked[$i] === '>') {
                $inside = false;
            }

            $mask .= $inside ? '1' : '0';
        }

        return $mask;
    }

    /** The expression the construct is applied to, back to the start of its term. */
    private function expressionBefore(string $source, int $offset): string
    {
        $segment = rtrim(substr($source, 0, $offset));
        $depth = 0;

        for ($i = strlen($segment) - 1; $i >= 0; $i--) {
            $char = $segment[$i];

            if ($char === ')' || $char === ']') {
                $depth++;
            } elseif ($char === '(' || $char === '[') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($depth === 0 && ($char === '{' || $char === '%')) {
                break;
            }
        }

        return substr($segment, $i + 1);
    }

    /**
     * Which of the three kinds the expression names, by PROPERTY ACCESS.
     *
     * A dotted access and not a bare identifier: `order.total` is a document's money total, while a
     * bare `total` in `{{ (total / limit)|round(0, 'ceil') }}` is a row count that happens to share
     * the name.
     *
     * @param array<string, list<string>> $shapes
     */
    private function kindOf(string $expression, array $shapes): ?string
    {
        foreach ($shapes as $kind => $fields) {
            if ($this->fieldNamed($expression, $fields) !== null) {
                return $kind;
            }
        }

        return null;
    }

    /** @param list<string> $fields */
    private function fieldNamed(string $expression, array $fields): ?string
    {
        preg_match_all('/\.\s*([A-Za-z_][A-Za-z0-9_]*)/', $expression, $accessed);

        foreach ($accessed[1] as $name) {
            if (in_array($name, $fields, true)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Whether the figure is rendered with a currency marker glued to it.
     *
     * A literal `$` immediately before the output tag, a `'$' ~` concatenation inside the
     * expression, or a `{{ something.currency }}` tag sitting right in front — which is how the
     * procurement screens, which are multi-currency, spell the same thing.
     */
    private function currencyMarked(string $source, int $offset, string $expression): bool
    {
        if (preg_match('/["\']\$["\']\s*~/', $expression) === 1) {
            return true;
        }

        $open = strrpos(substr($source, 0, $offset), '{{');

        if ($open === false) {
            return false;
        }

        $before = rtrim(substr($source, max(0, $open - 40), min(40, $open)));

        return str_ends_with($before, '$') || preg_match('/\.\s*currency\s*\}\}$/', $before) === 1;
    }

    /** @return list<string> */
    private function templates(): array
    {
        $roots = array_merge(
            [$this->projectDir() . '/templates'],
            glob($this->projectDir() . '/modules/*/templates') ?: [],
        );

        $paths = [];

        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            ) as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.twig')) {
                    $paths[] = $file->getPathname();
                }
            }
        }

        sort($paths);

        return $paths;
    }

    private function projectDir(): string
    {
        return dirname(__DIR__, 2);
    }
}
