<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Unit;

use App\Contract\Menu\AdminMenuOverrideProviderInterface;
use InventoryDepthBundle\Bundle\InventoryDepthBundleDescriptor;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Menu\InventoryDepthMenuOverrideProvider;
use PHPUnit\Framework\TestCase;

final class InventoryDepthMenuItemTest extends TestCase
{
    /** @return array<string, mixed> */
    private function menuItem(string $key): array
    {
        foreach ((new InventoryDepthMenuOverrideProvider())->getCustomItems() as $item) {
            if ($item['key'] === $key) {
                return $item;
            }
        }

        self::fail(sprintf('the sidebar provider contributes no "%s" entry', $key));
    }

    /**
     * House convention: the sidebar link and App Management's Configure button go to the same
     * place. The hub is the Configure target, so it keeps a sidebar line of its own — at the foot
     * of the Inventory group since the screens became a tree instead of a flat injection-point
     * list.
     */
    public function testRouteMatchesTheBundleDescriptorsEditRoute(): void
    {
        self::assertSame(
            (new InventoryDepthBundleDescriptor())->getEditRoute(),
            $this->menuItem('inventory.home')['route'],
        );
        self::assertSame('inventory', $this->menuItem('inventory.home')['parent']);
    }

    /**
     * The provider names its own bundle. App\Menu\Admin\AdminMenuTreeBuilder skips a provider
     * whose getSource() is not active per BundleStatusRepository, so a source that disagreed with
     * the descriptor's would leave all eleven entries in the sidebar with the bundle switched off.
     */
    public function testTheSidebarProviderNamesTheSameSourceTheDescriptorDoes(): void
    {
        $provider = new InventoryDepthMenuOverrideProvider();

        self::assertInstanceOf(AdminMenuOverrideProviderInterface::class, $provider);
        self::assertSame((new InventoryDepthBundleDescriptor())->getSource(), $provider->getSource());
    }

    /**
     * Inventory is a real TOP-LEVEL group now, not a `nav-sub-section` label inside Apps.
     * `group => true` with no route is what keeps `custom === false` in
     * App\Menu\Admin\AdminMenuTreeBuilder, and that is the only thing
     * templates/admin/_main/layout.html.twig renders as a nav-group with a children list.
     *
     * Every screen is a child of it, declared after it — the builder validates a custom item's
     * parent against the nodes merged SO FAR, so a child listed first would quietly land at the
     * top level. The orders are the negated #632 tag priorities.
     */
    public function testTheScreensAreChildrenOfATopLevelInventoryGroup(): void
    {
        $group = $this->menuItem('inventory');

        self::assertSame('Inventory', $group['label']);
        self::assertTrue($group['group']);
        self::assertNull($group['parent']);
        self::assertArrayNotHasKey('route', $group);
        self::assertSame(['admin_bundle_inventory_depth_'], $group['routePrefixes']);

        $children = [];

        foreach ((new InventoryDepthMenuOverrideProvider())->getCustomItems() as $item) {
            if (($item['group'] ?? false) === true) {
                continue;
            }

            self::assertSame('inventory', $item['parent'], $item['key'] . ' is not under the Inventory group');
            self::assertArrayHasKey('route', $item, $item['key'] . ' has no route');
            $children[$item['key']] = $item['order'];
        }

        self::assertSame([
            // Product Inventory Hub (docs/plans/2026-09-15-product-inventory-hub.md): leads the
            // section — "I wanted to lookup a SKU and don't have a place to go" was the owner's own
            // stated gap this entry closes.
            'inventory.product_lookup' => -710,
            'inventory.stock_by_location' => -700,
            'inventory.serials' => -690,
            'inventory.lots' => -680,
            // #725: search by batch/lot code, find where it is now and who was sold units of it.
            'inventory.lot_trace' => -675,
            'inventory.bins' => -670,
            'inventory.cycle_count' => -660,
            'inventory.movements' => -650,
            'inventory.adjust' => -640,
            // #22, placed between Adjust and Low Stock deliberately: before it existed, breaking a
            // case could only be done as two unrelated adjustments, so that is where somebody
            // looking for it will look.
            'inventory.pack_conversion' => -635,
            'inventory.low_stock' => -630,
            'inventory.tracking' => -620,
            'inventory.home' => -610,
        ], $children);
    }

    /**
     * `isActiveForInstance()` derives the source from the root namespace segment, so a descriptor
     * whose getSource() disagrees with its own namespace would make the Active/Inactive kill-switch
     * silently miss every seam this bundle registers.
     */
    public function testTheDescriptorSourceMatchesTheNamespaceRoot(): void
    {
        $descriptor = new InventoryDepthBundleDescriptor();

        self::assertSame(
            substr($descriptor::class, 0, strpos($descriptor::class, '\\') ?: 0),
            $descriptor->getSource(),
        );
    }

    /**
     * `available` is the ONE status the core number counts. If a status is ever added to this list
     * without a deliberate decision about whether it is sellable, the invariant quietly changes
     * meaning — so the sellable set is pinned here rather than left implicit.
     */
    public function testOnlyAvailableIsSellable(): void
    {
        self::assertContains(InventoryDetail::STATUS_AVAILABLE, InventoryDetail::statuses());

        $notSellable = array_values(array_diff(InventoryDetail::statuses(), [InventoryDetail::STATUS_AVAILABLE]));

        self::assertSame(
            ['in_transit', 'damaged', 'quarantine', 'expired', 'sold', 'scrapped', 'lost', 'returned', 'returned_to_vendor'],
            $notSellable,
        );
    }

    /** Rule 3: these keep their warehouse but drop their bin, which is what makes "where was it lost" answerable. */
    public function testTerminalStatusesAreTheOnesThatLeaveTheBuilding(): void
    {
        self::assertSame(['sold', 'scrapped', 'lost', 'returned_to_vendor'], InventoryDetail::terminalStatuses());
    }
}
