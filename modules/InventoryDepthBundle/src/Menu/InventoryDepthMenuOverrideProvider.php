<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Menu;

use App\Contract\Menu\AdminMenuOverrideProviderInterface;

/** This bundle's screens as the top-level Inventory group. */
final class InventoryDepthMenuOverrideProvider implements AdminMenuOverrideProviderInterface
{
    /** Must equal InventoryDepthBundleDescriptor::getSource(): AdminMenuTreeBuilder skips a provider
     *  whose source is Inactive, so a drifted value stops the App Management switch hiding these. */
    public const SOURCE = 'InventoryDepthBundle';

    /** `group => true` with no route is what makes a node render as a group rather than a link. */
    private const GROUP = [
        'key' => 'inventory',
        'label' => 'Inventory',
        'url' => '',
        'parent' => null,
        'order' => 260,
        'group' => true,
        'icon' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7h-9M14 17H5M17 21a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM7 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/></svg>',
        'routePrefixes' => ['admin_bundle_inventory_depth_'],
    ];

    /**
     * [key, label, route, order]. The order is the NEGATED tag priority these entries carried as
     * injection points, because the sidebar sorts ascending where the tagged iterator sorted
     * descending.
     *
     * `inventory.home` is the hub, and stays because App Management's Configure button opens it.
     */
    private const SCREENS = [
        // The owner's own stated gap: "I wanted to lookup a SKU and don't have a place to go."
        // Leads the section for exactly that reason — docs/plans/2026-09-15-product-inventory-hub.md.
        ['inventory.product_lookup', 'Product Lookup', 'admin_bundle_inventory_depth_product_lookup', -710],
        ['inventory.stock_by_location', 'Stock by Location', 'admin_bundle_inventory_depth_stock_by_location', -700],
        ['inventory.serials', 'Serials', 'admin_bundle_inventory_depth_serial_lookup', -690],
        ['inventory.lots', 'Lots', 'admin_bundle_inventory_depth_lots', -680],
        // #725: search by batch/lot code, find where it is now and who was sold units of it.
        ['inventory.lot_trace', 'Recall / Lot Trace', 'admin_bundle_inventory_depth_lot_trace', -675],
        ['inventory.bins', 'Bins', 'admin_bundle_inventory_depth_bins', -670],
        ['inventory.cycle_count', 'Cycle Count', 'admin_bundle_inventory_depth_cycle_count', -660],
        ['inventory.movements', 'Movements', 'admin_bundle_inventory_depth_movements', -650],
        ['inventory.adjust', 'Adjust', 'admin_bundle_inventory_depth_adjust', -640],
        // #22. Beside Adjust deliberately: before this screen existed, breaking a case could only be
        // done as two unrelated adjustments, and somebody looking for it will look there first.
        ['inventory.pack_conversion', 'Break a Case', 'admin_bundle_inventory_depth_pack_conversion', -635],
        ['inventory.low_stock', 'Low Stock', 'admin_bundle_inventory_depth_low_stock', -630],
        ['inventory.tracking', 'Tracking', 'admin_bundle_inventory_depth_tracking_worklist', -620],
        ['inventory.home', 'Inventory Depth Home', 'admin_bundle_inventory_depth_index', -610],
    ];

    public function getSource(): string
    {
        return self::SOURCE;
    }

    public function getHiddenKeys(): array
    {
        return [];
    }

    public function getOrderOverrides(): array
    {
        return [];
    }

    public function getParentOverrides(): array
    {
        return [];
    }

    /** Group first: AdminMenuTreeBuilder validates a child's parent against the nodes merged so far. */
    public function getCustomItems(): array
    {
        $items = [self::GROUP];

        foreach (self::SCREENS as [$key, $label, $route, $order]) {
            $items[] = ['key' => $key, 'label' => $label, 'url' => '', 'parent' => strstr($key, '.', true), 'order' => $order, 'route' => $route];
        }

        return $items;
    }
}
