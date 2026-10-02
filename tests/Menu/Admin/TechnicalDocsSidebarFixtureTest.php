<?php

declare(strict_types=1);

namespace App\Tests\Menu\Admin;

use App\Maxeme\Menu\MaxemeAdminMenuProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use TechnicalDocsBundle\Docs\DocsRepository;

/**
 * The millisecond version of the sidebar fixture's most common failure.
 *
 * `AdminMenuDefaultSidebarCest` already catches a stale fixture, but only from the Functional
 * suite: a kernel, a login, a rendered page. The cheap loop most people run first is plain
 * PHPUnit, and until this existed that loop was silent about the one change most likely to break
 * the fixture — adding or removing a markdown file under docs/.
 *
 * `DocsRepository::list()` is a RECURSIVE Finder over docs/ matching *.md, and
 * `TechnicalDocsNavExtension` turns every hit into a nav row. So the sidebar is a function of the
 * docs/ directory listing, which means a docs-only commit changes the admin menu. That is not
 * hypothetical — the same one-row difference was misdiagnosed twice in one evening, once on the
 * stated grounds that a docs-only commit could not possibly reach the menu.
 *
 * ## Why this is not the anti-pattern docs/QUEUE.md warns about
 *
 * `docs/QUEUE.md` names this very fixture as the hand-kept-list ANTI-pattern to learn from: it
 * works only because someone remembers to refresh it. The obvious "fix" — a second check listing
 * the documents that ought to be in the menu — would be a third copy of the same hand-kept list
 * and strictly worse, because it would have to be remembered too.
 *
 * So neither side of this comparison is written down here. One side is whatever
 * `DocsRepository::list()` returns when asked today; the other is whatever Technical Docs rows the
 * committed fixture actually contains, read back out of its markup by `SidebarNavRows`. This test
 * holds no opinion about which documents exist — only that the two answers agree.
 *
 * No HTTP, no kernel, no Codeception: two file reads and a set difference.
 */
final class TechnicalDocsSidebarFixtureTest extends TestCase
{
    private const REMEDY = <<<'TEXT'
    The admin sidebar's Technical Docs group is generated from the docs/ directory listing, so a
    docs-only commit changes it. Fix it in the commit that caused it, one of two ways:

      - refresh tests/Support/Fixtures/admin_default_sidebar_nav.html in the SAME commit as the
        docs change (precedent: 77c15a4b, 89ceba61) — the fixture is the rendered
        <nav id="primary-navigation"> fragment from GET /admin as a ROLE_TECH_SUPPORT admin, or
      - keep the file outside docs/ if it is not meant to be a technical document. That is what
        7ee3ef8b did with DECISIONS-NEEDED.md, and why it sits at the repository root.

    Do NOT add the file to a list of expected documents anywhere. There is deliberately no such
    list: see this test's class docblock and docs/QUEUE.md on hand-kept lists.
    TEXT;

    /**
     * The shop's sidebar (MaxemeAdminMenuProvider) hides every core catalog entry, Technical Docs
     * included, so the rendered sidebar is NOT a function of docs/ while that holds: the fixture
     * must carry no Technical Docs rows at all, and a docs-only commit cannot change it.
     */
    public function testTheShopSidebarHidesTechnicalDocs(): void
    {
        if (!self::shopSidebarHidesTechnicalDocs()) {
            self::markTestSkipped('MaxemeAdminMenuProvider no longer hides technical_docs; the fixture comparison below applies.');
        }

        self::assertSame(
            [],
            SidebarNavRows::technicalDocsPaths((string) file_get_contents(SidebarNavRows::FIXTURE)),
            'the shop sidebar hides Technical Docs, so the committed fixture must have no Technical Docs rows',
        );
    }

    public function testTheCommittedSidebarFixtureListsExactlyTheMarkdownFilesUnderDocs(): void
    {
        if (self::shopSidebarHidesTechnicalDocs()) {
            self::markTestSkipped('The shop sidebar hides Technical Docs (see testTheShopSidebarHidesTechnicalDocs).');
        }

        $onDisk = (new DocsRepository(dirname(__DIR__, 3)))->list();
        $inFixture = SidebarNavRows::technicalDocsPaths(
            (string) file_get_contents(SidebarNavRows::FIXTURE),
        );

        // Positive controls. Two empty sets are also "the same set", so without these a broken
        // Finder, a moved fixture or a changed href shape would make this test pass forever while
        // comparing nothing — the exact failure mode it exists to catch elsewhere.
        self::assertNotEmpty(
            $onDisk,
            'DocsRepository::list() found no markdown at all under docs/, so this test is comparing nothing',
        );
        self::assertNotEmpty(
            $inFixture,
            sprintf(
                'no Technical Docs rows were found in %s, so this test is comparing nothing —'
                . ' either the fixture moved or the nav href shape changed from %s',
                basename(SidebarNavRows::FIXTURE),
                SidebarNavRows::TECHNICAL_DOCS_HREF_PREFIX,
            ),
        );

        $problems = [];

        foreach (array_diff($onDisk, $inFixture) as $path) {
            $problems[] = sprintf('docs/%s exists but the fixture has no row for it', $path);
        }

        foreach (array_diff($inFixture, $onDisk) as $path) {
            $problems[] = sprintf('the fixture has a Technical Docs row for docs/%s, which is not on disk', $path);
        }

        self::assertSame([], $problems, implode("\n", $problems) . "\n\n" . self::REMEDY);
    }

    private static function shopSidebarHidesTechnicalDocs(): bool
    {
        $provider = new MaxemeAdminMenuProvider([], new class implements UrlGeneratorInterface {
            public function setContext(RequestContext $context): void {}
            public function getContext(): RequestContext { return new RequestContext(); }
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string { return '/'; }
        });

        return in_array('technical_docs', $provider->getHiddenKeys(), true);
    }
}
