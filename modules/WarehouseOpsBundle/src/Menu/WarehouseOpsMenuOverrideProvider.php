<?php

declare(strict_types=1);

namespace WarehouseOpsBundle\Menu;

use App\Contract\Menu\AdminMenuOverrideProviderInterface;

/** This bundle's screens as the top-level Warehouse group, which it shares with BarcodeBundle. */
final class WarehouseOpsMenuOverrideProvider implements AdminMenuOverrideProviderInterface
{
    /** Must equal WarehouseOpsBundleDescriptor::getSource(): AdminMenuTreeBuilder skips a provider
     *  whose source is Inactive, so a drifted value stops the App Management switch hiding these. */
    public const SOURCE = 'WarehouseOpsBundle';

    /**
     * `group => true` with no route is what makes a node render as a group rather than a link.
     *
     * Declared identically by BarcodeBundle\Menu\BarcodeMenuOverrideProvider — either bundle can be
     * deleted or switched off alone, so each declares the group it contributes to and
     * AdminMenuTreeBuilder drops whichever copy arrives second. Keep the two in step.
     *
     * @var array<string, mixed>
     */
    public const WAREHOUSE_GROUP = [
        'key' => 'warehouse',
        'label' => 'Warehouse',
        'url' => '',
        'parent' => null,
        'order' => 250,
        'group' => true,
        'icon' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21V8l9-5 9 5v13"/><path d="M9 21v-6h6v6"/></svg>',
        'routePrefixes' => ['admin_bundle_warehouse_ops_', 'admin_bundle_barcodes'],
    ];

    /**
     * [key, label, route, order]. The order is the NEGATED tag priority these entries carried as
     * injection points, because the sidebar sorts ascending where the tagged iterator sorted
     * descending; the gap at -750 is BarcodeBundle's own entry.
     *
     * `warehouse.home` is the hub, and stays because App Management's Configure button opens it.
     */
    private const SCREENS = [
        ['warehouse.scan', 'Scan Console', 'admin_bundle_warehouse_ops_scan', -800],
        ['warehouse.pick_lists', 'Pick Lists', 'admin_bundle_warehouse_ops_pick_lists', -790],
        ['warehouse.stock', 'Stock', 'admin_bundle_warehouse_ops_stock', -785],
        ['warehouse.transfers', 'Transfers', 'admin_bundle_warehouse_ops_transfers', -780],
        ['warehouse.bin_map', 'Bin Map', 'admin_bundle_warehouse_ops_bin_map', -770],
        ['warehouse.labels', 'Labels', 'admin_bundle_warehouse_ops_labels', -760],
        ['warehouse.discrepancies', 'Discrepancies', 'admin_bundle_warehouse_ops_discrepancies', -740],
        ['warehouse.home', 'Warehouse Ops Home', 'admin_bundle_warehouse_ops_index', -730],
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
        $items = [self::WAREHOUSE_GROUP];

        foreach (self::SCREENS as [$key, $label, $route, $order]) {
            $items[] = ['key' => $key, 'label' => $label, 'url' => '', 'parent' => strstr($key, '.', true), 'order' => $order, 'route' => $route];
        }

        return $items;
    }
}
