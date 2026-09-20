<?php

declare(strict_types=1);

namespace App\Tests\Asset;

use App\Entity\ProductCore;
use App\Service\SuggestedPriceCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the price grid's Suggested Effective column to the server that owns the same rule (#452, #464).
 *
 * That column is computed twice: once in PHP by SuggestedPriceCalculator::getEffectivePrice(), which
 * decides what is stored and what a page load renders, and once in the browser by
 * recomputeSuggestedEffective() in templates/admin/product/prices.html.twig, which paints the cell
 * the instant an admin edits a row. Two implementations of one rule drift, and this pair did: the JS
 * kept the pre-#445/#446 shape (`type === '' || type === 'Number'` with a `v > 0` test) for two
 * releases after the server dropped it, so `Number`+`0` and `Number`+`-5` displayed the base price
 * while the server stored 0.00, and a blank type displayed a leftover stored value as though Number
 * had been selected. (That 0.00 is itself history now: #458 took the zero floor off both sides, so
 * `Number`+`-5` is stored, and displayed, as -5.00.) #464 then found the same drift one level down,
 * in how the two sides decide what the BASE price even is and what counts as a number at all.
 *
 * The divergence is not self-correcting, which is why it needs a test rather than a fix alone. The
 * save handler deliberately ignores the server's `data.effective` and recomputes from the DOM, so
 * that a slow response cannot stomp a base price the admin typed while it was in flight (a real
 * race, guarded on purpose). The consequence is that the server's correct answer never overwrites a
 * wrong client-side one — the figure stays wrong on screen until a full page reload.
 *
 * Unlike its siblings in this directory, this test does not pattern-match the asset's text: matching
 * text proves a line was written, not that it computes the right number, and the bug here was a
 * perfectly well-formed line. The function is instead lifted out of the Twig template and EXECUTED
 * against a stub row, and its output is compared with the PHP calculator's for the same inputs.
 * Both are then compared to a literal expectation, so neither side can be "fixed" to agree with a
 * regression in the other.
 *
 * The engine is Deno, not node — node and npm are banned by project policy and Deno replaces them —
 * and it runs under Deno's default sandbox, `deno run` with no `--allow-*` flags, which denies
 * filesystem, environment and network access to a chunk of application JavaScript that this test
 * hands to an interpreter. That constraint shapes the harness below: it cannot read its inputs off
 * disk, so the lifted functions and the case table are inlined into the harness source as literals.
 *
 * The per-price-list Effective columns — the Discount%/Discount$ side of the same grid, computed by
 * recomputeRowEffective() against updatePrice() — are covered the same way in
 * PriceGridRowEffectiveJsTest. The two share a base price and diverge immediately after, so testing
 * one proves half.
 */
final class PriceGridSuggestedEffectiveJsTest extends TestCase
{
    /** @var array<string, string>|null Results of the single Deno run, keyed by case. */
    private static ?array $jsResults = null;

    /**
     * Every row of the divergence table in #452, plus the branches that were already correct so a
     * future "fix" cannot trade one for another.
     *
     * The base price is a defaultPrice/originalPrice PAIR rather than a single field, because the
     * base is a chain and not a value: getBasePrice() takes defaultPrice when it is numeric and
     * originalPrice when it is not, and the grid mirrors that with the Default Price input and the
     * hidden `.js-product-price-base` field. Rows that only care about the arithmetic pass a blank
     * originalPrice; the chain itself is the subject of baseResolutionProvider() below.
     *
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public static function caseProvider(): iterable
    {
        // type, value, defaultPrice, originalPrice, expected displayed figure

        // The three rows #452 reported. Each of these rendered the left-hand column of the old
        // behaviour instead of the value the server holds.
        yield 'Number with a typed zero is a price of zero, not an absent value' => ['Number', '0', '12.50', '', '0.00'];
        yield 'Number with a negative value is that negative, not floored and not discarded' => ['Number', '-5', '12.50', '', '-5.00'];
        yield 'a blank type ignores a leftover value and shows the base price' => ['', '25', '12.50', '', '12.50'];

        // Already correct before #452 — regression guards for the fix itself.
        yield 'Number with an ordinary value' => ['Number', '25', '12.50', '', '25.00'];
        yield 'Number with no value at all falls back to the base price' => ['Number', '', '12.50', '', '12.50'];
        yield 'Number with a non-numeric value falls back to the base price' => ['Number', 'abc', '12.50', '', '12.50'];
        yield 'a blank type with no value shows the base price' => ['', '', '12.50', '', '12.50'];
        yield 'a blank type with a negative leftover value still shows the base price' => ['', '-5', '12.50', '', '12.50'];
        yield 'Markup$ adds to the base price' => ['Markup$', '5', '10.00', '', '15.00'];
        yield 'Markup$ with a negative value is a below-base loss leader' => ['Markup$', '-3', '10.00', '', '7.00'];
        yield 'Markup% adds a percentage of the base price' => ['Markup%', '10', '10.00', '', '11.00'];
        yield 'Markup% with a negative value is a below-base loss leader' => ['Markup%', '-25', '10.00', '', '7.50'];
        yield 'Markup% with no value at all shows the base price' => ['Markup%', '', '10.00', '', '10.00'];

        // #458: the two markup branches have never floored, and now Number does not either. These
        // pin the case that used to be the argument FOR the floor — a result under zero — so a
        // future reintroduction of `max(0, ...)` on either side fails here rather than passing
        // quietly because both sides were floored together.
        yield 'Markup$ may take the price below zero' => ['Markup$', '-20', '10.00', '', '-10.00'];
        yield 'Markup% may take the price below zero' => ['Markup%', '-200', '10.00', '', '-10.00'];
        yield 'nothing to show when there is no base price and no Number rule' => ['Markup%', '10', '', '', '-'];
        yield 'nothing to show for a bare product with no rule and no price' => ['', '', '', '', '-'];

        // #464 item 7. getEffectivePrice() falls off the end of its branch list and returns the base
        // price for a type it does not recognise; the client fell off the end of ITS branch list and
        // rendered a dash. Only reachable through bad data — the Suggested Type cell is a <select>
        // with three options — but bad data has to be answered the same way by both sides, and a
        // dash where the server holds 12.50 is not the same answer.
        yield 'an unrecognised rule type shows the base price, not a dash' => ['Discount%', '10', '12.50', '', '12.50'];
        yield 'an unrecognised rule type with no value still shows the base price' => ['Nonsense', '', '12.50', '', '12.50'];
        yield 'an unrecognised rule type with no base price shows a dash' => ['Discount%', '10', '', '', '-'];
    }

    #[DataProvider('caseProvider')]
    public function testTheBrowserShowsWhatTheServerWillStore(
        string $type,
        string $value,
        string $defaultPrice,
        string $originalPrice,
        string $expected,
    ): void {
        self::assertSame(
            $expected,
            $this->serverEffective($type, $value, $defaultPrice, $originalPrice),
            'SuggestedPriceCalculator no longer produces the agreed figure for this row.',
        );

        self::assertSame(
            $expected,
            $this->jsEffective($type, $value, $defaultPrice, $originalPrice),
            'recomputeSuggestedEffective() in prices.html.twig no longer agrees with '
            . 'SuggestedPriceCalculator for this row — the grid would show a number the server '
            . 'does not hold, and the save handler will not correct it (see #452, #464).',
        );
    }

    /**
     * The base-resolution table of #464 in full: every combination of Default Price and Product
     * Price that getBasePrice() distinguishes, rather than a sample of the interesting ones.
     *
     * Half of these rows passed before #464 was fixed, and they are here precisely because of HOW
     * they passed. "default 0, product 0" agreed only because the client rejected the zero Default
     * Price and then rejected the zero Product Price it fell through to, arriving at "no base" — the
     * same answer the server reaches by accepting the zero outright. Two errors cancelling. Repair
     * the fallback TRIGGER without also repairing what the fallback will ACCEPT and that row breaks,
     * so it guards the pair rather than either half of it.
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
     * Each row of the base table, read three ways.
     *
     * A blank type is the base itself, unmediated by any rule — that is what getEffectivePrice()
     * returns for `$type === ''` — so this reads the resolved base straight out of both
     * implementations instead of inferring it from an answer. `Markup$ 3` then puts arithmetic on
     * top of it, which catches a base that resolves correctly and is then mangled by a branch.
     *
     * `Number 25` is the control. It is the one rule that must ignore the base entirely, so it is
     * 25.00 down the whole table and fails only if a base-resolution change has leaked into a branch
     * with no business consulting the base.
     */
    #[DataProvider('baseResolutionProvider')]
    public function testTheBaseIsResolvedTheSameWayOnBothSides(
        string $defaultPrice,
        string $originalPrice,
        string $expectedBase,
    ): void {
        $markup = $expectedBase === '-' ? '-' : number_format((float) $expectedBase + 3, 2, '.', '');

        $expectations = [
            ['', '', $expectedBase],
            ['Markup$', '3', $markup],
            ['Number', '25', '25.00'],
        ];

        foreach ($expectations as [$type, $value, $expected]) {
            self::assertSame(
                $expected,
                $this->serverEffective($type, $value, $defaultPrice, $originalPrice),
                sprintf('SuggestedPriceCalculator no longer gives "%s %s" the agreed figure here.', $type, $value),
            );

            self::assertSame(
                $expected,
                $this->jsEffective($type, $value, $defaultPrice, $originalPrice),
                sprintf(
                    'recomputeSuggestedEffective() disagrees with SuggestedPriceCalculator on '
                    . '"%s %s" for this pair of price fields (#464).',
                    $type,
                    $value,
                ),
            );
        }
    }

    /**
     * The rule-value table of #464: what each side treats as a value at all.
     *
     * parseFloat() reads the longest numeric prefix, so "12abc" was 12 in the browser and absent on
     * the server. is_numeric() accepts exponent notation, so "1e3" was 1000 on the server — and that
     * is the one place in this issue where the SERVER was judged wrong and changed to match, since
     * three keystrokes in a money field are not a request for a thousand dollars. Both are now the
     * same question, asked by numericValue() and by PriceNumber::isPrice().
     *
     * A rejected value is an ABSENT value, not a zero: every row here resolves to the base price
     * when there is one and to a dash when there is not.
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
    public function testARejectedRuleValueResolvesToTheBasePriceOnBothSides(string $value): void
    {
        // With a base: every rule type answers with the base price, because a value that is not a
        // number is a rule that cannot be applied.
        foreach (['Number', 'Markup$', 'Markup%', ''] as $type) {
            self::assertSame(
                '12.50',
                $this->serverEffective($type, $value, '12.50', ''),
                sprintf('SuggestedPriceCalculator accepted "%s" as a %s value.', $value, $type ?: 'blank-type'),
            );

            self::assertSame(
                '12.50',
                $this->jsEffective($type, $value, '12.50', ''),
                sprintf(
                    'recomputeSuggestedEffective() computed something from "%s" under %s. A value '
                    . 'the server treats as absent must not become a figure on screen (#464).',
                    $value,
                    $type ?: 'a blank type',
                ),
            );
        }

        // Without a base there is nothing to fall back TO, so both sides show a dash rather than
        // inventing a number out of the rejected value.
        foreach (['Number', 'Markup$', 'Markup%', ''] as $type) {
            self::assertSame(
                '-',
                $this->serverEffective($type, $value, '', ''),
                sprintf('SuggestedPriceCalculator produced a figure from "%s" with no base.', $value),
            );

            self::assertSame(
                '-',
                $this->jsEffective($type, $value, '', ''),
                sprintf('recomputeSuggestedEffective() produced a figure from "%s" with no base.', $value),
            );
        }
    }

    /**
     * The values on the other side of that line, so the rejections above cannot be achieved by
     * rejecting everything. Plain decimals and negatives keep working, and a `0` is still a price.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function acceptedRuleValueProvider(): iterable
    {
        yield 'a plain integer is a number ("25")' => ['25', '25.00'];
        yield 'a plain decimal is a number ("12.75")' => ['12.75', '12.75'];
        yield 'a trailing-point decimal is a number ("12.")' => ['12.', '12.00'];
        yield 'a bare fraction is a number (".5")' => ['.5', '0.50'];
        yield 'a negative is a number ("-5")' => ['-5', '-5.00'];
        yield 'a negative decimal is a number ("-5.25")' => ['-5.25', '-5.25'];
        yield 'an explicit plus sign is a number ("+7")' => ['+7', '7.00'];
        yield 'a zero is a number, and is a price of zero ("0")' => ['0', '0.00'];
        yield 'surrounding whitespace does not stop a value being a number' => ["  12  ", '12.00'];
    }

    #[DataProvider('acceptedRuleValueProvider')]
    public function testAnAcceptedRuleValueIsUsedByBothSides(string $value, string $expected): void
    {
        self::assertSame(
            $expected,
            $this->serverEffective('Number', $value, '12.50', ''),
            sprintf('SuggestedPriceCalculator no longer accepts "%s" as a price.', $value),
        );

        self::assertSame(
            $expected,
            $this->jsEffective('Number', $value, '12.50', ''),
            sprintf(
                'numericValue() rejected "%s", which the server accepts. Over-tightening the client '
                . 'is as much a divergence as under-tightening it (#464).',
                $value,
            ),
        );
    }

    /**
     * The same question, asked of the base-price fields rather than the rule value.
     *
     * baseResolutionProvider() covers "12abc" because #464 named it; these cover the rest of the
     * grammar, on the field that can actually hold them. `.js-product-price-base` is a hidden input
     * rendered straight from original_price, so whatever the database holds arrives here verbatim —
     * unlike the visible Default Price cell, which is `<input type="number">` and normalises.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function basePriceGrammarProvider(): iterable
    {
        yield 'a hexadecimal product price is not a base ("0x1A")' => ['0x1A', '-'];
        yield 'an exponent product price is not a base ("1e3")' => ['1e3', '-'];
        yield 'an uppercase exponent product price is not a base ("1E3")' => ['1E3', '-'];
        yield 'letters are not a base ("abc")' => ['abc', '-'];
        yield 'a padded product price is still a base' => ['  12  ', '12.00'];
        yield 'a bare fraction is a base (".5")' => ['.5', '0.50'];
        yield 'a negative product price is a base ("-5")' => ['-5', '-5.00'];
    }

    #[DataProvider('basePriceGrammarProvider')]
    public function testTheBasePriceFieldsAskTheSameGrammarOnBothSides(string $originalPrice, string $expected): void
    {
        self::assertSame(
            $expected,
            $this->serverEffective('', '', '', $originalPrice),
            sprintf('SuggestedPriceCalculator::getBasePrice() no longer reads "%s" this way.', $originalPrice),
        );

        self::assertSame(
            $expected,
            $this->jsEffective('', '', '', $originalPrice),
            sprintf('resolveBasePrice() disagrees with getBasePrice() about "%s" (#464).', $originalPrice),
        );
    }

    /**
     * The save handler's refusal to trust the server's answer is deliberate and must survive this
     * fix. Making the client agree with the server is the right repair; making the client stop
     * computing and just take `data.effective` is not, because a response reflects the base price
     * the server saw when the request was sent — a slow one would overwrite a newer Default Price
     * the admin has since typed.
     */
    public function testTheSaveHandlerStillRecomputesFromTheDomInsteadOfTrustingTheResponse(): void
    {
        $handler = PriceGridScript::slice('function postSuggestedUpdate(', 'function postUpdate(');

        self::assertStringContainsString(
            'recomputeSuggestedEffective(row, { save: false })',
            $handler,
            'The save handler must recompute the column from the DOM after a successful save.',
        );
        // Comments are dropped first: the handler explains at length why it does NOT read
        // data.effective, and that explanation must not be mistaken for the thing it warns against.
        $code = PriceGridScript::stripComments($handler);

        self::assertStringNotContainsString(
            'data.effective',
            $code,
            'The save handler must not adopt the server\'s effective price: a late response would '
            . 'stomp a base price the admin changed while the request was in flight.',
        );
    }

    /**
     * The tables above are only meaningful while the client is still asking the server's question.
     * Both halves of the old guard are named here so that a re-introduction fails with an
     * explanation attached rather than as an arithmetic surprise thirty rows down.
     *
     * parseFloat is included for the same reason: it is the whole of #464's second half, it has no
     * remaining legitimate use in this script, and every call it makes returns a number for input
     * the server treats as absent.
     */
    public function testTheClientAsksWhetherItIsANumberNotWhetherItIsPositive(): void
    {
        $code = PriceGridScript::code();

        self::assertStringNotContainsString(
            'base <= 0',
            $code,
            'The base-price guard tests for positivity again. getBasePrice() has no sign test: '
            . 'is_numeric("0") and is_numeric("-5") are both true, so both are valid bases (#464).',
        );

        self::assertStringNotContainsString(
            'productPrice > 0',
            $code,
            'The product-price fallback is accepted only when positive again. It stands in for '
            . 'original_price, which the server accepts whenever it is numeric (#464).',
        );

        self::assertStringNotContainsString(
            'parseFloat(',
            $code,
            'parseFloat() reads "12abc" as 12; the server gates on is_numeric() and treats it as '
            . 'absent. Every field in this grid must go through numericValue() instead (#464).',
        );
    }

    /**
     * The server's base-price chain asserted directly, rather than inferred from the rows above:
     * defaultPrice when numeric, originalPrice when it is not, and no sign test at either step.
     * This is the behaviour the client was conformed to, so if it ever changes it is the conforming
     * that needs revisiting — not the client.
     */
    public function testTheServerAcceptsAnyNumericBaseIncludingZeroAndNegative(): void
    {
        $calculator = new SuggestedPriceCalculator();

        self::assertSame(
            '0',
            $calculator->getBasePrice((new ProductCore())->setDefaultPrice('0')->setOriginalPrice('20.00')),
            'A zero defaultPrice is numeric, so it is the base — originalPrice is not consulted.',
        );

        self::assertSame(
            '-5',
            $calculator->getBasePrice((new ProductCore())->setDefaultPrice('-5')->setOriginalPrice('20.00')),
            'A negative defaultPrice is numeric, so it is the base too.',
        );

        self::assertSame(
            '0',
            $calculator->getBasePrice((new ProductCore())->setDefaultPrice('abc')->setOriginalPrice('0')),
            'A zero originalPrice is a usable fallback base, not a reason to give up.',
        );

        self::assertNull(
            $calculator->getBasePrice((new ProductCore())->setDefaultPrice('12abc')->setOriginalPrice('0x1A')),
            'Only non-numeric input exhausts the chain — and "12abc"/"0x1A" are non-numeric.',
        );
    }

    private function serverEffective(
        string $type,
        string $value,
        string $defaultPrice,
        string $originalPrice,
    ): string {
        $product = new ProductCore();
        if ($defaultPrice !== '') {
            $product->setDefaultPrice($defaultPrice);
        }
        if ($originalPrice !== '') {
            $product->setOriginalPrice($originalPrice);
        }
        if ($type !== '') {
            $product->setSuggestedPriceType($type);
        }
        if ($value !== '') {
            $product->setSuggestedPriceValue($value);
        }

        $effective = (new SuggestedPriceCalculator())->getEffectivePrice($product);

        // The column is what is being compared, not the storage string, so both sides are reduced
        // to the figure the admin reads: two decimals, or the em-less dash the cell shows for
        // "nothing to display".
        return $effective === null ? '-' : number_format((float) $effective, 2, '.', '');
    }

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

    /** The four inputs identify a case uniquely, on both sides of the pipe to the JS runtime. */
    private static function caseKey(
        string $type,
        string $value,
        string $defaultPrice,
        string $originalPrice,
    ): string {
        return $type . '|' . $value . '|' . $defaultPrice . '|' . $originalPrice;
    }

    /**
     * Every (type, value, defaultPrice, originalPrice) any test in this class will ask about.
     *
     * All of them go through one Deno process, so a new provider must not mean a new process — it
     * means another loop here. Duplicates across providers are expected and are collapsed by the
     * keying in runFunctionInDeno().
     *
     * @return iterable<array{0: string, 1: string, 2: string, 3: string}>
     */
    private static function everyJsCase(): iterable
    {
        foreach (self::caseProvider() as [$type, $value, $defaultPrice, $originalPrice]) {
            yield [$type, $value, $defaultPrice, $originalPrice];
        }

        foreach (self::baseResolutionProvider() as [$defaultPrice, $originalPrice]) {
            yield ['', '', $defaultPrice, $originalPrice];
            yield ['Markup$', '3', $defaultPrice, $originalPrice];
            yield ['Number', '25', $defaultPrice, $originalPrice];
        }

        foreach (self::rejectedRuleValueProvider() as [$value]) {
            foreach (['Number', 'Markup$', 'Markup%', ''] as $type) {
                yield [$type, $value, '12.50', ''];
                yield [$type, $value, '', ''];
            }
        }

        foreach (self::acceptedRuleValueProvider() as [$value]) {
            yield ['Number', $value, '12.50', ''];
        }

        foreach (self::basePriceGrammarProvider() as [$originalPrice]) {
            yield ['', '', '', $originalPrice];
        }
    }

    /**
     * Runs every case through the real function in one Deno process, memoized for the class.
     *
     * The function is lifted verbatim out of the Twig template; it contains no Twig tags of its own
     * (the `{{ path(...) }}` calls live further down, in postSuggestedUpdate()), so it can be
     * evaluated as plain JavaScript.
     *
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

    private function functionSource(): string
    {
        return PriceGridScript::sharedHelpers()
            . PriceGridScript::slice('function recomputeSuggestedEffective(', 'function postSuggestedUpdate(');
    }

    /**
     * The harness with this run's inputs baked in.
     *
     * The sandbox has no filesystem access, so the lifted functions and the case table cannot be
     * passed as files or read from argv — they are substituted into the source as literals instead.
     * json_encode produces a valid JavaScript literal in both slots, and it is the only thing
     * between the template's source text and the interpreter, so both placeholders go through it.
     *
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
     * Stands in for the browser: a row whose querySelector answers with the five elements the
     * function reaches for, and a postSuggestedUpdate() that fails loudly if a { save: false }
     * recompute ever tries to hit the network.
     *
     * `.js-product-price-base` is parameterised, and that is not a detail. It used to be hard-coded
     * to a blank value, which pinned the base-price fallback permanently OFF: the second half of the
     * chain — the half #464 was hiding in — was never once executed by this harness, so six rows of
     * the base table could not be expressed at all.
     *
     * The function under test is still compiled from the template's own text at run time, via
     * `new Function`: inlining changes how the source reaches the interpreter, not the fact that the
     * shipped code is what executes.
     */
    private const HARNESS = <<<'JS'
        const source = __FUNCTION_SOURCE__;
        const cases = __CASES__;

        function postSuggestedUpdate() {
            throw new Error('recomputeSuggestedEffective() posted to the server under { save: false }.');
        }

        const recompute = new Function(
            'postSuggestedUpdate',
            source + '\nreturn recomputeSuggestedEffective;'
        )(postSuggestedUpdate);

        const results = {};

        cases.forEach(function (c) {
            const span = { textContent: '<never written>' };
            const nodes = {
                '.js-suggested-type': { value: c.type },
                '.js-suggested-value': { value: c.value },
                '.js-suggested-effective': span,
                '.js-core-price-input': { value: c.defaultPrice },
                '.js-product-price-base': { value: c.originalPrice }
            };

            recompute({
                querySelector: function (selector) {
                    return Object.prototype.hasOwnProperty.call(nodes, selector) ? nodes[selector] : null;
                }
            }, { save: false });

            results[c.key] = span.textContent;
        });

        console.log(JSON.stringify(results));
        JS;
}
