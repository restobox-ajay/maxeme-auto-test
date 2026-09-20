<?php

declare(strict_types=1);

namespace App\Tests\Asset;

use PHPUnit\Framework\TestCase;

/**
 * Nothing in app.css hides the admin page title row's lead or eyebrow again (queue item 41).
 *
 * ## What this is guarding
 *
 * `.site-admin .panel-title-row .eyebrow, .lead` and the `.admin-titlebar` pair carried
 * `display: none !important` from the first commit in this repository. Thirty-one purchase screens
 * pass a lead and on seven of them it is FACTS — the vendor, the destination warehouse, the
 * document date, the units outstanding, and the STATUS. `purchase_order_detail.html.twig` passed
 * "vendor -> warehouse · status" and none of it reached the screen, so a cancelled purchase order
 * read "Cancelled" nowhere at all. The owner ruled: "dont hide status. status shoudl be in tables
 * and on the detail page, big and loud".
 *
 * Codeception cannot see this. The functional suite drives the Symfony kernel, not a browser, and
 * the markup was always in the response — `DocumentStatusIsLoudCest` asserts the badge and the
 * lead are rendered, and would have passed just as happily on the day the CSS was swallowing both.
 * The rule is only checkable as a stylesheet, which is what this file does and why it lives beside
 * `EstimateLineTableWrapCssTest`.
 *
 * ## Why it reads the declarations rather than grepping for a string
 *
 * The rule that caused this could come back written differently — a different selector order, a
 * `visibility: hidden`, a `font-size: 0`. So the stylesheet is parsed into rules, and every rule
 * that hides its subject is checked against the selectors that would reach these two elements,
 * wherever in the file it is and whatever else it also hides.
 *
 * Deliberately broad on the selector side: any selector that mentions `panel-title-row` or
 * `admin-titlebar` and targets `.lead` or `.eyebrow` counts, without resolving the full cascade.
 * A rule like that is never legitimate here, so a false positive would be a rule somebody should
 * have to argue for anyway — and the cost of the cheap reading is a failure message that names the
 * selector, which is the whole ask.
 */
final class AdminTitleRowIsNotHiddenCssTest extends TestCase
{
    private const CSS_PATH = __DIR__ . '/../../public/assets/css/app.css';

    /** Ways a rule can take an element off the screen without deleting it. */
    private const HIDING = [
        'display' => 'none',
        'visibility' => 'hidden',
        'font-size' => '0',
    ];

    public function testNothingHidesTheLeadOrEyebrowInAnAdminTitleRow(): void
    {
        $offenders = [];

        foreach ($this->styleRules() as $rule) {
            $hidden = [];

            foreach (self::HIDING as $property => $value) {
                if (($rule['declarations'][$property] ?? null) === $value) {
                    $hidden[] = $property . ': ' . $value;
                }
            }

            if ($hidden === []) {
                continue;
            }

            foreach ($this->selectors($rule['selector']) as $selector) {
                if ($this->reachesTheTitleRowsOwnText($selector)) {
                    $offenders[] = sprintf('%s { %s }', $selector, implode('; ', $hidden));
                }
            }
        }

        self::assertSame([], $offenders, implode("\n", [
            'app.css hides the admin page title row\'s own text again. Queue item 41: the lead is'
            . ' where 31 purchase screens write the vendor, the warehouse, the dates and the status,'
            . ' so hiding it takes a cancelled purchase order\'s "Cancelled" off the screen entirely.',
            'Offending rule(s):',
            ...$offenders,
        ]));
    }

    /**
     * The positive control for the test above.
     *
     * `assertSame([], $offenders)` passes just as happily when the parser reads no declarations at
     * all, or when the selector test matches nothing it should. So: the same parse has to still
     * find the hiding rules that ARE in this stylesheet and are meant to be, and the same selector
     * test has to still recognise the exact rule that was removed.
     */
    public function testTheScanStillFindsHidingRulesAndStillRecognisesTheOneThatWasRemoved(): void
    {
        $hiders = 0;

        foreach ($this->styleRules() as $rule) {
            if (($rule['declarations']['display'] ?? null) === 'none') {
                ++$hiders;
            }
        }

        self::assertGreaterThan(
            10,
            $hiders,
            'The declaration parse no longer finds the display:none rules this stylesheet is full'
            . ' of, so the test above is asserting against an empty list.',
        );

        self::assertTrue(
            $this->reachesTheTitleRowsOwnText('.site-admin .panel-title-row .lead'),
            'The selector test no longer recognises the rule queue item 41 removed.',
        );
        self::assertTrue($this->reachesTheTitleRowsOwnText('.site-admin .admin-titlebar .eyebrow'));

        // And it does not fire on rules about something else entirely, or every stylesheet change
        // would be a failure here.
        self::assertFalse($this->reachesTheTitleRowsOwnText('.site-admin .panel-title-row .button'));
        self::assertFalse($this->reachesTheTitleRowsOwnText('.site-customer .catalog-card .lead'));
    }

    /** The loud badge the ruling's second half asks for is defined, and takes its colour from the grids'. */
    public function testTheDocumentStatusBadgeIsStyledFromTheListScreensOwnStatusClasses(): void
    {
        $css = $this->css();

        self::assertStringContainsString(
            '.order-status.document-status',
            $css,
            'The document detail status badge has no styling of its own any more.',
        );

        // It is `.order-status` + `.order-status-<slug>` in the markup precisely so the colour is
        // decided once, in the table the grids read from. If that table goes, the badge is slate
        // everywhere and the two halves of the ruling stop agreeing.
        foreach (['.order-status-draft', '.order-status-cancelled', '.order-status-void'] as $modifier) {
            self::assertStringContainsString(
                $modifier,
                $css,
                'The status colour table the badge and every grid share is missing ' . $modifier . '.',
            );
        }
    }

    /**
     * Whether $selector targets the `.lead` or `.eyebrow` of an admin page title row.
     *
     * The subject is the RIGHTMOST compound — a rule ending in `.panel-title-row` styles the row
     * itself, and hiding an empty title row is a different decision from hiding its text.
     */
    private function reachesTheTitleRowsOwnText(string $selector): bool
    {
        $compounds = preg_split('/\s*[\s>+~]\s*/', trim($selector)) ?: [];
        $subject = (string) end($compounds);

        if (!str_contains($subject, '.lead') && !str_contains($subject, '.eyebrow')) {
            return false;
        }

        return str_contains($selector, 'panel-title-row') || str_contains($selector, 'admin-titlebar');
    }

    /** @return list<string> */
    private function selectors(string $selectorList): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $selectorList)),
            static fn (string $one): bool => $one !== '',
        ));
    }

    /**
     * Every style rule in the file, at-rule nested ones included, as selector plus declarations.
     *
     * Comments are stripped first — this file's are prose, and prose about `display: none` is not a
     * rule. At-rule bodies are walked rather than skipped: a `@media` block is exactly where a rule
     * like the one removed would come back without anybody noticing.
     *
     * @return list<array{selector: string, declarations: array<string, string>}>
     */
    private function styleRules(): array
    {
        static $rules = null;

        if ($rules === null) {
            $rules = [];
            $this->collect((string) preg_replace('#/\*.*?\*/#s', '', $this->css()), $rules);
        }

        return $rules;
    }

    /** @param list<array{selector: string, declarations: array<string, string>}> $rules */
    private function collect(string $css, array &$rules): void
    {
        $offset = 0;
        $length = strlen($css);

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
                if (preg_match('/^@(media|supports|container|layer|scope)\b/', $prelude) === 1) {
                    $this->collect($body, $rules);
                }
            } elseif ($prelude !== '') {
                $rules[] = ['selector' => $prelude, 'declarations' => $this->declarations($body)];
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

    /**
     * `!important` is dropped from the value on purpose: a rule hides its subject whether or not it
     * shouts, and the one this guards against shouted.
     *
     * @return array<string, string>
     */
    private function declarations(string $body): array
    {
        $declarations = [];

        foreach (explode(';', $body) as $declaration) {
            $colon = strpos($declaration, ':');

            if ($colon === false) {
                continue;
            }

            $property = strtolower(trim(substr($declaration, 0, $colon)));

            if ($property === '') {
                continue;
            }

            $value = trim((string) preg_replace('/!\s*important/i', '', substr($declaration, $colon + 1)));
            $declarations[$property] = strtolower($value);
        }

        return $declarations;
    }

    private function css(): string
    {
        $css = file_get_contents(self::CSS_PATH);
        self::assertIsString($css, 'app.css is unreadable.');

        return $css;
    }
}
