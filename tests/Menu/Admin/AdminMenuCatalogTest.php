<?php

declare(strict_types=1);

namespace App\Tests\Menu\Admin;

use App\Menu\Admin\AdminMenuCatalog;
use PHPUnit\Framework\TestCase;

/**
 * The drift guard between AdminMenuCatalog and both the default tree it builds and the sidebar
 * template that renders it.
 *
 * Before #439 the nav was hand-typed Twig with one `admin_menu_enabled('key')` call per entry, so
 * the guard diffed ITEMS against those literal calls. The sidebar is generated from
 * AdminMenuCatalog::defaultTree() now, so ITEMS and the tree can never name different keys by
 * construction (defaultTree() is built by iterating ITEMS) — what can still drift is the
 * per-key route/icon/group data defaultTree() attaches, and whether the template still calls the
 * tree function it's supposed to instead of resurrecting the old per-key guard.
 */
final class AdminMenuCatalogTest extends TestCase
{
    private const LAYOUT = __DIR__ . '/../../../templates/admin/_main/layout.html.twig';

    /**
     * The eight catalog keys that render purely as a `<div class="nav-group">` container with a
     * tree-driven children list, never a link. "technical_docs" is deliberately not among them:
     * it also renders as a group, but a special-cased one whose children come from
     * technical_docs_items() rather than from the tree, and its own route is used for that
     * group's is-open highlighting the same way any other node's is (see
     * templates/admin/_main/layout.html.twig).
     */
    private const GROUP_KEYS = ['products', 'sales', 'users', 'settings', 'apps', 'system'];

    public function testDefaultTreeCoversExactlyTheCatalogsKeysInCatalogOrder(): void
    {
        $treeKeys = array_map(static fn ($node) => $node->key, AdminMenuCatalog::defaultTree());

        self::assertSame(AdminMenuCatalog::keys(), $treeKeys);
    }

    public function testEveryNonGroupKeyHasARoute(): void
    {
        foreach (AdminMenuCatalog::defaultTree() as $node) {
            if (in_array($node->key, self::GROUP_KEYS, true)) {
                self::assertNull($node->route, $node->key . ' is a group container and must not have its own route');
                continue;
            }

            self::assertNotNull($node->route, $node->key . ' is not a group and must resolve to a real route');
        }
    }

    public function testEveryGroupKeyHasNoParentAndEveryChildsParentIsItsCatalogGroup(): void
    {
        foreach (AdminMenuCatalog::defaultTree() as $node) {
            self::assertSame(AdminMenuCatalog::groupOf($node->key), $node->parent);
        }
    }

    /**
     * The refactor's whole point: the template must be driven by admin_menu_tree(), not by
     * resurrecting a per-key admin_menu_enabled() guard that would bypass
     * App\Menu\Admin\AdminMenuTreeBuilder's hide/reorder/reparent/custom-item merge entirely.
     */
    public function testTheLayoutIsDrivenByAdminMenuTreeNotThePerKeyGuardItReplaced(): void
    {
        $layout = (string) file_get_contents(self::LAYOUT);

        self::assertStringContainsString('admin_menu_tree()', $layout);
        self::assertStringNotContainsString('admin_menu_enabled(', $layout);
    }

    /**
     * #538, then the later Sales consolidation: the top-level entries in the exact order the
     * sidebar renders them out of the box. Pinned in full, not just around the group the ticket
     * moved, so that a later edit which quietly shuffles an unrelated group has to say so here
     * first.
     */
    public function testTheDefaultTopLevelOrderIsTheOneTheSidebarShips(): void
    {
        $topLevel = [];
        foreach (AdminMenuCatalog::defaultTree() as $node) {
            if ($node->parent === null) {
                $topLevel[] = $node->key;
            }
        }

        self::assertSame([
            'dashboard',
            'products',
            'sales',
            'users',
            'settings',
            'apps',
            'system',
            'technical_docs',
            'help',
        ], $topLevel);
    }

    /**
     * Moving a group in ITEMS must carry its children with it: every sub-entry sits directly
     * after its own group (or after an earlier sibling of the same group), never separated from
     * it by an unrelated entry — the property that makes the catalog's flat list readable as a
     * tree, and the one a careless reorder breaks first.
     */
    public function testEveryChildImmediatelyFollowsItsOwnGroup(): void
    {
        $keys = AdminMenuCatalog::keys();

        foreach ($keys as $index => $key) {
            $group = AdminMenuCatalog::groupOf($key);
            if ($group === null) {
                continue;
            }

            $previous = $keys[$index - 1] ?? null;
            self::assertTrue(
                $previous === $group || ($previous !== null && AdminMenuCatalog::groupOf($previous) === $group),
                $key . ' must follow its own group, with no unrelated entry wedged between them',
            );
        }
    }
}
