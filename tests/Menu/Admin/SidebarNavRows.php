<?php

declare(strict_types=1);

namespace App\Tests\Menu\Admin;

/**
 * Reads the admin sidebar's rows out of rendered nav markup — or out of the committed snapshot of
 * it, which is the same shape of HTML.
 *
 * ## Why this exists
 *
 * `tests/Support/Fixtures/admin_default_sidebar_nav.html` is ~28 KB on ONE line, and
 * `AdminMenuDefaultSidebarCest` compares it against the rendered nav after deleting inter-tag
 * whitespace from BOTH sides — a structural comparison, not a byte one, whatever the repo used to
 * say about it; that Cest's docblock lists exactly what it does and does not guarantee. The rule
 * is right and this class does not touch it. What was wrong was the failure: PHPUnit's string
 * comparison prints expected and actual in full, so a single added nav row surfaces as 56 KB of
 * near-identical text with the difference somewhere inside it. A one-row difference was
 * misdiagnosed twice in one evening, once as "a docs-only commit cannot possibly reach the
 * menu" — it can, see below.
 *
 * So the test still asserts exactly what it always asserted, and asks this class what to SAY when
 * the two normalised strings differ.
 *
 * ## Why it is also the fixture's half of the TechnicalDocs guard
 *
 * `TechnicalDocsSidebarFixtureTest` compares `DocsRepository::list()` against the Technical Docs
 * rows in the committed fixture. Both sides have to be derived — a hand-written list of expected
 * documents would be the very anti-pattern `docs/QUEUE.md` names this fixture for. This class is
 * how the fixture side gets derived.
 */
final class SidebarNavRows
{
    /**
     * Every Technical Docs row's href starts with this, because TechnicalDocsNavExtension builds
     * them all from `admin_technical_docs_view` with the doc's path relative to docs/.
     */
    public const TECHNICAL_DOCS_HREF_PREFIX = '/admin/technical-docs/view/';

    public const FIXTURE = __DIR__ . '/../../Support/Fixtures/admin_default_sidebar_nav.html';

    /**
     * Every row the sidebar renders, as "label -> href".
     *
     * Links first, then group headings, then sub-section headings — grouped by kind rather than in
     * document order, because every caller compares these as a SET. Nothing here detects a row
     * being moved; the string comparison in AdminMenuDefaultSidebarCest does that, and a reorder
     * lands in the "rows all match, markup differs" branch of explainDifference().
     *
     * Buttons and sub-section headings have no href, so they carry a bracketed pseudo-href
     * instead: a whole group appearing or disappearing is exactly the kind of difference this is
     * meant to name, and dropping it because it is not an <a> would leave the caller with
     * "something changed, no idea what".
     *
     * @return list<string>
     */
    public static function parse(string $navHtml): array
    {
        $rows = [];

        // <a class="nav-sub " href="/admin/orders"><span class="nav-label">Orders</span></a>
        preg_match_all('#<a\b([^>]*)>(.*?)</a>#s', $navHtml, $links, PREG_SET_ORDER);
        foreach ($links as $link) {
            if (preg_match('#\shref="([^"]*)"#', $link[1], $href) !== 1) {
                continue;
            }

            $rows[] = self::label($link[1], $link[2]) . ' -> ' . $href[1];
        }

        // <button class="nav-group-title" ...><span class="nav-label">Technical Docs</span></button>
        preg_match_all('#<button\b([^>]*\bnav-group-title\b[^>]*)>(.*?)</button>#s', $navHtml, $groups, PREG_SET_ORDER);
        foreach ($groups as $group) {
            $rows[] = self::label($group[1], $group[2]) . ' -> [group heading, no link]';
        }

        // <span class="nav-sub-section">Configuration</span>
        preg_match_all('#<span class="nav-sub-section">(.*?)</span>#s', $navHtml, $sections, PREG_SET_ORDER);
        foreach ($sections as $section) {
            $rows[] = self::text($section[1]) . ' -> [sub-section heading, no link]';
        }

        return $rows;
    }

    /**
     * The docs/-relative paths the Technical Docs rows link to, e.g. "bundles/fee.md".
     *
     * This is the fixture-side half of the guard, and it is derived from the markup rather than
     * listed, so it cannot agree with a stale answer.
     *
     * @return list<string> sorted, so it lines up with DocsRepository::list()
     */
    public static function technicalDocsPaths(string $navHtml): array
    {
        $paths = [];

        foreach (self::parse($navHtml) as $row) {
            $href = self::hrefOf($row);

            if ($href !== null && str_starts_with($href, self::TECHNICAL_DOCS_HREF_PREFIX)) {
                $paths[] = rawurldecode(substr($href, strlen(self::TECHNICAL_DOCS_HREF_PREFIX)));
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * One line naming what differs, instead of two 28 KB strings.
     *
     * The symmetric difference of the two row lists, each side named. Both arguments are the
     * whitespace-normalised navs the caller compared, not the raw file. When nothing in the row
     * list differs the markup still did, so it says so and shows a bounded window around the
     * first differing character rather than pretending there is nothing to see — a silent "no
     * differences" on a failing assertion would be its own false green.
     */
    public static function explainDifference(string $expectedNav, string $actualNav): string
    {
        $expected = self::parse($expectedNav);
        $actual = self::parse($actualNav);

        // Counted, not array_diff'd. A row rendered TWICE against a fixture that has it once is a
        // real difference, and array_diff would call it no difference at all — leaving the message
        // saying every row matches while the two strings plainly disagree.
        $extra = self::surplus($actual, $expected);
        $missing = self::surplus($expected, $actual);

        if ($extra === [] && $missing === []) {
            return "Every sidebar row matches the fixture by label and href, so the difference is in\n"
                . "markup around them — an icon, a class such as is-current/is-open, an attribute, or\n"
                . "element order.\n\n" . self::firstDifferingBytes($expectedNav, $actualNav);
        }

        $lines = [];

        if ($extra !== []) {
            $lines[] = sprintf(
                'the rendered sidebar has %d row%s the fixture does not: %s',
                count($extra),
                count($extra) === 1 ? '' : 's',
                implode(', ', $extra),
            );
        }

        if ($missing !== []) {
            $lines[] = sprintf(
                'the fixture has %d row%s the rendered sidebar does not: %s',
                count($missing),
                count($missing) === 1 ? '' : 's',
                implode(', ', $missing),
            );
        }

        $coupling = self::technicalDocsCoupling(array_merge($extra, $missing));
        if ($coupling !== null) {
            $lines[] = '';
            $lines[] = $coupling;
        }

        return implode("\n", $lines);
    }

    /**
     * The rows $a has that $b does not, counting repeats.
     *
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return list<string>
     */
    private static function surplus(array $a, array $b): array
    {
        $remaining = array_count_values($b);
        $surplus = [];

        foreach ($a as $row) {
            if (($remaining[$row] ?? 0) > 0) {
                --$remaining[$row];
                continue;
            }

            $surplus[] = $row;
        }

        return $surplus;
    }

    /**
     * Named only when it is actually the cause, because a note attached to every failure is a note
     * nobody reads.
     *
     * @param list<string> $differingRows
     */
    private static function technicalDocsCoupling(array $differingRows): ?string
    {
        $docs = [];
        foreach ($differingRows as $row) {
            $href = self::hrefOf($row);
            if ($href !== null && str_starts_with($href, self::TECHNICAL_DOCS_HREF_PREFIX)) {
                $docs[] = 'docs/' . rawurldecode(substr($href, strlen(self::TECHNICAL_DOCS_HREF_PREFIX)));
            }
        }

        if ($docs === []) {
            return null;
        }

        return sprintf(
            "Those rows sit under Technical Docs, which is not a hand-written menu:\n"
            . "TechnicalDocsBundle\\Docs\\DocsRepository::list() is a RECURSIVE Finder over docs/ matching\n"
            . "*.md, and TechnicalDocsNavExtension turns every hit into a nav row. So adding, renaming or\n"
            . "deleting a markdown file anywhere under docs/ changes this sidebar — a docs-only commit\n"
            . "reaches the menu. Here that is: %s\n\n"
            . "The remedy is one of two things, not a change to this test:\n"
            . "  - refresh tests/Support/Fixtures/admin_default_sidebar_nav.html in the SAME commit as the\n"
            . "    docs change (precedent: 77c15a4b, 89ceba61), or\n"
            . "  - keep the file outside docs/ if it is not meant to be a technical document\n"
            . "    (precedent: 7ee3ef8b, which moved DECISIONS-NEEDED.md to the repository root for\n"
            . "    exactly this reason).",
            implode(', ', $docs),
        );
    }

    /** The href out of a "label -> href" row, or null for the bracketed pseudo-hrefs. */
    private static function hrefOf(string $row): ?string
    {
        $at = strrpos($row, ' -> ');
        if ($at === false) {
            return null;
        }

        $href = substr($row, $at + 4);

        return str_starts_with($href, '[') ? null : $href;
    }

    /**
     * What a human would call this row.
     *
     * Usually the <span class="nav-label">. The row affordances (the "+" quick-create links) carry
     * no label span at all — they are an icon plus aria-label/title — and an unnamed row in a
     * difference report is no better than the 28 KB diff this replaces, so those fall back to the
     * accessible name the markup already has.
     */
    private static function label(string $attributes, string $inner): string
    {
        if (preg_match('#<span class="nav-label">(.*?)</span>#s', $inner, $m) === 1) {
            return self::text($m[1]);
        }

        foreach (['aria-label', 'title'] as $attribute) {
            if (preg_match('#\s' . $attribute . '="([^"]*)"#', $attributes, $m) === 1) {
                return self::text($m[1]);
            }
        }

        $text = self::text($inner);

        return $text === '' ? '(unlabelled row)' : $text;
    }

    private static function text(string $html): string
    {
        return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html))));
    }

    /**
     * A bounded window around the first character that differs — enough to place it, not 28 KB.
     *
     * The offset is into the whitespace-NORMALISED nav both sides were compared as, so it will
     * not line up with the file on disk unless that file happens to have no inter-tag whitespace.
     * It is there to locate the difference in the text printed below it, not to seek in the
     * fixture.
     */
    private static function firstDifferingBytes(string $expected, string $actual): string
    {
        $at = strspn($expected ^ $actual, "\0");
        $from = max(0, $at - 90);

        return sprintf(
            "First difference at offset %d of %d in the normalised nav:\n  fixture:  ...%s...\n  rendered: ...%s...",
            $at,
            strlen($expected),
            substr($expected, $from, 220),
            substr($actual, $from, 220),
        );
    }
}
