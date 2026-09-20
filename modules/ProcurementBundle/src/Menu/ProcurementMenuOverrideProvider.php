<?php

declare(strict_types=1);

namespace ProcurementBundle\Menu;

use App\Contract\Menu\AdminMenuOverrideProviderInterface;

/** This bundle's screens as two top-level sidebar groups, Vendors and Purchases. */
final class ProcurementMenuOverrideProvider implements AdminMenuOverrideProviderInterface
{
    /** Must equal ProcurementBundleDescriptor::getSource(): AdminMenuTreeBuilder skips a provider
     *  whose source is Inactive, so a drifted value stops the App Management switch hiding these. */
    public const SOURCE = 'ProcurementBundle';

    /** key => [label, order, route prefixes that open the group]. */
    private const GROUPS = [
        // The vendor prefix also covers vendor RETURNS, whose entry is under Purchases, so both
        // groups open there — as Products and Companies both do on Company Pricing.
        'vendors' => ['Vendors', 230, ['admin_bundle_procurement_vendor'], '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9h18M3 9l2-5h14l2 5M5 9v11h14V9"/></svg>'],
        'purchases' => ['Purchases', 240, [
            // Kept although the RFQ row is hidden (see SCREENS): somebody who opens an RFQ by its
            // own URL still gets the Purchases group open around them rather than a sidebar that
            // has no idea where they are. A prefix opens a group; it does not draw a row.
            'admin_bundle_procurement_rfq',
            'admin_bundle_procurement_purchase_order',
            'admin_bundle_procurement_expected_arrivals',
            'admin_bundle_procurement_receipt',
            'admin_bundle_procurement_receive',
            'admin_bundle_procurement_receiving',
            'admin_bundle_procurement_vendor_return',
            'admin_bundle_procurement_bill',
            'admin_bundle_procurement_debit_memo',
            'admin_bundle_procurement_exceptions',
            'admin_bundle_procurement_index',
            'admin_bundle_procurement_settings',
        ], '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/></svg>', '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/></svg>'],
    ];

    /**
     * [key, label, route, order]. The key's prefix names its group; the order is the NEGATED tag
     * priority these entries carried as injection points, because the sidebar sorts ascending
     * where the tagged iterator sorted descending.
     *
     * `purchases.home` is the hub, and stays because App Management's Configure button opens it.
     */

    /**
     * The Warehouse group, declared here as well as in WarehouseOpsBundle and BarcodeBundle,
     * because receiving is warehouse work and this bundle contributes two screens to it. An
     * instance can run Procurement with Warehouse Operations switched off, and without this
     * copy those two would fall back to top level. Keep all three identical.
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
     * The three create screens that ALSO render as a `+` on the right of their own list row
     * (queue item 35): key => [attachTo, icon, accessible name].
     *
     * Each one NAMES the row it sits on. Not "the row above me" — ordering here is numeric, three
     * bundles inject into these same groups, and App Management can reorder the lot, so a
     * positional rule would one day attach New Bill to Vendor Returns and look entirely correct
     * doing it. A named target that has gone missing draws no icon and says so in the log, which
     * is a great deal easier to notice than a + on the wrong row.
     *
     * Every target here is declared in THIS provider, which is the tidy case but not a rule: a
     * provider is skipped whole when its bundle is Inactive, so attaching to another bundle's key
     * means the icon comes and goes with that bundle. That is survivable — no target, no icon, and
     * the item keeps its row — but attaching within your own provider, or to one of core's
     * AdminMenuCatalog keys, is the version with no surprises in it.
     *
     * NOT additive any more (App\Menu\Admin\AdminMenuTreeBuilder::assemble()): a flagged item's own
     * row and the `+` it draws elsewhere used to both render, which put "New Bill" on the sidebar
     * twice — once as its own row, once as the icon on Bills, for the same destination. Now it
     * renders ONLY as the icon while its target resolves, and falls back to its own row only if the
     * target is hidden, deleted, or (per the builder's own note) itself an icon with no row left to
     * carry a second one. This entry's fields still describe the same thing they always did — which
     * row it names, what icon, what accessible name — the builder just no longer keeps both.
     *
     * "New Purchase Order" is the one stated accessible name: the sidebar label is abbreviated to
     * fit a narrow row, and an icon announced as "New P O" is worse than one announced in full.
     * The other two say what their label says, which is the field's whole point being optional.
     *
     * @var array<string, array{string, string, ?string}>
     */
    private const ROW_AFFORDANCES = [
        'purchases.order_new' => ['purchases.orders', 'plus', 'New Purchase Order'],
        'purchases.bill_new' => ['purchases.bills', 'plus', null],
        'warehouse.receipt_new' => ['warehouse.receiving', 'plus', null],
    ];

    /**
     * The RFQ's row is NOT here, and -905 is the gap it left (queue item 56, GitHub #662).
     *
     * The owner asked for it off the menu: what is built awards one whole RFQ to one vendor —
     * `RfqConversionService::convert()` takes a single `RfqVendorReply` and refuses it unless
     * `isFullyPriced()` — where NetSuite and Zoho both award per LINE, several vendors able to win
     * different lines of one tender. Splitting an award is what tendering is for, so it is parked
     * rather than patched.
     *
     * Nothing else moved: the entities, controllers, routes, templates and tests all stay, and
     * `/admin/bundles/procurement/rfqs/{id}` still resolves, so an RFQ already out with vendors is
     * finished through its own URL instead of 404-ing because a menu was tidied. `#662` puts the
     * row back when per-line award exists. Do not renumber the group to close the gap — -905 is
     * where the row goes back.
     */
    private const SCREENS = [
        ['vendors.all', 'All Vendors', 'admin_bundle_procurement_vendors', -1000],
        ['vendors.prices', 'Vendor Prices', 'admin_bundle_procurement_vendor_prices', -990],
        ['vendors.sheet_import', 'Vendor Sheet Import', 'admin_bundle_procurement_vendor_sheet_import_index', -985],
        ['purchases.orders', 'Purchase Orders', 'admin_bundle_procurement_purchase_orders', -900],
        ['purchases.order_new', 'New PO', 'admin_bundle_procurement_purchase_order_new', -890],
        ['purchases.expected_arrivals', 'Expected Arrivals', 'admin_bundle_procurement_expected_arrivals', -880],
        ['warehouse.receiving', 'Receiving', 'admin_bundle_procurement_receipts', -870],
        ['warehouse.receipt_new', 'New Goods Receipt', 'admin_bundle_procurement_receive', -860],
        ['purchases.bills', 'Bills', 'admin_bundle_procurement_bills', -850],
        ['purchases.bill_new', 'New Bill', 'admin_bundle_procurement_bill_new', -840],
        // Directly above Debit Memos: the return and the memo are the two halves of one event —
        // the goods go back, the money comes back — so they read together, cause first. -837 keeps
        // the deliberate gaps either side rather than renumbering the group.
        ['purchases.vendor_returns', 'Vendor Returns', 'admin_bundle_procurement_vendor_returns', -837],
        ['purchases.debit_memos', 'Debit Memos', 'admin_bundle_procurement_debit_memos', -835],
        ['purchases.exceptions', 'Exceptions', 'admin_bundle_procurement_exceptions', -830],
        // Beside Exceptions because the two answer halves of one question: those say which bills are
        // wrong, this says which are old.
        ['purchases.ap_aging', 'AP Aging', 'admin_bundle_procurement_bill_aging', -825],
        ['purchases.home', 'Procurement Home', 'admin_bundle_procurement_index', -820],
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

    /** Groups first: AdminMenuTreeBuilder validates a child's parent against the nodes merged so
     *  far, and `group => true` with no route is what makes a node render as a group at all. */
    public function getCustomItems(): array
    {
        $items = [self::WAREHOUSE_GROUP];

        foreach (self::GROUPS as $key => [$label, $order, $prefixes, $icon]) {
            $items[] = ['key' => $key, 'label' => $label, 'url' => '', 'parent' => null, 'order' => $order, 'group' => true, 'routePrefixes' => $prefixes, 'icon' => $icon];
        }

        foreach (self::SCREENS as [$key, $label, $route, $order]) {
            $item = ['key' => $key, 'label' => $label, 'url' => '', 'parent' => strstr($key, '.', true), 'order' => $order, 'route' => $route];

            // Three optional fields, and absent for everything not listed — which is why every
            // other entry here renders byte for byte the row it always did.
            if (isset(self::ROW_AFFORDANCES[$key])) {
                [$attachTo, $icon, $accessibleName] = self::ROW_AFFORDANCES[$key];
                $item['attachTo'] = $attachTo;
                $item['affordanceIcon'] = $icon;
                if ($accessibleName !== null) {
                    $item['accessibleName'] = $accessibleName;
                }
            }

            $items[] = $item;
        }

        return $items;
    }
}
