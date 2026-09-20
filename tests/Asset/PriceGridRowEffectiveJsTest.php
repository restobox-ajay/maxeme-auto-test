<?php

declare(strict_types=1);

namespace App\Tests\Asset;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The other half of the price grid: the per-price-list Effective columns, computed in the browser by
 * recomputeRowEffective() and on the server by Admin\ProductController::updatePrice() (#464).
 *
 * PriceGridSuggestedEffectiveJsTest covers the Suggested Effective column next door. The two share a
 * base price — the same default_price/original_price chain, resolved by the same resolveBasePrice()
 * — and then diverge immediately: one applies Number/Markup$/Markup%, this one applies
 * Number/Discount%/Discount$. Testing either alone proves half of the base-resolution table, which
 * is why this file exists rather than a few extra rows over there.
 *
 * This side had the worse symptom of the two. Its guards were early `return`s, not fallbacks: given
 * a base of 0 or below, `Discount%` and `Discount$` abandoned the update entirely and left the
 * previous figure sitting in the cell, while the server went ahead and computed and saved a
 * different one. Nothing on screen said the two had parted company — the admin saw a stale number
 * that looked exactly like a fresh one.
 *
 * The harness is the same idea as its sibling's: the real function is lifted verbatim out of the
 * Twig template and EXECUTED under Deno's default sandbox (`deno run`, no `--allow-*` flags), driven
 * against a stub row. What it writes is compared to a literal table, not to a re-implementation.
 *
 * The PHP side of that table is not asserted here — updatePrice() is a controller action and needs a
 * request and a database — it is asserted end-to-end, row for row, in
 * tests/Functional/AdminProductPriceRuleBaseResolutionCest.php. The two files carry the same table
 * on purpose: this one proves the browser computes it, that one proves the server stores it, and a
 * change to either side that breaks the agreement turns exactly one of them red.
 */
final class PriceGridRowEffectiveJsTest extends TestCase
{
    /**
     * What the harness reports when recomputeRowEffective() wrote nothing at all.
     *
     * That is a real outcome and not a failure: with no base price and no usable rule, updatePrice()
     * falls back to the `price` field the POST carried, which IS this hidden input. Leaving it alone
     * is how the client agrees with that. The sentinel makes "wrote nothing" distinguishable from
     * "wrote a figure that happens to equal what was there", which a blank seed would not.
     */
    private const UNCHANGED = '<stale>/<stale>';

    /** @var array<string, string>|null Results of the single Deno run, keyed by case. */
    private static ?array $jsResults = null;

    /**
     * The base-resolution table of #464, in full, for the discount side.
     *
     * Identical inputs to PriceGridSuggestedEffectiveJsTest::baseResolutionProvider() — it is the
     * same chain being resolved — and the same reason for including rows that already passed: half
     * of them passed only because two errors cancelled. "default 0, product 0" resolved to no base
     * on the client (zero rejected, then the zero fallback rejected too) and to a base of 0 on the
     * server, and the client's early `return` meant neither wrote anything visible, so the row
     * looked quiet. Fix the fallback trigger without fixing what the fallback accepts and it stops
     * being quiet.
     *
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function baseResolutionProvider(): iterable
    {
        // defaultPrice, originalPrice, the resolved base as money ('-' for no base at all)

        yield 'default 10, product 20 -> base 10' => ['10', '20', '10.00'];
        yield 'default 10, product blank -> base 10' => ['10', '', '10.00'];
        yield 'default 0, product 20 -> base 0' => ['0', '20', '0.00'];
        yield 'default 0, product 0 -> base 0' => ['0', '0', '0.00'];
        yield 'default 0, product blank -> base 0' => ['0', '', '0.00'];
        yield 'default -5, product 20 -> base -5' => ['-5', '20', '-5.00'];
        yield 'default -5, product 0 -> base -5' => ['-5', '0', '-5.00'];
        yield 'default -5, product blank -> base -5' => ['-5', '', '-5.00'];
        yield 'default blank, product 20 -> base 20' => ['', '20', '20.00'];
        yield 'default blank, product 0 -> base 0' => ['', '0', '0.00'];
        yield 'default blank, product -5 -> base -5' => ['', '-5', '-5.00'];
        yield 'default blank, product blank -> no base' => ['', '', '-'];
        yield 'default 12abc, product 20 -> base 20' => ['12abc', '20', '20.00'];
        yield 'default 12abc, product blank -> no base' => ['12abc', '', '-'];
    }

    /**
     * Each row of the base table, read four ways.
     *
     * `Discount$ 3` and `Discount% 10` are the readings: the base is arithmetic in both, so the
     * figures name it exactly, and between them they catch a base that is right in one branch and
     * wrong in the other. A blank type is the base itself with no rule over it — updatePrice()
     * applies no rule it does not recognise and falls back to the base price.
     *
     * `Number 25` is the control. It is the one rule that must ignore the base entirely, so it
     * stays 25.00 down the whole table, including the two rows where there is no base at all, and
     * fails only if a base-resolution change has leaked into a branch that has no business
     * consulting the base.
     */
    #[DataProvider('baseResolutionProvider')]
    public function testTheBrowserComputesTheServersBase(
        string $defaultPrice,
        string $originalPrice,
        string $expectedBase,
    ): void {
        $noBase = $expectedBase === '-';
        $base = $noBase ? 0.0 : (float) $expectedBase;

        $expectations = [
            // A rule that cannot be applied resolves to the base price, and with no base at all
            // nothing is written — the server keeps whatever the POST carried, which is this cell.
            ['', '', $noBase ? self::UNCHANGED : self::money($base)],
            ['Discount$', '3', $noBase ? self::UNCHANGED : self::money($base - 3)],
            ['Discount%', '10', $noBase ? self::UNCHANGED : self::money($base * 0.9)],
            ['Number', '25', self::money(25)],
        ];

        foreach ($expectations as [$type, $value, $expected]) {
            self::assertSame(
                $expected,
                $this->jsEffective($type, $value, $defaultPrice, $originalPrice),
                sprintf(
                    'recomputeRowEffective() resolved a different base from updatePrice() for '
                    . '"%s %s" over this pair of price fields (#464). Expected the posted price and '
                    . 'the displayed price to be %s.',
                    $type ?: '(no type)',
                    $value,
                    $expected,
                ),
            );
        }
    }

    /**
     * The rule-value table of #464 on this side: what counts as a value at all.
     *
     * parseFloat() turned "12abc" into 12 and computed a discount from it; is_numeric() rejects it
     * and the server discounts nothing. And "1e3" is the one row where the SERVER was judged wrong
     * and changed to match — PriceNumber::isPrice() now refuses exponent notation, so a rule value
     * of `1e3` is not a 1000% discount, it is not a value.
     *
     * Every rejected value here resolves to the BASE PRICE, which is the third of #464's seven and
     * the one that is easiest to get half right: the client used to `return` on a value it could not
     * parse, leaving a stale figure, where updatePrice() writes the base.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function rejectedRuleValueProvider(): iterable
    {
        yield 'a numeric prefix is not a number ("12abc")' => ['12abc'];
        yield 'an empty value is not a number' => [''];
        yield 'a hexadecimal literal is not a number ("0x1A")' => ['0x1A'];
        yield 'lowercase exponent notation is not a price ("1e3")' => ['1e3'];
        yield 'uppercase exponent notation is not a price ("1E3")' => ['1E3'];
        yield 'letters are not a number ("abc")' => ['abc'];
    }

    #[DataProvider('rejectedRuleValueProvider')]
    public function testARejectedRuleValueWritesTheBasePrice(string $value): void
    {
        foreach (['Discount%', 'Discount$', 'Number', ''] as $type) {
            self::assertSame(
                '12.50/12.50',
                $this->jsEffective($type, $value, '12.50', ''),
                sprintf(
                    'recomputeRowEffective() did not fall back to the base price for a %s rule with '
                    . 'the unusable value "%s". updatePrice() writes the base here; abandoning the '
                    . 'update leaves a stale figure on screen with nothing to say the save went '
                    . 'somewhere else (#464).',
                    $type ?: 'blank-type',
                    $value,
                ),
            );
        }

        // With no base there is nothing to fall back TO. updatePrice() keeps the posted price, so
        // the client leaves the cell exactly as it found it rather than inventing a figure.
        foreach (['Discount%', 'Discount$', 'Number', ''] as $type) {
            self::assertSame(
                self::UNCHANGED,
                $this->jsEffective($type, $value, '', ''),
                sprintf('recomputeRowEffective() invented a figure from "%s" with no base.', $value),
            );
        }
    }

    /**
     * The other side of that line, so the rejections above cannot be achieved by rejecting
     * everything. These are all values PriceNumber::isPrice() accepts.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function acceptedRuleValueProvider(): iterable
    {
        // value, expected effective for `Discount$ <value>` over a base of 12.50

        yield 'a plain integer is a number ("5")' => ['5', '7.50'];
        yield 'a plain decimal is a number ("2.25")' => ['2.25', '10.25'];
        yield 'a trailing-point decimal is a number ("2.")' => ['2.', '10.50'];
        yield 'a bare fraction is a number (".5")' => ['.5', '12.00'];
        yield 'a negative discount is a premium ("-5")' => ['-5', '17.50'];
        yield 'an explicit plus sign is a number ("+5")' => ['+5', '7.50'];
        yield 'a zero discount leaves the base price ("0")' => ['0', '12.50'];
        yield 'a discount larger than the base goes negative ("20")' => ['20', '-7.50'];
        yield 'surrounding whitespace does not stop a value being a number' => ["  5  ", '7.50'];
    }

    #[DataProvider('acceptedRuleValueProvider')]
    public function testAnAcceptedRuleValueIsApplied(string $value, string $expected): void
    {
        self::assertSame(
            $expected . '/' . $expected,
            $this->jsEffective('Discount$', $value, '12.50', ''),
            sprintf(
                'numericValue() rejected "%s", which updatePrice() accepts. Over-tightening the '
                . 'client is as much a divergence as under-tightening it (#464).',
                $value,
            ),
        );
    }

    /**
     * An unrecognised rule type. updatePrice() only ever applies Discount%/Discount$/Number, so
     * anything else is not a rule and lands on the base price; the client used to treat every
     * unrecognised type as though it were `Number` and write the rule VALUE into the price cell.
     *
     * Only reachable through bad data — the Type cell is a `<select>` — but bad data has to be
     * answered the same way by both sides, and writing 10.00 where the server writes 12.50 is not
     * the same answer.
     */
    public function testAnUnrecognisedRuleTypeWritesTheBasePrice(): void
    {
        self::assertSame(
            '12.50/12.50',
            $this->jsEffective('Nonsense', '10', '12.50', ''),
            'An unrecognised rule type must fall back to the base price, as updatePrice() does — '
            . 'not be treated as Number and write the rule value into the price (#464).',
        );

        self::assertSame(
            self::UNCHANGED,
            $this->jsEffective('Nonsense', '10', '', ''),
            'An unrecognised rule type with no base price must leave the posted price alone.',
        );
    }

    /**
     * The rows above are only meaningful while the guards are still fallbacks. These were early
     * `return`s until #464, which is a different failure from a wrong number and a quieter one, so
     * a relapse is named here explicitly rather than left to be inferred from arithmetic.
     */
    public function testTheDiscountBranchesFallBackRatherThanAbandonTheUpdate(): void
    {
        $code = PriceGridScript::stripComments(
            PriceGridScript::slice('function recomputeRowEffective(', 'function showToast('),
        );

        self::assertStringNotContainsString(
            'base <= 0',
            $code,
            'The discount branches test the base for positivity again. updatePrice() applies a rule '
            . 'whenever the base is a number, and 0 and negatives are numbers (#464).',
        );

        self::assertStringContainsString(
            'effective = base;',
            $code,
            'A rule that cannot be applied must resolve to the base price, the way updatePrice() '
            . 'does. Returning instead leaves a stale figure in a cell the server has just '
            . 'overwritten with something else (#464).',
        );
    }

    private static function money(float $value): string
    {
        $formatted = number_format(round($value, 2), 2, '.', '');

        return $formatted . '/' . $formatted;
    }

    /**
     * The figure recomputeRowEffective() posted and the figure it displayed, joined.
     *
     * Both are reported rather than just the visible one because they are written from the same
     * variable and a disagreement between them would be a bug in its own right — the hidden input is
     * what the POST carries, so a cell showing one number while sending another is precisely the
     * class of problem this file exists to catch.
     */
    private function jsEffective(
        string $type,
        string $value,
        string $defaultPrice,
        string $originalPrice,
    ): string {
        $key = self::caseKey($type, $value, $defaultPrice, $originalPrice);
        $results = $this->runFunctionInDeno();

        self::assertArrayHasKey($key, $results, 'The JS harness returned no result for this case.');

        return $results[$key];
    }

    private static function caseKey(
        string $type,
        string $value,
        string $defaultPrice,
        string $originalPrice,
    ): string {
        return $type . '|' . $value . '|' . $defaultPrice . '|' . $originalPrice;
    }

    /**
     * Every case any test in this class will ask about, all through one Deno process.
     *
     * @return iterable<array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function everyJsCase(): iterable
    {
        foreach (self::baseResolutionProvider() as [$defaultPrice, $originalPrice]) {
            yield ['', '', $defaultPrice, $originalPrice];
            yield ['Discount$', '3', $defaultPrice, $originalPrice];
            yield ['Discount%', '10', $defaultPrice, $originalPrice];
            yield ['Number', '25', $defaultPrice, $originalPrice];
        }

        foreach (self::rejectedRuleValueProvider() as [$value]) {
            foreach (['Discount%', 'Discount$', 'Number', ''] as $type) {
                yield [$type, $value, '12.50', ''];
                yield [$type, $value, '', ''];
            }
        }

        foreach (self::acceptedRuleValueProvider() as [$value]) {
            yield ['Discount$', $value, '12.50', ''];
        }

        yield ['Nonsense', '10', '12.50', ''];
        yield ['Nonsense', '10', '', ''];
    }

    /**
     * @return array<string, string>
     */
    private function runFunctionInDeno(): array
    {
        if (self::$jsResults !== null) {
            return self::$jsResults;
        }

        $cases = [];
        foreach (self::everyJsCase() as [$type, $value, $defaultPrice, $originalPrice]) {
            $key = self::caseKey($type, $value, $defaultPrice, $originalPrice);
            $cases[$key] = [
                'key' => $key,
                'type' => $type,
                'value' => $value,
                'defaultPrice' => $defaultPrice,
                'originalPrice' => $originalPrice,
            ];
        }

        $stdout = DenoRuntime::run($this->harnessSource(array_values($cases)));

        /** @var array<string, string> $decoded */
        $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);

        return self::$jsResults = $decoded;
    }

    /**
     * numericValue()/formatMoney()/resolveBasePrice(), then setCellDisplayText() and the function
     * under test. All lifted verbatim; the template has no Twig tags in this range (the
     * `{{ path(...) }}` calls live further down, in postUpdate()).
     */
    private function functionSource(): string
    {
        return PriceGridScript::sharedHelpers()
            . PriceGridScript::slice('function setCellDisplayText(', 'function showToast(');
    }

    /**
     * @param list<array{key: string, type: string, value: string, defaultPrice: string, originalPrice: string}> $cases
     */
    private function harnessSource(array $cases): string
    {
        return strtr(self::HARNESS, [
            '__FUNCTION_SOURCE__' => json_encode($this->functionSource(), JSON_THROW_ON_ERROR),
            '__CASES__' => json_encode($cases, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * Stands in for the browser: one row carrying one price list, whose querySelector and
     * querySelectorAll answer with the elements the function reaches for, and a postUpdate() that
     * fails loudly if a { save: false } recompute ever tries to hit the network.
     *
     * The hidden price input and the Effective span are seeded with a sentinel rather than a blank,
     * so that "the function wrote nothing" is reported as itself instead of being indistinguishable
     * from "the function wrote something that happens to match what was there".
     */
    private const HARNESS = <<<'JS'
        const source = __FUNCTION_SOURCE__;
        const cases = __CASES__;

        function postUpdate() {
            throw new Error('recomputeRowEffective() posted to the server under { save: false }.');
        }

        const recompute = new Function(
            'postUpdate',
            source + '\nreturn recomputeRowEffective;'
        )(postUpdate);

        const results = {};

        cases.forEach(function (c) {
            const hidden = {
                value: '<stale>',
                getAttribute: function (name) { return name === 'data-price-list-id' ? '1' : null; }
            };
            const span = { textContent: '<stale>' };

            // closest() answers null throughout: setCellDisplayText() is only reached by the
            // No Price / Hide branch, which returns before any of the arithmetic under test, and a
            // null cell is what it is written to tolerate.
            const nothing = function () { return null; };
            const nodes = {
                '.js-core-price-input': { value: c.defaultPrice, closest: nothing },
                '.js-product-price-base': { value: c.originalPrice, closest: nothing },
                '.js-price-type[data-price-list-id="1"]': { value: c.type, closest: nothing },
                '.js-price-value[data-price-list-id="1"]': { value: c.value, closest: nothing },
                '.js-price-effective[data-price-list-id="1"]': span
            };

            recompute({
                querySelector: function (selector) {
                    return Object.prototype.hasOwnProperty.call(nodes, selector) ? nodes[selector] : null;
                },
                querySelectorAll: function (selector) {
                    return selector === '.js-price-input[data-price-list-id]' ? [hidden] : [];
                }
            }, { save: false });

            results[c.key] = hidden.value + '/' + span.textContent;
        });

        console.log(JSON.stringify(results));
        JS;
}
