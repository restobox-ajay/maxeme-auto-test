<?php

declare(strict_types=1);

namespace App\Tests\Asset;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards keyboard driving of the searchable-select widget (initSearchableSelect in
 * public/assets/js/app.js).
 *
 * The widget replaces a real <select> with a trigger button and a filtered <ul>, and for a long time
 * the only way to commit a row was a mouse click: arrowing through the list did nothing, which is not
 * how the native control it stands in for behaves. That matters most on the order and quote line
 * pickers, where #399 made typing the only way to reach a product in a 3,000+ item catalog.
 *
 * Neither suite can reach this. Codeception's Functional suite is PHPBrowser — it executes no
 * JavaScript at all — and there is no browser-driven suite in this repo, so a broken key binding
 * would go unnoticed until someone opened the form by hand. These are structural assertions over the
 * asset itself, in the same spirit as the sibling Asset tests: they prove the wiring is present, not
 * that the browser does the right thing with it.
 */
final class SearchableSelectKeyboardTest extends TestCase
{
    private const JS_PATH = __DIR__ . '/../../public/assets/js/app.js';
    private const CSS_PATH = __DIR__ . '/../../public/assets/css/app.css';

    private function widgetSource(): string
    {
        $js = (string) file_get_contents(self::JS_PATH);
        $start = strpos($js, 'function initSearchableSelect(');
        self::assertNotFalse($start, 'initSearchableSelect() has been renamed or removed.');

        // The widget ends where the next top-level construct begins; the $(function () {...}) that
        // instantiates it is the marker used elsewhere in this file's structure.
        $end = strpos($js, "$('select.js-searchable-select')", $start);
        self::assertNotFalse($end, 'The initSearchableSelect() bootstrap call has moved.');

        return substr($js, $start, $end - $start);
    }

    public function testTheSearchBoxBindsKeydownRatherThanLeavingTheListMouseOnly(): void
    {
        $widget = $this->widgetSource();

        self::assertMatchesRegularExpression(
            '/\$search\s*\.\s*on\s*\(\s*[\'"]keydown[\'"]/',
            $widget,
            'The search input must bind keydown — without it the result list is mouse-only.'
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function keyProvider(): iterable
    {
        yield 'move down the list' => ['ArrowDown'];
        yield 'move up the list' => ['ArrowUp'];
        yield 'commit the highlighted row' => ['Enter'];
        yield 'dismiss the panel' => ['Escape'];
    }

    #[DataProvider('keyProvider')]
    public function testEveryNavigationKeyIsHandled(string $key): void
    {
        self::assertStringContainsString(
            "'" . $key . "'",
            $this->widgetSource(),
            sprintf('The widget no longer handles %s.', $key)
        );
    }

    /**
     * Enter inside these pickers must be swallowed. They sit inside the order and quote forms, so an
     * unhandled Enter submits the whole document instead of picking the highlighted product — saving
     * a half-filled order is a considerably worse outcome than the keystroke doing nothing.
     */
    public function testEnterIsPreventedSoItCannotSubmitTheSurroundingForm(): void
    {
        $widget = $this->widgetSource();
        $enterAt = strpos($widget, "e.key === 'Enter'");
        self::assertNotFalse($enterAt, 'The Enter branch has been removed.');

        // Generous window: the branch carries a comment explaining why Enter is swallowed, and a
        // tight slice would cut the call itself off the end.
        $branch = substr($widget, $enterAt, 600);
        self::assertStringContainsString(
            'preventDefault',
            $branch,
            'The Enter branch must call preventDefault() or it will submit the order/quote form.'
        );
    }

    /**
     * Moving the highlight must not write to the <select>. Only an explicit commit may change the
     * posted product id, otherwise merely arrowing past a row would reprice the line.
     */
    public function testArrowKeysMoveTheHighlightWithoutCommittingAValue(): void
    {
        $widget = $this->widgetSource();

        $arrowAt = strpos($widget, "e.key === 'ArrowDown' || e.key === 'ArrowUp'");
        self::assertNotFalse($arrowAt, 'The arrow-key branch has been removed or reshaped.');

        $branch = substr($widget, $arrowAt, 400);
        self::assertStringNotContainsString(
            '$select.val(',
            $branch,
            'Arrowing must only move the highlight; committing is Enter\'s job.'
        );
    }

    /**
     * The panel reports on itself while a remote search is in flight. It used to leave "type at least
     * 2 characters to search" on screen through the debounce and the round trip — answering a
     * question the admin had already answered by typing the second character.
     */
    public function testAPendingSearchSaysSoInsteadOfRepeatingTheMinimumLengthHint(): void
    {
        $widget = $this->widgetSource();

        self::assertMatchesRegularExpression(
            '/showStatus\(\s*[\'"]Searching/u',
            $widget,
            'Committing to a search must show a pending state, not the minimum-length hint.'
        );

        // One status node for every state, so two of them can never be on screen together.
        self::assertSame(
            1,
            preg_match_all('/\$status\s*=\s*\$\(/', $widget),
            'The status line must be a single node reused across states.'
        );
    }

    /**
     * "Show more" must not move the admin's place in the list.
     *
     * Two things conspired to move it. rebuildList() throws the <ul> away and filter() re-picks a
     * default, so the highlight has to be restored by VALUE — its index means nothing once a page has
     * been appended above it. And the appended rows push "Show more" down, sliding a product row under
     * a pointer that never moved, which the browser reports as the pointer entering that row; the
     * hover handler then dropped the highlight wherever the mouse happened to be resting.
     */
    public function testLoadingMoreKeepsTheHighlightWhereTheAdminLeftIt(): void
    {
        $widget = $this->widgetSource();

        self::assertMatchesRegularExpression(
            '/keepActive\s*=\s*append\s*\?\s*activeValue\(\)/',
            $widget,
            'An append must capture the highlighted row before the list is rebuilt.'
        );
        self::assertStringContainsString(
            'setActiveByValue(keepActive)',
            $widget,
            'An append must restore the highlight it captured.'
        );
        // By value, not by index — the whole point, since appending shifts every index.
        self::assertMatchesRegularExpression(
            '/function setActiveByValue\(value\)[\s\S]{0,400}data-value/',
            $widget,
            'setActiveByValue() must match on the option value.'
        );
    }

    /**
     * A failed request must not be indistinguishable from a slow one that is still coming.
     */
    public function testAFailedSearchClearsThePendingState(): void
    {
        self::assertMatchesRegularExpression(
            '/\.fail\(\s*function/',
            $this->widgetSource(),
            'The search request needs a failure path or the panel hangs on its pending state.'
        );
    }

    /**
     * A highlight nothing renders is not a highlight. The keyboard cursor has to be visible, and
     * distinctly so, or arrowing gives the admin no feedback about what Enter would pick.
     */
    public function testTheKeyboardCursorHasVisibleStyling(): void
    {
        $css = (string) file_get_contents(self::CSS_PATH);

        self::assertMatchesRegularExpression(
            '/\.ss-item\.is-active\s*\{[^}]*background\s*:/',
            $css,
            '.ss-item.is-active must set a background so the keyboard cursor is visible.'
        );
    }
}
