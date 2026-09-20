<?php

declare(strict_types=1);

namespace App\Tests\Menu\Admin;

use App\Contract\Menu\AdminMenuOverrideProviderInterface;
use App\Menu\Admin\AdminMenuCatalog;
use App\Menu\Admin\AdminMenuIconSet;
use App\Menu\Admin\AdminMenuNode;
use App\Menu\Admin\AdminMenuTreeBuilder;
use App\Repository\BundleStatusRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Unit + adversarial coverage for the tree-building/merge logic behind admin_menu_tree() (#439).
 *
 * "Adversarial" here means: the data driving a merge can be edited directly in a bundle's own
 * storage, independently of the code that reads it, so it can drift out of sync — a parent key
 * can be deleted from the catalog after being saved, two providers (or one, reparenting the same
 * pair) can point two groups at each other, a stored order value can be corrupted. Every case
 * below asserts the builder degrades gracefully (falls back to a sane default) rather than
 * throwing or corrupting the rest of the tree.
 */
final class AdminMenuTreeBuilderTest extends TestCase
{
    /**
     * The catalog keys ROW_AFFORDANCES flags — each renders ONLY as a `+` on the row it names now,
     * not as its own tree node, so flattenKeys() (which walks node+children, never affordances)
     * never includes them while their target resolves. Centralized here so every comparison
     * against "the whole default catalog, rendered" states the same exclusion once rather than
     * repeating the literal list, and so flagging a new key is a one-line change to this list
     * rather than a hunt through every test that asserts against the full catalog.
     *
     * @var list<string>
     */
    private const ATTACHED_BY_DEFAULT = [
        'products.add', 'sales.customers_add', 'sales.quotes_create', 'sales.invoices_create',
        'sales.credit_notes_create', 'sales.orders_create',
    ];

    /** AdminMenuCatalog::keys() with the default-attached keys removed, in catalog order. */
    private function defaultFlattenedKeys(): array
    {
        return array_values(array_diff(AdminMenuCatalog::keys(), self::ATTACHED_BY_DEFAULT));
    }

    private function bundleStatusRepo(bool $active = true): BundleStatusRepository
    {
        $repo = $this->createStub(BundleStatusRepository::class);
        $repo->method('isActive')->willReturn($active);

        return $repo;
    }

    /**
     * @param list<string> $hiddenKeys
     * @param array<string, int> $orderOverrides
     * @param array<string, ?string> $parentOverrides
     * @param list<array{key: string, label: string, url: string, parent: ?string, order: int}> $customItems
     */
    private function provider(
        array $hiddenKeys = [],
        array $orderOverrides = [],
        array $parentOverrides = [],
        array $customItems = [],
        string $source = 'AdminMenuBundle',
    ): AdminMenuOverrideProviderInterface {
        $provider = $this->createStub(AdminMenuOverrideProviderInterface::class);
        $provider->method('getSource')->willReturn($source);
        $provider->method('getHiddenKeys')->willReturn($hiddenKeys);
        $provider->method('getOrderOverrides')->willReturn($orderOverrides);
        $provider->method('getParentOverrides')->willReturn($parentOverrides);
        $provider->method('getCustomItems')->willReturn($customItems);

        return $provider;
    }

    /** @param list<array{node: AdminMenuNode, children: list<AdminMenuNode>, affordances: array<string, list<AdminMenuNode>>}> $tree */
    private function findTopLevel(array $tree, string $key): ?AdminMenuNode
    {
        foreach ($tree as $entry) {
            if ($entry['node']->key === $key) {
                return $entry['node'];
            }
        }

        return null;
    }

    /** @param list<array{node: AdminMenuNode, children: list<AdminMenuNode>, affordances: array<string, list<AdminMenuNode>>}> $tree */
    private function findChildren(array $tree, string $parentKey): ?array
    {
        foreach ($tree as $entry) {
            if ($entry['node']->key === $parentKey) {
                return $entry['children'];
            }
        }

        return null;
    }

    // --- Default tree, no providers ------------------------------------------------------------

    public function testWithNoProvidersTheTreeIsExactlyTheDefaultCatalogOrder(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $tree = $builder->build([]);

        self::assertSame($this->defaultFlattenedKeys(), AdminMenuTreeBuilder::flattenKeys($tree));
    }

    /**
     * The coordinator's explicit fresh-install check: a provider that answers with entirely empty
     * overrides (the state of a freshly created AdminMenuBundle provider before any admin ever
     * touches the builder UI — zero AdminMenuItemStatus/AdminCustomMenuItem rows) must produce a
     * tree identical, key-for-key and in the same order, to the pre-#439 hardcoded catalog order.
     */
    public function testAFreshlyInstalledProviderWithNoStoredOverridesChangesNothing(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $tree = $builder->build([$this->provider()]);

        self::assertSame($this->defaultFlattenedKeys(), AdminMenuTreeBuilder::flattenKeys($tree));
    }

    public function testTopLevelNodesNestUnderTheCatalogsOwnParentsByDefault(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $tree = $builder->build([]);

        $children = $this->findChildren($tree, 'products');
        self::assertNotNull($children);
        $childKeys = array_map(static fn (AdminMenuNode $n): string => $n->key, $children);
        // No 'products.add': it renders only as the `+` on 'products.details' by default (see
        // ATTACHED_BY_DEFAULT above), not as a child row of its own.
        self::assertSame(
            ['products.details', 'products.pricing', 'products.inventory', 'products.backorders', 'products.import', 'products.pricing_groups', 'products.company_pricing', 'products.categories', 'products.tracking_policies', 'products.units_of_measure'],
            $childKeys,
        );
    }

    // --- Single active provider -----------------------------------------------------------------

    public function testAnActiveProvidersHiddenKeysAreExcludedFromTheTree(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        // Not 'sales': hiding Sales would hide Quotes AND every sibling document consolidated
        // under it, leaving nothing to contrast against. 'products' still nests a real child
        // (products.details) the way the old standalone Quotes group used to.
        $tree = $builder->build([$this->provider(hiddenKeys: ['products', 'help'])]);

        $keys = AdminMenuTreeBuilder::flattenKeys($tree);
        self::assertNotContains('products', $keys);
        self::assertNotContains('products.details', $keys, 'hiding a group hides its children too');
        self::assertNotContains('products.pricing', $keys);
        self::assertNotContains('help', $keys);
        self::assertContains('dashboard', $keys, 'an unrelated key must be untouched');
    }

    public function testAnActiveProvidersOrderOverrideMovesAnEntryAmongItsSiblings(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        // Move Units of Measure (last by default among rendered children — Product Add renders
        // only as an affordance, not a sibling, per ATTACHED_BY_DEFAULT) to the very front.
        $tree = $builder->build([$this->provider(orderOverrides: ['products.units_of_measure' => -100])]);

        $children = $this->findChildren($tree, 'products');
        self::assertSame('products.units_of_measure', $children[0]->key);
    }

    /**
     * A bundle's own top-level group (WarehouseOpsBundle's "Warehouse", ProcurementBundle's
     * "Vendors", etc.) used to have no row in $nodes at the point overrides were collected, so a
     * stored order override naming it was silently dropped by the isset() guard — the DB-backed
     * AdminMenuOverrideProvider could move any CORE key but could never touch a bundle-contributed
     * one. Two providers here: one stands in for the bundle (declares the group, returns no
     * overrides of its own, exactly like every real bundle provider does today), the other for
     * AdminMenuBundle's stored-override provider (declares the override, no items of its own).
     */
    public function testAnActiveProvidersOrderOverrideCanMoveABundleContributedGroupAmongTopLevelEntries(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $tree = $builder->build([
            $this->provider(source: 'SomeWarehouseBundle', customItems: [
                ['key' => 'warehouse', 'label' => 'Warehouse', 'url' => '', 'parent' => null, 'order' => 999, 'group' => true],
            ]),
            $this->provider(orderOverrides: ['warehouse' => -100]),
        ]);

        $topLevelKeys = array_map(static fn (array $e): string => $e['node']->key, $tree);
        self::assertSame('warehouse', $topLevelKeys[0]);
    }

    /** The same gap, for hidden and parent overrides: both used to be silently ignored for a bundle-contributed key. */
    public function testAnActiveProvidersHiddenAndParentOverridesAlsoReachABundleContributedGroup(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $hiddenTree = $builder->build([
            $this->provider(source: 'SomeWarehouseBundle', customItems: [
                ['key' => 'warehouse', 'label' => 'Warehouse', 'url' => '', 'parent' => null, 'order' => 999, 'group' => true],
            ]),
            $this->provider(hiddenKeys: ['warehouse']),
        ]);
        self::assertNotContains('warehouse', AdminMenuTreeBuilder::flattenKeys($hiddenTree));

        $reparentedTree = $builder->build([
            $this->provider(source: 'SomeWarehouseBundle', customItems: [
                ['key' => 'warehouse', 'label' => 'Warehouse', 'url' => '', 'parent' => null, 'order' => 999, 'group' => true],
                ['key' => 'warehouse.scan', 'label' => 'Scan', 'url' => '', 'parent' => 'warehouse', 'order' => 1, 'route' => 'admin_dashboard'],
            ]),
            $this->provider(parentOverrides: ['warehouse.scan' => 'apps']),
        ]);
        $appsChildren = array_map(static fn (AdminMenuNode $n): string => $n->key, $this->findChildren($reparentedTree, 'apps'));
        self::assertContains('warehouse.scan', $appsChildren);
    }

    public function testAnActiveProvidersParentOverrideMovesAnEntryToAnotherGroup(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $tree = $builder->build([$this->provider(parentOverrides: ['settings.sales_tax' => 'apps'])]);

        $settingsChildren = array_map(static fn (AdminMenuNode $n): string => $n->key, $this->findChildren($tree, 'settings'));
        $appsChildren = array_map(static fn (AdminMenuNode $n): string => $n->key, $this->findChildren($tree, 'apps'));

        self::assertNotContains('settings.sales_tax', $settingsChildren);
        self::assertContains('settings.sales_tax', $appsChildren);
    }

    public function testAnActiveProvidersParentOverrideCanMoveAnEntryToTheTopLevel(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        // Reparent something nested to the top level. Not 'sales.orders_create': its target still
        // resolves regardless of `parent`, so it always renders as the `+` on 'sales.orders' rather
        // than as a row anywhere — reparenting it would have nothing observable to assert on.
        $tree = $builder->build([$this->provider(parentOverrides: ['sales.sales_returns' => null])]);

        $topLevelKeys = array_map(static fn (array $e): string => $e['node']->key, $tree);
        self::assertContains('sales.sales_returns', $topLevelKeys);

        $salesChildren = array_map(static fn (AdminMenuNode $n): string => $n->key, $this->findChildren($tree, 'sales'));
        self::assertNotContains('sales.sales_returns', $salesChildren);
    }

    public function testCombinedHideReorderAndReparentAllApplyTogether(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $tree = $builder->build([$this->provider(
            hiddenKeys: ['sales.carts'],
            orderOverrides: ['help' => -100],
            parentOverrides: ['users.staff' => 'settings'],
        )]);

        $keys = AdminMenuTreeBuilder::flattenKeys($tree);
        self::assertNotContains('sales.carts', $keys);
        self::assertSame('help', $tree[0]['node']->key);
        self::assertContains('users.staff', array_map(static fn (AdminMenuNode $n): string => $n->key, $this->findChildren($tree, 'settings')));
    }

    // --- Provider present but bundle inactive ---------------------------------------------------

    public function testAProviderWhoseBundleIsInactiveIsIgnoredEntirely(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(false));

        $tree = $builder->build([$this->provider(
            hiddenKeys: AdminMenuCatalog::keys(),
            customItems: [['key' => 'custom.x', 'label' => 'X', 'url' => '/x', 'parent' => null, 'order' => 0]],
        )]);

        self::assertSame($this->defaultFlattenedKeys(), AdminMenuTreeBuilder::flattenKeys($tree));
    }

    public function testOnlyProvidersWhoseOwnBundleIsActiveContributeWhenProvidersAreMixed(): void
    {
        // A single BundleStatusRepository double can't answer differently per source in this
        // suite's stub style, so this exercises the same active/inactive split across two builds
        // instead of one build with two providers — the per-provider isActive() call inside
        // AdminMenuTreeBuilder::build() is exactly the same either way.
        $activeBuilder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));
        $inactiveBuilder = new AdminMenuTreeBuilder($this->bundleStatusRepo(false));

        $provider = $this->provider(hiddenKeys: ['help']);

        self::assertNotContains('help', AdminMenuTreeBuilder::flattenKeys($activeBuilder->build([$provider])));
        self::assertContains('help', AdminMenuTreeBuilder::flattenKeys($inactiveBuilder->build([$provider])));
    }

    // --- Custom item injection -------------------------------------------------------------------

    public function testACustomItemIsInjectedAtTheTopLevelWhenNoParentIsGiven(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $tree = $builder->build([$this->provider(customItems: [
            ['key' => 'custom.warehouse_map', 'label' => 'Warehouse Map', 'url' => '/admin/warehouse-map', 'parent' => null, 'order' => 5],
        ])]);

        $node = $this->findTopLevel($tree, 'custom.warehouse_map');
        self::assertNotNull($node);
        self::assertTrue($node->custom);
        self::assertSame('Warehouse Map', $node->label);
        self::assertSame('/admin/warehouse-map', $node->url);
    }

    public function testACustomItemIsInjectedUnderAnExistingCoreGroup(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $tree = $builder->build([$this->provider(customItems: [
            ['key' => 'custom.extra_report', 'label' => 'Extra Report', 'url' => '/admin/extra-report', 'parent' => 'system', 'order' => 5],
        ])]);

        $systemChildren = array_map(static fn (AdminMenuNode $n): string => $n->key, $this->findChildren($tree, 'system'));
        self::assertContains('custom.extra_report', $systemChildren);
    }

    public function testMultipleCustomItemsCanBeInjectedAtDifferentParentsSimultaneously(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $tree = $builder->build([$this->provider(customItems: [
            ['key' => 'custom.a', 'label' => 'A', 'url' => '/a', 'parent' => null, 'order' => 0],
            ['key' => 'custom.b', 'label' => 'B', 'url' => '/b', 'parent' => 'apps', 'order' => 0],
            ['key' => 'custom.c', 'label' => 'C', 'url' => '/c', 'parent' => 'system', 'order' => 0],
        ])]);

        self::assertNotNull($this->findTopLevel($tree, 'custom.a'));
        self::assertContains('custom.b', array_map(static fn (AdminMenuNode $n): string => $n->key, $this->findChildren($tree, 'apps')));
        self::assertContains('custom.c', array_map(static fn (AdminMenuNode $n): string => $n->key, $this->findChildren($tree, 'system')));
    }

    // --- defaultNodes(): what AdminMenuConfigController lists as draggable rows -----------------

    /** Core's own keys are all there, keyed by their own key, with their own default order. */
    public function testDefaultNodesIncludesEveryCoreKey(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $nodes = $builder->defaultNodes([]);

        self::assertArrayHasKey('dashboard', $nodes);
        self::assertArrayHasKey('sales', $nodes);
        self::assertArrayHasKey('sales.orders', $nodes);
        self::assertSame(10, $nodes['dashboard']->order);
    }

    /**
     * The actual fix (#menu-order): a bundle's own top-level group and its screens are now listed
     * too, with the bundle's own default order still on them — before this, only core's own keys
     * were ever handed to the config screen, so a bundle-contributed group could not be reordered
     * or hidden through the UI at all, no matter what an admin dragged.
     */
    public function testDefaultNodesIncludesAnActiveBundlesStructuralItems(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $nodes = $builder->defaultNodes([$this->provider(source: 'SomeWarehouseBundle', customItems: [
            ['key' => 'warehouse', 'label' => 'Warehouse', 'url' => '', 'parent' => null, 'order' => 250, 'group' => true],
            ['key' => 'warehouse.scan', 'label' => 'Scan', 'url' => '', 'parent' => 'warehouse', 'order' => -800, 'route' => 'admin_dashboard'],
        ])]);

        self::assertArrayHasKey('warehouse', $nodes);
        self::assertSame(250, $nodes['warehouse']->order);
        self::assertArrayHasKey('warehouse.scan', $nodes);
        self::assertFalse($nodes['warehouse']->custom);
    }

    /**
     * An admin-created custom link (a plain label+url, no route, not a group) is excluded:
     * AdminMenuConfigController::index() already lists those from AdminCustomMenuItemRepository
     * with full CRUD, so listing them again here would duplicate the row.
     */
    public function testDefaultNodesExcludesAdminCreatedCustomLinks(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $nodes = $builder->defaultNodes([$this->provider(customItems: [
            ['key' => 'custom.warehouse_map', 'label' => 'Warehouse Map', 'url' => '/admin/warehouse-map', 'parent' => null, 'order' => 5],
        ])]);

        self::assertArrayNotHasKey('custom.warehouse_map', $nodes);
    }

    /** A provider whose own bundle is inactive contributes nothing to the list either. */
    public function testDefaultNodesExcludesAnInactiveProvidersItems(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(false));

        $nodes = $builder->defaultNodes([$this->provider(source: 'SomeWarehouseBundle', customItems: [
            ['key' => 'warehouse', 'label' => 'Warehouse', 'url' => '', 'parent' => null, 'order' => 250, 'group' => true],
        ])]);

        self::assertArrayNotHasKey('warehouse', $nodes);
    }

    // --- Adversarial: cyclic parent references --------------------------------------------------

    public function testATwoGroupParentCycleIsBrokenByRevertingBothToTheirDefaultParent(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $tree = $builder->build([$this->provider(parentOverrides: [
            'products' => 'apps',
            'apps' => 'products',
        ])]);

        // Both groups stay top-level (their real default parent is null) rather than the tree
        // builder looping forever or one of them silently disappearing.
        $topLevelKeys = array_map(static fn (array $e): string => $e['node']->key, $tree);
        self::assertContains('products', $topLevelKeys);
        self::assertContains('apps', $topLevelKeys);
    }

    public function testALongerParentCycleAcrossThreeGroupsIsBroken(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $tree = $builder->build([$this->provider(parentOverrides: [
            'products' => 'sales',
            'sales' => 'users',
            'users' => 'products',
        ])]);

        $topLevelKeys = array_map(static fn (array $e): string => $e['node']->key, $tree);
        self::assertContains('products', $topLevelKeys);
        self::assertContains('sales', $topLevelKeys);
        self::assertContains('users', $topLevelKeys);
    }

    // --- Adversarial: reparenting under a hidden or nonexistent parent --------------------------

    public function testReparentingUnderAHiddenGroupHidesTheReparentedEntryToo(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        // Not 'sales.orders_create': its attachTo target ('sales.orders') is untouched by this
        // override, so it renders as the `+` there regardless — nothing here would exercise the
        // reparent.
        $tree = $builder->build([$this->provider(
            hiddenKeys: ['settings'],
            parentOverrides: ['sales.sales_returns' => 'settings'],
        )]);

        self::assertNotContains('sales.sales_returns', AdminMenuTreeBuilder::flattenKeys($tree));
    }

    public function testReparentingUnderANonexistentKeyIsIgnored(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $tree = $builder->build([$this->provider(parentOverrides: ['sales.sales_returns' => 'this_key_does_not_exist'])]);

        // Falls back to its real default parent rather than vanishing or crashing.
        $salesChildren = array_map(static fn (AdminMenuNode $n): string => $n->key, $this->findChildren($tree, 'sales'));
        self::assertContains('sales.sales_returns', $salesChildren);
    }

    public function testReparentingUnderANonGroupKeyIsIgnored(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        // "help" is a single top-level link (route set), never a container — reparenting under it
        // would silently orphan the entry, since the template only loops children for a group.
        $tree = $builder->build([$this->provider(parentOverrides: ['sales.sales_returns' => 'help'])]);

        $salesChildren = array_map(static fn (AdminMenuNode $n): string => $n->key, $this->findChildren($tree, 'sales'));
        self::assertContains('sales.sales_returns', $salesChildren);
    }

    // --- Adversarial: custom item key collides with a core key ----------------------------------

    public function testACustomItemWhoseKeyCollidesWithACoreKeyIsDropped(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $tree = $builder->build([$this->provider(customItems: [
            ['key' => 'dashboard', 'label' => 'Fake Dashboard', 'url' => '/evil', 'parent' => null, 'order' => -999],
        ])]);

        $dashboard = $this->findTopLevel($tree, 'dashboard');
        self::assertNotNull($dashboard);
        self::assertFalse($dashboard->custom, 'the real core dashboard entry must survive untouched');
        self::assertSame('Dashboard', $dashboard->label);
    }

    public function testASecondCustomItemWithADuplicateKeyOfAnEarlierCustomItemIsDropped(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $tree = $builder->build([$this->provider(customItems: [
            ['key' => 'custom.dup', 'label' => 'First', 'url' => '/first', 'parent' => null, 'order' => 0],
            ['key' => 'custom.dup', 'label' => 'Second', 'url' => '/second', 'parent' => null, 'order' => 0],
        ])]);

        $node = $this->findTopLevel($tree, 'custom.dup');
        self::assertNotNull($node);
        self::assertSame('First', $node->label, 'first-registered wins; the duplicate never overwrites it');
    }

    // --- Adversarial: malformed/oversized order values -------------------------------------------

    public function testANonNumericOrderOverrideFallsBackToZeroInsteadOfCrashing(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        /** @phpstan-ignore-next-line intentionally malformed input */
        $tree = $builder->build([$this->provider(orderOverrides: ['help' => 'not-a-number'])]);

        // Every default order is >= 10, so a value coerced to 0 (rather than crashing on the
        // non-numeric string, or being left as-is and comparing unpredictably) sorts "help" to
        // the very front — deterministic proof the coercion actually happened.
        $topLevelKeys = array_map(static fn (array $e): string => $e['node']->key, $tree);
        self::assertSame('help', $topLevelKeys[0]);
    }

    public function testAnOversizedOrderValueIsClampedRatherThanOverflowing(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $tree = $builder->build([$this->provider(orderOverrides: ['help' => \PHP_INT_MAX])]);

        // Clamped to the front of nothing pathological happening: help just sorts last among
        // top-level entries, same as any very-large-but-sane value would.
        $topLevelKeys = array_map(static fn (array $e): string => $e['node']->key, $tree);
        self::assertSame('help', end($topLevelKeys));
    }

    public function testAHugeNegativeOrderValueIsClampedToTheFront(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $tree = $builder->build([$this->provider(orderOverrides: ['help' => \PHP_INT_MIN])]);

        self::assertSame('help', $tree[0]['node']->key);
    }

    public function testACustomItemsMalformedOrderFallsBackToZero(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $tree = $builder->build([$this->provider(customItems: [
            ['key' => 'custom.x', 'label' => 'X', 'url' => '/x', 'parent' => null, 'order' => 'garbage'],
        ])]);

        $node = $this->findTopLevel($tree, 'custom.x');
        self::assertNotNull($node);
        self::assertSame(0, $node->order);
    }

    // --- Adversarial: override data referencing a since-deleted parent --------------------------

    public function testAParentOverrideReferencingASinceDeletedCatalogKeyIsIgnored(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        // Simulates stored data written against an older catalog that has since dropped this key.
        $tree = $builder->build([$this->provider(parentOverrides: ['sales.sales_returns' => 'a_deleted_group_key'])]);

        $salesChildren = array_map(static fn (AdminMenuNode $n): string => $n->key, $this->findChildren($tree, 'sales'));
        self::assertContains('sales.sales_returns', $salesChildren);
    }

    public function testACustomItemReferencingASinceDeletedParentFallsBackToTopLevel(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $tree = $builder->build([$this->provider(customItems: [
            ['key' => 'custom.orphaned', 'label' => 'Orphaned', 'url' => '/orphaned', 'parent' => 'a_deleted_group_key', 'order' => 0],
        ])]);

        self::assertNotNull($this->findTopLevel($tree, 'custom.orphaned'));
    }

    /**
     * #538, then the later Sales consolidation: what used to be four separate top-level groups
     * (Customers, Carts, Quotes, Orders) are one Sales group now, its children in the order the
     * workflow happens — customer, quote, order, what was billed, what came back, what was
     * credited — with the cart last as the lowest-commitment thing here. Asserted on the BUILT
     * tree rather than on the catalog alone, so a regression in how defaultTree() derives each
     * node's order (or a stray sort in assemble()) is caught even if ITEMS itself still reads in
     * the right order — and through a provider that stores nothing, since that is the state every
     * install starts in.
     */
    public function testSalesConsolidatesCustomersQuotesOrdersAndCartsInWorkflowOrder(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $tree = $builder->build([$this->provider()]);

        $topLevelKeys = array_map(static fn (array $entry): string => $entry['node']->key, $tree);
        self::assertContains('sales', $topLevelKeys);
        self::assertNotContains('companies', $topLevelKeys, 'Customers folded into Sales, not left behind as its own group');
        self::assertNotContains('carts', $topLevelKeys, 'Carts folded into Sales, not left behind as its own group');
        self::assertNotContains('quotes', $topLevelKeys, 'Quotes folded into Sales, not left behind as its own group');
        self::assertNotContains('orders', $topLevelKeys, 'Orders folded into Sales, not left behind as its own group');

        // None of the create items is here: each renders only as the `+` on its own list row
        // (queue item 35's later revision — a flagged item no longer keeps a row of its own once
        // its target resolves), which is also why this list is shorter than ITEMS' own count of
        // Sales entries.
        self::assertSame(
            ['sales.customers', 'sales.quotes', 'sales.orders', 'sales.invoices', 'sales.credit_notes', 'sales.sales_returns', 'sales.carts'],
            array_map(static fn (AdminMenuNode $n): string => $n->key, $this->findChildren($tree, 'sales') ?? []),
        );
    }

    /**
     * A PSR-3 recorder, so "it degrades" can be asserted alongside "and it says so". No return type
     * on purpose: callers read ->lines, which LoggerInterface does not have.
     */
    private function recordingLogger()
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $rendered = (string) $message;
                foreach ($context as $placeholder => $value) {
                    $rendered = str_replace('{' . $placeholder . '}', (string) $value, $rendered);
                }

                $this->lines[] = $level . ': ' . $rendered;
            }
        };
    }

    // --- Row affordances (queue item 35) --------------------------------------------------------

    /**
     * Every affordance key anywhere in a built tree, in document order.
     *
     * @param list<array{node: AdminMenuNode, children: list<AdminMenuNode>, affordances: array<string, list<AdminMenuNode>>}> $tree
     *
     * @return list<string>
     */
    private function allAffordanceKeys(array $tree): array
    {
        $keys = [];
        foreach ($tree as $entry) {
            foreach ($entry['affordances'] as $onRow) {
                foreach ($onRow as $affordance) {
                    $keys[] = $affordance->key;
                }
            }
        }

        return $keys;
    }

    /**
     * @param list<array{node: AdminMenuNode, children: list<AdminMenuNode>, affordances: array<string, list<AdminMenuNode>>}> $tree
     *
     * @return list<AdminMenuNode>
     */
    private function findAffordances(array $tree, string $rowKey): array
    {
        foreach ($tree as $entry) {
            if (isset($entry['affordances'][$rowKey])) {
                return $entry['affordances'][$rowKey];
            }
        }

        return [];
    }

    /** @return list<array{key: string, label: string, url: string, parent: ?string, order: int}> */
    private function listAndCreateSpecs(string $attachTo = 'custom.list', ?string $accessibleName = null): array
    {
        $create = ['key' => 'custom.create', 'label' => 'New Thing', 'url' => '', 'parent' => 'products', 'order' => 20, 'route' => 'admin_dashboard', 'attachTo' => $attachTo, 'affordanceIcon' => 'plus'];
        if ($accessibleName !== null) {
            $create['accessibleName'] = $accessibleName;
        }

        return [
            ['key' => 'custom.list', 'label' => 'Things', 'url' => '', 'parent' => 'products', 'order' => 10, 'route' => 'admin_dashboard'],
            $create,
        ];
    }

    /**
     * A flagged item is filed under the row it named INSTEAD OF keeping a row of its own.
     *
     * Not additive any more: a list row and a `+` beside it going to the same create screen read
     * as two links for one workflow, not one link with a shortcut. Both halves are asserted —
     * the item is gone from the row list AND from the flattened key order — because leaving either
     * one behind would be a silent, very plausible regression back toward the duplication this
     * removed.
     */
    public function testAFlaggedItemRendersOnlyOnTheRowItNamesNotAsARowOfItsOwn(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $tree = $builder->build([$this->provider(customItems: $this->listAndCreateSpecs())]);

        $childKeys = array_map(static fn (AdminMenuNode $n): string => $n->key, $this->findChildren($tree, 'products') ?? []);
        self::assertContains('custom.list', $childKeys);
        self::assertNotContains('custom.create', $childKeys, 'the flag replaces the row with an icon; it does not add the icon beside the row');
        self::assertNotContains('custom.create', AdminMenuTreeBuilder::flattenKeys($tree));

        $affordances = $this->findAffordances($tree, 'custom.list');
        self::assertCount(1, $affordances);
        self::assertSame('custom.create', $affordances[0]->key);
        // It keeps its own identity: route, label and permission are untouched by the flag.
        self::assertSame('admin_dashboard', $affordances[0]->route);
        self::assertSame('New Thing', $affordances[0]->label);
    }

    /**
     * The regression guard for the item beside the flagged one: declared exactly as it always was,
     * it still renders exactly as it always did — the feature's effect is scoped to flagged items.
     */
    public function testAnUnflaggedItemIsUnchangedByTheFeatureExisting(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $tree = $builder->build([$this->provider(customItems: $this->listAndCreateSpecs())]);

        $list = null;
        foreach ($this->findChildren($tree, 'products') ?? [] as $child) {
            if ($child->key === 'custom.list') {
                $list = $child;
            }
        }

        self::assertNotNull($list);
        self::assertFalse($list->isAffordance());
        self::assertNull($list->attachTo);
        self::assertNull($list->affordanceIcon);
        self::assertNull($list->accessibleName);
        self::assertSame([], $this->findAffordances($tree, 'custom.create'));

        // And the shipped catalog flags exactly its six create entries and nothing else. Pinned
        // here rather than left to the fixture, so that flagging a seventh core entry has to be
        // said out loud in a test before it can reach the sidebar snapshot.
        // In the order the ROWS they sit on appear, which is what a person reading the sidebar
        // sees: Products first, then Sales's own children in THEIR order — Customers, Quotes,
        // Orders, Invoices, Credit Notes (Sales Returns and Carts carry no affordance of their own).
        self::assertSame(
            ['products.add', 'sales.customers_add', 'sales.quotes_create', 'sales.orders_create', 'sales.invoices_create', 'sales.credit_notes_create'],
            $this->allAffordanceKeys($builder->build([])),
        );
    }

    /**
     * A target nothing declares costs the icon and NOTHING else. The menu still renders, the item
     * still has its own row, and no exception escapes.
     *
     * This was built the other way first — it threw, on the reasoning that attachTo is declared in
     * code beside the item so a target that does not resolve is a typo, and swallowing a typo
     * leaves an icon nobody can find. The owner reversed it and was right: an affordance is
     * decoration, and decoration does not get to take a navigation down. It is also not only the
     * typo case — a bundle can attach to a CORE key, and core can rename that key knowing nothing
     * about the bundle, which would let one bundle's icon break the menu for everybody.
     */
    public function testAttachingToAKeyThatDoesNotExistCostsTheIconAndNothingElse(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $tree = $builder->build([$this->provider(customItems: $this->listAndCreateSpecs('purchases.no_such_row'))]);

        // The menu renders in full, not just "did not throw".
        self::assertNotSame([], $tree);
        self::assertContains('dashboard', AdminMenuTreeBuilder::flattenKeys($tree));

        $keys = array_map(static fn (AdminMenuNode $n): string => $n->key, $this->findChildren($tree, 'products') ?? []);
        self::assertContains('custom.create', $keys, 'the item keeps its own row when its target does not exist');
        self::assertContains('custom.list', $keys, 'and the rest of the group is untouched');

        self::assertNotContains('custom.create', $this->allAffordanceKeys($tree), 'nothing can be attached to a row that does not exist');
    }

    /**
     * Degrading quietly is not the same as degrading silently. The log line is what keeps a typo
     * findable once it can no longer announce itself by failing, so it names the item AND the
     * target it wanted — a message saying only "missing key" sends whoever reads it hunting
     * through every provider for which item meant it.
     */
    public function testAMissingTargetIsLoggedWithTheItemAndTheTargetItWanted(): void
    {
        $logger = $this->recordingLogger();
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true), $logger);

        $builder->build([$this->provider(customItems: $this->listAndCreateSpecs('purchases.no_such_row'))]);

        self::assertCount(1, $logger->lines);
        self::assertStringContainsString('custom.create', $logger->lines[0]);
        self::assertStringContainsString('purchases.no_such_row', $logger->lines[0]);
    }

    /**
     * The second, different degrade: there IS a row to sit on, so the affordance renders — it just
     * has no glyph in it. The icon is the decoration; the link is the function, and losing a glyph
     * must not lose the shortcut. The node keeps its route and its accessible name, and returns no
     * SVG rather than a wrong one.
     */
    public function testAnUnknownIconNameKeepsTheAffordanceAndDropsOnlyTheGlyph(): void
    {
        $logger = $this->recordingLogger();
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true), $logger);

        $specs = $this->listAndCreateSpecs();
        $specs[1]['affordanceIcon'] = 'gear';

        $tree = $builder->build([$this->provider(customItems: $specs)]);

        $affordances = $this->findAffordances($tree, 'custom.list');
        self::assertCount(1, $affordances, 'an unknown icon name must not cost the affordance');
        self::assertSame('admin_dashboard', $affordances[0]->route);
        self::assertSame('New Thing', $affordances[0]->affordanceName());
        self::assertNull($affordances[0]->affordanceIconSvg(), 'no glyph rather than the wrong glyph');
    }

    /** And the log for it names the icons that DO exist, so a typo reads its own answer. */
    public function testAnUnknownIconNameIsLoggedWithTheNamesThatDoExist(): void
    {
        $logger = $this->recordingLogger();
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true), $logger);

        $specs = $this->listAndCreateSpecs();
        $specs[1]['affordanceIcon'] = 'gear';
        $builder->build([$this->provider(customItems: $specs)]);

        self::assertCount(1, $logger->lines);
        self::assertStringContainsString('gear', $logger->lines[0]);
        self::assertStringContainsString('cog', $logger->lines[0], 'the answer to this particular typo must be in the message');
        foreach (AdminMenuIconSet::names() as $name) {
            self::assertStringContainsString($name, $logger->lines[0]);
        }
    }

    /** Naming no icon at all is legal, not a mistake: bare button, and nothing logged. */
    public function testNamingNoIconIsNotAMistakeAndIsNotLogged(): void
    {
        $logger = $this->recordingLogger();
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true), $logger);

        $specs = $this->listAndCreateSpecs();
        unset($specs[1]['affordanceIcon']);

        $tree = $builder->build([$this->provider(customItems: $specs)]);

        $affordances = $this->findAffordances($tree, 'custom.list');
        self::assertCount(1, $affordances);
        self::assertNull($affordances[0]->affordanceIconSvg());
        self::assertSame([], $logger->lines);
    }

    /** A hidden target is an admin configuring their sidebar, not a mistake — so nothing is logged. */
    public function testHidingTheTargetRowIsNotLoggedAsAMistake(): void
    {
        $logger = $this->recordingLogger();
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true), $logger);

        $builder->build([$this->provider(hiddenKeys: ['custom.list'], customItems: $this->listAndCreateSpecs())]);

        self::assertSame([], $logger->lines);
    }

    /**
     * A target that is itself flagged no longer has a row to sit an icon on — it was swallowed
     * into its OWN icon, on 'custom.list'. Stacking a second icon there anyway (drawing
     * 'custom.second' onto 'custom.list' as if it named 'custom.create') would silently redirect
     * an icon to a row it never asked for, so instead the second item falls back to keeping its own
     * row: better a plain row than a shortcut nobody declared.
     */
    public function testAttachingToAnItemThatIsItselfFlaggedFallsBackToItsOwnRowInsteadOfStacking(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $specs = $this->listAndCreateSpecs();
        $specs[] = ['key' => 'custom.second', 'label' => 'Second', 'url' => '', 'parent' => 'products', 'order' => 30, 'route' => 'admin_dashboard', 'attachTo' => 'custom.create', 'affordanceIcon' => 'plus'];

        $tree = $builder->build([$this->provider(customItems: $specs)]);

        self::assertSame(['custom.create'], array_map(static fn (AdminMenuNode $n): string => $n->key, $this->findAffordances($tree, 'custom.list')));
        // custom.second's target (custom.create) is not itself a rendered row, so custom.second is
        // never swallowed — it keeps its own row instead of drawing an icon nowhere.
        self::assertContains('custom.second', array_map(static fn (AdminMenuNode $n): string => $n->key, $this->findChildren($tree, 'products') ?? []));
        self::assertSame([], $this->findAffordances($tree, 'custom.create'), 'nothing renders on a row that is itself only an icon');
    }

    /**
     * Hiding the target row IS stored data, so it degrades the way everything else here does: the
     * icon goes with the row it was drawn on, and the item is left exactly as it would have been
     * without the flag — its own row, its own link, still reachable.
     */
    public function testHidingTheTargetRowCostsTheIconAndNothingElse(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $tree = $builder->build([$this->provider(
            hiddenKeys: ['custom.list'],
            customItems: $this->listAndCreateSpecs(),
        )]);

        $keys = array_map(static fn (AdminMenuNode $n): string => $n->key, $this->findChildren($tree, 'products') ?? []);

        self::assertNotContains('custom.list', $keys);
        self::assertContains('custom.create', $keys, 'the flagged item must stay reachable when the row it sits on is hidden');
        self::assertSame([], $this->findAffordances($tree, 'custom.list'));

        self::assertNotContains('custom.create', $this->allAffordanceKeys($tree), 'no icon can survive the row it was drawn on');
    }

    /** Affordances on one row sort by the item's own existing order number, like any sibling. */
    public function testAffordancesOnOneRowSortByTheItemsOwnOrder(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $tree = $builder->build([$this->provider(customItems: [
            ['key' => 'custom.list', 'label' => 'Things', 'url' => '', 'parent' => 'products', 'order' => 10, 'route' => 'admin_dashboard'],
            ['key' => 'custom.later', 'label' => 'Later', 'url' => '', 'parent' => 'products', 'order' => 90, 'route' => 'admin_dashboard', 'attachTo' => 'custom.list', 'affordanceIcon' => 'cog'],
            ['key' => 'custom.earlier', 'label' => 'Earlier', 'url' => '', 'parent' => 'products', 'order' => 20, 'route' => 'admin_dashboard', 'attachTo' => 'custom.list', 'affordanceIcon' => 'plus'],
        ])]);

        self::assertSame(
            ['custom.earlier', 'custom.later'],
            array_map(static fn (AdminMenuNode $n): string => $n->key, $this->findAffordances($tree, 'custom.list')),
        );
    }

    /** The accessible name falls back to the item's own label, and the stated one overrides it. */
    public function testTheAccessibleNameFallsBackToTheLabelAndTheStatedOneOverridesIt(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $fallback = $builder->build([$this->provider(customItems: $this->listAndCreateSpecs())]);
        self::assertSame('New Thing', $this->findAffordances($fallback, 'custom.list')[0]->affordanceName());

        $stated = $builder->build([$this->provider(customItems: $this->listAndCreateSpecs(accessibleName: 'New Thing For This Row'))]);
        self::assertSame('New Thing For This Row', $this->findAffordances($stated, 'custom.list')[0]->affordanceName());
    }

    /** An affordance may sit on a top-level row too, not only on a child of a group. */
    public function testAnAffordanceCanSitOnATopLevelRow(): void
    {
        $builder = new AdminMenuTreeBuilder($this->bundleStatusRepo(true));

        $tree = $builder->build([$this->provider(customItems: [
            ['key' => 'custom.top_create', 'label' => 'New Help Article', 'url' => '', 'parent' => null, 'order' => 10, 'route' => 'admin_dashboard', 'attachTo' => 'help', 'affordanceIcon' => 'plus'],
        ])]);

        self::assertSame(
            ['custom.top_create'],
            array_map(static fn (AdminMenuNode $n): string => $n->key, $this->findAffordances($tree, 'help')),
        );
    }
}
