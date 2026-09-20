<?php

declare(strict_types=1);

namespace App\Tests\Asset;

use PHPUnit\Framework\TestCase;

/**
 * Guards the `white-space` cascade over estimate's line table in public/assets/css/app.css.
 *
 * Estimate's line table carries both `.wide-price-table` (the shared base class, written for the bulk
 * pricing/inventory grids) and `.estimate-line-table`. The base class drags in a `nowrap` rule whose
 * three `:not()` arguments push it to specificity (0,4,1) — enough to beat the (0,2,1)
 * `.wide-price-table.estimate-line-table td` block that asks for `normal`. That silently forced every
 * estimate line cell back to `nowrap`, and the computed fee rows' label cell (a bare text node, see
 * `estimate_fee_line_row` in templates/admin/estimate/form.html.twig) ran past its column instead of
 * wrapping onto a second line.
 *
 * Codeception can't help here — it doesn't execute CSS — so this resolves the cascade itself: it parses
 * every style rule declaring `white-space`, keeps the ones whose selector matches the cell in question,
 * and picks the winner by `!important`, then specificity, then source order. That catches the original
 * bug and any future rule that outranks the fix, rather than just asserting some literal text is present.
 *
 * Scope: the base (unconditional) cascade only. Rules nested in at-rules are excluded from the cascade
 * resolution since evaluating `@media` conditions would need a viewport; the second test asserts
 * separately that no rule anywhere in the file — at-rule nested or not — re-imposes `nowrap` on the cell.
 */
final class EstimateLineTableWrapCssTest extends TestCase
{
    private const CSS_PATH = __DIR__ . '/../../public/assets/css/app.css';

    /**
     * The fee row estimate's form renders per computed fee (BC Environmental Fee, BC Recycling Fee, …),
     * as an ancestor chain from the table down to the label cell that actually overflowed.
     */
    private const FEE_LABEL_CELL = [
        ['tag' => 'table', 'classes' => ['wide-price-table', 'estimate-line-table'], 'attrs' => []],
        ['tag' => 'tbody', 'classes' => [], 'attrs' => []],
        ['tag' => 'tr', 'classes' => ['estimate-line-row', 'fee-line-row'], 'attrs' => []],
        ['tag' => 'td', 'classes' => [], 'attrs' => ['data-label' => 'Product']],
    ];

    /** A plain cell in the bulk pricing grid the original `nowrap` rule was written for. */
    private const PRICING_GRID_CELL = [
        ['tag' => 'table', 'classes' => ['wide-price-table'], 'attrs' => []],
        ['tag' => 'tbody', 'classes' => [], 'attrs' => []],
        ['tag' => 'tr', 'classes' => [], 'attrs' => []],
        ['tag' => 'td', 'classes' => [], 'attrs' => ['data-label' => 'Qty']],
    ];

    public function testFeeRowLabelCellWrapsInsteadOfOverflowingItsColumn(): void
    {
        self::assertSame('normal', $this->winningValue('white-space', self::FEE_LABEL_CELL));
        self::assertSame('normal', $this->winningValue('word-break', self::FEE_LABEL_CELL));
    }

    public function testNoAtRuleReimposesNowrapOnTheFeeRowLabelCell(): void
    {
        $offenders = [];

        foreach ($this->styleRules() as $rule) {
            if (!$rule['inAtRule'] || ($rule['declarations']['white-space']['value'] ?? null) !== 'nowrap') {
                continue;
            }

            if ($this->matchSpecificity($rule['selector'], self::FEE_LABEL_CELL) !== null) {
                $offenders[] = $rule['selector'];
            }
        }

        self::assertSame([], $offenders, 'At-rule nested selectors force the fee label cell back to nowrap.');
    }

    public function testBulkPricingGridStillGetsNowrap(): void
    {
        self::assertSame('nowrap', $this->winningValue('white-space', self::PRICING_GRID_CELL));
    }

    /**
     * Resolves the declared value of $property that wins for $path: `!important` first, then
     * specificity, then source order — the same order a browser applies.
     */
    private function winningValue(string $property, array $path): ?string
    {
        $winner = null;

        foreach ($this->styleRules() as $rule) {
            if ($rule['inAtRule'] || !isset($rule['declarations'][$property])) {
                continue;
            }

            $specificity = $this->matchSpecificity($rule['selector'], $path);
            if ($specificity === null) {
                continue;
            }

            $candidate = [
                'important' => $rule['declarations'][$property]['important'],
                'specificity' => $specificity,
                'order' => $rule['order'],
                'value' => $rule['declarations'][$property]['value'],
            ];

            if ($winner === null || $this->beats($candidate, $winner)) {
                $winner = $candidate;
            }
        }

        return $winner['value'] ?? null;
    }

    private function beats(array $candidate, array $winner): bool
    {
        if ($candidate['important'] !== $winner['important']) {
            return $candidate['important'];
        }

        if ($candidate['specificity'] !== $winner['specificity']) {
            return $candidate['specificity'] > $winner['specificity'];
        }

        return $candidate['order'] > $winner['order'];
    }

    /**
     * Highest specificity among the comma-separated selectors in $selectorList that match $path, as
     * [ids, classes, elements] for direct array comparison. Null when none of them match.
     */
    private function matchSpecificity(string $selectorList, array $path): ?array
    {
        $best = null;

        foreach ($this->splitTopLevel($selectorList, ',') as $selector) {
            $compounds = $this->splitCompounds($selector);
            if ($compounds === [] || !$this->matchesPath($compounds, $path)) {
                continue;
            }

            $specificity = [0, 0, 0];
            foreach ($compounds as $compound) {
                $part = $this->parseCompound($compound);
                foreach ($part['specificity'] as $i => $count) {
                    $specificity[$i] += $count;
                }
            }

            if ($best === null || $specificity > $best) {
                $best = $specificity;
            }
        }

        return $best;
    }

    /**
     * The rightmost compound must match the target element; the rest must match its ancestors in order
     * (as a subsequence). Child/sibling combinators are treated as descendants — every path here is a
     * complete ancestor chain, so that can only be more permissive, never miss a matching rule.
     */
    private function matchesPath(array $compounds, array $path): bool
    {
        $target = $path[count($path) - 1];
        if (!$this->matchesElement((string) array_pop($compounds), $target)) {
            return false;
        }

        $ancestors = array_slice($path, 0, -1);
        foreach (array_reverse($compounds) as $compound) {
            while (true) {
                $ancestor = array_pop($ancestors);
                if ($ancestor === null) {
                    return false;
                }
                if ($this->matchesElement((string) $compound, $ancestor)) {
                    break;
                }
            }
        }

        return true;
    }

    private function matchesElement(string $compound, array $element): bool
    {
        $part = $this->parseCompound($compound);

        if ($part['unsupported']) {
            return false;
        }

        if ($part['tag'] !== null && $part['tag'] !== $element['tag']) {
            return false;
        }

        foreach ($part['classes'] as $class) {
            if (!in_array($class, $element['classes'], true)) {
                return false;
            }
        }

        foreach ($part['attrs'] as $name => $value) {
            if (!array_key_exists($name, $element['attrs'])) {
                return false;
            }
            if ($value !== null && $element['attrs'][$name] !== $value) {
                return false;
            }
        }

        foreach ($part['nots'] as $inner) {
            if ($this->matchSpecificity($inner, [$element]) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Splits one compound selector into tag / classes / attributes / `:not()` arguments, plus its own
     * specificity contribution. `unsupported` flags a pseudo we can't evaluate without a live DOM
     * (`:hover`, `:nth-child()`, …), which makes the compound count as a non-match.
     */
    private function parseCompound(string $compound): array
    {
        static $cache = [];
        if (isset($cache[$compound])) {
            return $cache[$compound];
        }

        $part = [
            'tag' => null,
            'classes' => [],
            'attrs' => [],
            'nots' => [],
            'unsupported' => false,
            'specificity' => [0, 0, 0],
        ];

        $rest = $compound;
        if (preg_match('/^([a-zA-Z][\w-]*)/', $rest, $m) === 1) {
            $part['tag'] = strtolower($m[1]);
            $part['specificity'][2]++;
            $rest = substr($rest, strlen($m[0]));
        }

        while ($rest !== '') {
            if (preg_match('/^:not\(([^()]*)\)/', $rest, $m) === 1) {
                $part['nots'][] = $m[1];
                foreach ($this->matchSpecificityOfList($m[1]) as $i => $count) {
                    $part['specificity'][$i] += $count;
                }
            } elseif (preg_match('/^\.([\w-]+)/', $rest, $m) === 1) {
                $part['classes'][] = $m[1];
                $part['specificity'][1]++;
            } elseif (preg_match('/^#([\w-]+)/', $rest, $m) === 1) {
                $part['specificity'][0]++;
            } elseif (preg_match('/^\[([\w-]+)(?:=\s*"([^"]*)")?\]/', $rest, $m) === 1) {
                $part['attrs'][$m[1]] = $m[2] ?? null;
                $part['specificity'][1]++;
            } elseif (preg_match('/^::[\w-]+/', $rest, $m) === 1) {
                $part['unsupported'] = true;
                $part['specificity'][2]++;
            } elseif (preg_match('/^:[\w-]+(\([^()]*\))?/', $rest, $m) === 1) {
                $part['unsupported'] = true;
                $part['specificity'][1]++;
            } else {
                $part['unsupported'] = true;
                break;
            }

            $rest = substr($rest, strlen($m[0]));
        }

        return $cache[$compound] = $part;
    }

    /** Highest specificity among a `:not()` argument's comma-separated selectors, per the spec. */
    private function matchSpecificityOfList(string $selectorList): array
    {
        $best = [0, 0, 0];

        foreach ($this->splitTopLevel($selectorList, ',') as $selector) {
            $specificity = [0, 0, 0];
            foreach ($this->splitCompounds($selector) as $compound) {
                foreach ($this->parseCompound($compound)['specificity'] as $i => $count) {
                    $specificity[$i] += $count;
                }
            }

            if ($specificity > $best) {
                $best = $specificity;
            }
        }

        return $best;
    }

    /**
     * @return list<array{selector: string, declarations: array<string, array{value: string, important: bool}>, order: int, inAtRule: bool}>
     */
    private function styleRules(): array
    {
        static $rules = null;
        if ($rules !== null) {
            return $rules;
        }

        $css = file_get_contents(self::CSS_PATH);
        self::assertIsString($css, 'app.css is unreadable.');

        $rules = [];
        $order = 0;
        $this->collectRules((string) preg_replace('#/\*.*?\*/#s', '', $css), false, $rules, $order);

        return $rules;
    }

    private function collectRules(string $css, bool $inAtRule, array &$rules, int &$order): void
    {
        $length = strlen($css);
        $offset = 0;

        while ($offset < $length) {
            $open = strpos($css, '{', $offset);
            if ($open === false) {
                return;
            }

            $close = $this->matchingBrace($css, $open);
            if ($close === null) {
                return;
            }

            $prelude = trim(substr($css, $offset, $open - $offset));
            $body = substr($css, $open + 1, $close - $open - 1);

            if (str_starts_with($prelude, '@')) {
                // Only conditional group rules hold nested style rules; @font-face/@keyframes don't.
                if (preg_match('/^@(media|supports|container|layer|scope)\b/', $prelude) === 1) {
                    $this->collectRules($body, true, $rules, $order);
                }
            } elseif ($prelude !== '') {
                $rules[] = [
                    'selector' => $prelude,
                    'declarations' => $this->parseDeclarations($body),
                    'order' => $order++,
                    'inAtRule' => $inAtRule,
                ];
            }

            $offset = $close + 1;
        }
    }

    private function matchingBrace(string $css, int $open): ?int
    {
        $depth = 0;

        for ($i = $open, $length = strlen($css); $i < $length; $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}' && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /** @return array<string, array{value: string, important: bool}> */
    private function parseDeclarations(string $body): array
    {
        $declarations = [];

        foreach (explode(';', $body) as $declaration) {
            $colon = strpos($declaration, ':');
            if ($colon === false) {
                continue;
            }

            $property = strtolower(trim(substr($declaration, 0, $colon)));
            $value = trim(substr($declaration, $colon + 1));
            $important = stripos($value, '!important') !== false;

            if ($property === '') {
                continue;
            }

            $declarations[$property] = [
                'value' => trim((string) preg_replace('/!\s*important/i', '', $value)),
                'important' => $important,
            ];
        }

        return $declarations;
    }

    /**
     * Splits a single selector into its compound selectors on descendant/child/sibling combinators.
     * Depth-aware, because a whitespace inside `[data-label="Default price"]` or a `:not()` argument is
     * part of the compound, not a combinator — splitting on it naively drops the rule from the cascade.
     *
     * @return list<string>
     */
    private function splitCompounds(string $selector): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;

        for ($i = 0, $length = strlen($selector); $i < $length; $i++) {
            $char = $selector[$i];

            if ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            } elseif ($depth === 0 && (ctype_space($char) || $char === '>' || $char === '+' || $char === '~')) {
                $parts[] = $buffer;
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $parts[] = $buffer;

        return array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
    }

    /** @return list<string> */
    private function splitTopLevel(string $subject, string $separator): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;

        for ($i = 0, $length = strlen($subject); $i < $length; $i++) {
            $char = $subject[$i];

            if ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            } elseif ($char === $separator && $depth === 0) {
                $parts[] = $buffer;
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $parts[] = $buffer;

        return array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
    }
}
