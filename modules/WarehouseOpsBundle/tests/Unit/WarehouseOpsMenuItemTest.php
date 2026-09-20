<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Tests\Unit;

use App\Contract\Menu\AdminMenuOverrideProviderInterface;
use PHPUnit\Framework\TestCase;
use WarehouseOpsBundle\Bundle\WarehouseOpsBundleDescriptor;
use WarehouseOpsBundle\Entity\PickList;
use WarehouseOpsBundle\Entity\TransferOrder;
use WarehouseOpsBundle\Menu\WarehouseOpsMenuOverrideProvider;

final class WarehouseOpsMenuItemTest extends TestCase
{
    /** @return array<string, mixed> */
    private function menuItem(string $key): array
    {
        foreach ((new WarehouseOpsMenuOverrideProvider())->getCustomItems() as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        self::fail(sprintf('the sidebar provider contributes no "%s" entry', $key));
    }

    /**
     * House convention: the sidebar link and App Management's Configure button go to the same
     * place. The hub is the Configure target, so it keeps a sidebar line of its own — at the foot
     * of the Warehouse group since the screens became a tree instead of a flat injection-point
     * list.
     */
    public function testRouteMatchesTheBundleDescriptorsEditRoute(): void
    {
        self::assertSame(
            (new WarehouseOpsBundleDescriptor())->getEditRoute(),
            $this->menuItem('warehouse.home')['route'],
        );
        self::assertSame('warehouse', $this->menuItem('warehouse.home')['parent']);
    }

    /**
     * The provider names its own bundle. App\Menu\Admin\AdminMenuTreeBuilder skips a provider
     * whose getSource() is not active per BundleStatusRepository, so a source that disagreed with
     * the descriptor's would leave all seven entries in the sidebar with the bundle switched off.
     */
    public function testTheSidebarProviderNamesTheSameSourceTheDescriptorDoes(): void
    {
        $provider = new WarehouseOpsMenuOverrideProvider();

        self::assertInstanceOf(AdminMenuOverrideProviderInterface::class, $provider);
        self::assertSame((new WarehouseOpsBundleDescriptor())->getSource(), $provider->getSource());
    }

    /**
     * `isActiveForInstance()` derives the source from the root namespace segment, so a descriptor
     * whose getSource() disagrees with its own namespace would make the Active/Inactive kill-switch
     * silently miss every seam this bundle registers.
     */
    public function testTheDescriptorSourceMatchesTheNamespaceRoot(): void
    {
        $descriptor = new WarehouseOpsBundleDescriptor();

        self::assertSame(
            substr($descriptor::class, 0, strpos($descriptor::class, '\\') ?: 0),
            $descriptor->getSource(),
        );
    }

    /**
     * The heading these screens share is now a real top-level GROUP rather than a
     * `nav-sub-section` label inside Apps, and it is SHARED with BarcodeBundle, which declares an
     * identical spec of its own (see BarcodeBundle\Menu\BarcodeMenuOverrideProvider). The two are
     * pinned to the same literal values here and in that bundle's own test rather than compared
     * across the two, so deleting either bundle cannot break the other's tests.
     *
     * `group => true` with no route is what keeps `custom === false` in
     * App\Menu\Admin\AdminMenuTreeBuilder, and that is the only thing
     * templates/admin/_main/layout.html.twig renders as a nav-group with a children list.
     */
    public function testTheWarehouseGroupIsTheSharedTopLevelGroupBarcodesAlsoDeclares(): void
    {
        self::assertSame([
            'key' => 'warehouse',
            'label' => 'Warehouse',
            'url' => '',
            'parent' => null,
            'order' => 250,
            'group' => true,
            'icon' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21V8l9-5 9 5v13"/><path d="M9 21v-6h6v6"/></svg>',
            'routePrefixes' => ['admin_bundle_warehouse_ops_', 'admin_bundle_barcodes'],
        ], WarehouseOpsMenuOverrideProvider::WAREHOUSE_GROUP);

        self::assertSame(WarehouseOpsMenuOverrideProvider::WAREHOUSE_GROUP, $this->menuItem('warehouse'));
    }

    /**
     * Every screen entry is a child of the group and carries a route, and the group is declared
     * before them — App\Menu\Admin\AdminMenuTreeBuilder validates a custom item's parent against
     * the nodes merged SO FAR, so a child listed first would quietly land at the top level.
     *
     * The orders are the negated #632 tag priorities, and the gap at -750 is BarcodeBundle's
     * "Barcodes": one sorted list across two bundles.
     */
    public function testTheScreensAreChildrenInTheOrderTheOldTagPrioritiesGaveThem(): void
    {
        $children = [];

        foreach ((new WarehouseOpsMenuOverrideProvider())->getCustomItems() as $item) {
            if (($item['group'] ?? false) === true) {
                continue;
            }

            self::assertSame('warehouse', $item['parent'], $item['key'] . ' is not under the Warehouse group');
            self::assertArrayHasKey('route', $item, $item['key'] . ' has no route');
            $children[$item['key']] = $item['order'];
        }

        self::assertSame([
            'warehouse.scan' => -800,
            'warehouse.pick_lists' => -790,
            'warehouse.stock' => -785,
            'warehouse.transfers' => -780,
            'warehouse.bin_map' => -770,
            'warehouse.labels' => -760,
            'warehouse.discrepancies' => -740,
            'warehouse.home' => -730,
        ], $children);
    }

    /**
     * There is no `picking` pick-list status on purpose: a partly-confirmed round is a released
     * round whose tasks have different amounts against them, and a second status saying the same
     * thing is a second thing to keep true.
     */
    public function testAPickListHasFourStatusesAndNoneOfThemIsPicking(): void
    {
        self::assertSame(['draft', 'released', 'closed', 'cancelled'], PickList::statuses());
    }

    /**
     * A transfer is two movements with a real gap between them, and `dispatched` is the name of
     * that gap — stock on a truck, unsellable at both ends.
     */
    public function testATransferHasAStatusForTheStockOnTheTruck(): void
    {
        self::assertSame(['draft', 'dispatched', 'received', 'cancelled'], TransferOrder::statuses());
    }
}
