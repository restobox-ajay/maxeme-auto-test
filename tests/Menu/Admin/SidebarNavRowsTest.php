<?php

declare(strict_types=1);

namespace App\Tests\Menu\Admin;

use PHPUnit\Framework\TestCase;

/**
 * The diagnostic itself, because a diagnostic only ever runs when something else is already broken.
 *
 * `SidebarNavRows::explainDifference()` is what `AdminMenuDefaultSidebarCest` prints INSTEAD of a
 * 28 KB string diff. If it silently stopped finding rows — a changed class name, a new markup
 * shape — the Cest would still fail correctly on the markup but would say nothing useful, and the
 * failure would go back to being misdiagnosed. Nobody would notice, because the only way to see
 * this code run is to break the sidebar on purpose.
 *
 * So it is exercised here on purpose, with the difference known in advance.
 */
final class SidebarNavRowsTest extends TestCase
{
    private const NAV = '<nav id="primary-navigation" class="nav" aria-label="Primary navigation">'
        . '<a href="/admin" class="is-current"><span class="nav-icon"><svg></svg></span><span class="nav-label">Dashboard</span></a>'
        . '<div class="nav-group "><button class="nav-group-title" type="button" aria-expanded="false"><span class="nav-label">Technical Docs</span></button>'
        . '<div class="nav-sub-list">'
        . '<a class="nav-sub " href="/admin/technical-docs/view/QUEUE.md" target="_blank" rel="noopener"><span class="nav-label">QUEUE</span></a>'
        . '<a class="nav-sub " href="/admin/technical-docs/view/bundles/fee.md" target="_blank" rel="noopener"><span class="nav-label">bundles/fee</span></a>'
        . '</div></div>'
        . '<div class="nav-group "><button class="nav-group-title" type="button" aria-expanded="false"><span class="nav-label">Apps</span></button>'
        . '<div class="nav-sub-list"><span class="nav-sub-section">Configuration</span>'
        . '<a class="nav-sub " href="/admin/messenger"><span class="nav-label">Mail Queue</span></a>'
        . '</div></div></nav>';

    private const DOC_ROW = '<a class="nav-sub " href="/admin/technical-docs/view/DECISIONS-NEEDED.md" target="_blank" rel="noopener">'
        . '<span class="nav-label">DECISIONS-NEEDED</span></a>';

    public function testItReadsEveryKindOfRowOutOfTheMarkup(): void
    {
        self::assertSame(
            [
                'Dashboard -> /admin',
                'QUEUE -> /admin/technical-docs/view/QUEUE.md',
                'bundles/fee -> /admin/technical-docs/view/bundles/fee.md',
                'Mail Queue -> /admin/messenger',
                'Technical Docs -> [group heading, no link]',
                'Apps -> [group heading, no link]',
                'Configuration -> [sub-section heading, no link]',
            ],
            SidebarNavRows::parse(self::NAV),
        );
    }

    public function testItDerivesTheDocsPathsFromTheMarkupRatherThanAList(): void
    {
        self::assertSame(['QUEUE.md', 'bundles/fee.md'], SidebarNavRows::technicalDocsPaths(self::NAV));
    }

    public function testAnAddedRowIsNamedOnOneLine(): void
    {
        $withExtra = str_replace('</div></div><div class="nav-group "><button class="nav-group-title" type="button" aria-expanded="false"><span class="nav-label">Apps</span>', self::DOC_ROW . '</div></div><div class="nav-group "><button class="nav-group-title" type="button" aria-expanded="false"><span class="nav-label">Apps</span>', self::NAV);
        self::assertNotSame(self::NAV, $withExtra, 'the fixture-side edit this case depends on did not apply');

        $message = SidebarNavRows::explainDifference(self::NAV, $withExtra);

        self::assertStringStartsWith(
            'the rendered sidebar has 1 row the fixture does not:'
            . ' DECISIONS-NEEDED -> /admin/technical-docs/view/DECISIONS-NEEDED.md',
            $message,
        );
    }

    public function testARemovedRowIsNamedFromTheOtherSide(): void
    {
        $withoutMailQueue = str_replace(
            '<a class="nav-sub " href="/admin/messenger"><span class="nav-label">Mail Queue</span></a>',
            '',
            self::NAV,
        );

        self::assertStringContainsString(
            'the fixture has 1 row the rendered sidebar does not: Mail Queue -> /admin/messenger',
            SidebarNavRows::explainDifference(self::NAV, $withoutMailQueue),
        );
    }

    /**
     * The conditional half: the Technical Docs coupling is named when it is the cause, and NOT
     * when it isn't. A note appended to every failure is a note nobody reads, which would put this
     * straight back where it started.
     */
    public function testTheDocsCouplingIsNamedOnlyWhenTheDifferingRowsAreDocs(): void
    {
        $docsDifference = SidebarNavRows::explainDifference(self::NAV, self::NAV . self::DOC_ROW);
        $otherDifference = SidebarNavRows::explainDifference(
            self::NAV,
            self::NAV . '<a class="nav-sub " href="/admin/widgets"><span class="nav-label">Widgets</span></a>',
        );

        self::assertStringContainsString('RECURSIVE Finder over docs/', $docsDifference);
        self::assertStringContainsString('docs/DECISIONS-NEEDED.md', $docsDifference);

        // The positive control for the absence assertion below: this IS a reported difference,
        // it just is not a docs one.
        self::assertStringContainsString('Widgets -> /admin/widgets', $otherDifference);
        self::assertStringNotContainsString('Finder', $otherDifference);
        self::assertStringNotContainsString('docs/', $otherDifference);
    }

    /**
     * A row rendered twice against a fixture holding it once IS a difference. `array_diff` would
     * report none, and the message would then claim every row matches while the two navs disagree
     * — a small false green inside the diagnostic itself.
     */
    public function testADuplicatedRowIsCountedRatherThanCancelledOut(): void
    {
        $duplicated = str_replace(
            '<a class="nav-sub " href="/admin/messenger"><span class="nav-label">Mail Queue</span></a>',
            '<a class="nav-sub " href="/admin/messenger"><span class="nav-label">Mail Queue</span></a>'
            . '<a class="nav-sub " href="/admin/messenger"><span class="nav-label">Mail Queue</span></a>',
            self::NAV,
        );

        self::assertSame(
            'the rendered sidebar has 1 row the fixture does not: Mail Queue -> /admin/messenger',
            SidebarNavRows::explainDifference(self::NAV, $duplicated),
        );
    }

    /**
     * When the rows all match, the markup still differed — the caller only asks on failure. Saying
     * "no differences" there would be a false green inside the very message that exists to stop
     * one, so it says where the two strings part instead, bounded rather than 28 KB.
     */
    public function testAMarkupOnlyDifferenceIsReportedAsSuchWithABoundedExcerpt(): void
    {
        $withoutCurrentClass = str_replace('class="is-current"', 'class=""', self::NAV);
        self::assertNotSame(self::NAV, $withoutCurrentClass);

        $message = SidebarNavRows::explainDifference(self::NAV, $withoutCurrentClass);

        self::assertStringContainsString('Every sidebar row matches the fixture by label and href', $message);
        self::assertStringContainsString('First difference at offset ', $message);
        self::assertLessThan(
            1200,
            strlen($message),
            'the fallback is supposed to be a window onto the difference, not a reprint of both navs',
        );
    }
}
