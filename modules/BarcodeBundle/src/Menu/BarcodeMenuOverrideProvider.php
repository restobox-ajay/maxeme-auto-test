<?php

declare(strict_types=1);

namespace BarcodeBundle\Menu;

use App\Contract\Menu\AdminMenuOverrideProviderInterface;

/** This bundle's one screen, in the top-level Warehouse group it shares with WarehouseOpsBundle. */
final class BarcodeMenuOverrideProvider implements AdminMenuOverrideProviderInterface
{
    /** Must equal BarcodeBundleDescriptor::getSource(): AdminMenuTreeBuilder skips a provider whose
     *  source is Inactive, so a drifted value stops the App Management switch hiding this entry. */
    public const SOURCE = 'BarcodeBundle';

    /**
     * `group => true` with no route is what makes a node render as a group rather than a link.
     *
     * Kept identical to WarehouseOpsBundle\Menu\WarehouseOpsMenuOverrideProvider::WAREHOUSE_GROUP —
     * see the note there. AdminMenuTreeBuilder drops whichever copy arrives second.
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

    /**
     * Group first: AdminMenuTreeBuilder validates a child's parent against the nodes merged so far.
     * Order -750 is the negated tag priority, which lands this between WarehouseOpsBundle's Labels
     * (-760) and Discrepancies (-740).
     */
    public function getCustomItems(): array
    {
        return [
            self::WAREHOUSE_GROUP,
            ['key' => 'warehouse.barcodes', 'label' => 'Barcodes', 'url' => '', 'parent' => 'warehouse', 'order' => -750, 'route' => 'admin_bundle_barcodes'],
        ];
    }
}
