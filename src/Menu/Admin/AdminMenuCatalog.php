<?php

declare(strict_types=1);

namespace App\Menu\Admin;

/**
 * The canonical list of admin sidebar entries, and (via defaultTree()) the default tree built
 * from them — route, icon, parent and sidebar order lifted straight from the markup
 * templates/admin/_main/layout.html.twig used to hand-type.
 *
 * Keys name what the item is, never where it sits, so reordering or reparenting via an
 * AdminMenuOverrideProviderInterface cannot silently re-point a saved override at a different
 * link. A group and its children share a key prefix only because the child genuinely belongs to
 * that group in the default tree; hiding/reordering/reparenting a group's key does not
 * automatically apply to its children, except that a hidden group's descendants are never shown
 * either, since there is nowhere left to render them (see AdminMenuTreeBuilder).
 *
 * defaultTree() intentionally omits the sidebar's dynamically-sourced sections (bundle-contributed
 * Shipping/Fees/Tax/Payments/injection-point entries under Apps, and the Technical Docs file
 * listing) — those were never named here, come and go with installed bundles, and are rendered
 * exactly as before by templates/admin/_main/layout.html.twig alongside the tree-driven entries.
 *
 * App\Tests\Menu\Admin\AdminMenuCatalogTest keeps ITEMS and defaultTree() honest against each
 * other and against the template's set of tree-driven keys.
 */
final class AdminMenuCatalog
{
    /**
     * key => label, in sidebar order. Labels match the nav text so the toggle screen and the
     * sidebar name the same thing.
     */
    public const ITEMS = [
        'dashboard' => 'Dashboard',

        'products' => 'Products',
        'products.details' => 'Product Details',
        'products.pricing' => 'Product Pricing',
        'products.inventory' => 'Product Inventory',
        // The backorder fulfilment queue (#548). Beside Product Inventory rather than under
        // Orders: it is worked from the stock side — an admin comes here because stock
        // arrived, not because an order changed.
        'products.backorders' => 'Backorders',
        'products.import' => 'Product Import',
        'products.pricing_groups' => 'Pricing Groups',
        'products.company_pricing' => 'Customer Pricing',
        'products.categories' => 'Product Categories',
        // #573. Beside the product screens rather than under Settings: it is a statement about a
        // product's stock — what identity a unit carries — and it is set from the product form.
        'products.tracking_policies' => 'Tracking Policies',
        // #601 phase 1 (#643). Under Products for the same reason Tracking Policies is: the base
        // unit is what a product's numbers mean, and it is set from the product form. It is the
        // ONE screen the measurement system needs since #659: the terms and their ratios are here,
        // and which of them a product may use is a tick list on the product form. "Packaging Units"
        // stood beside it until then and was the second place a conversion could be defined.
        'products.units_of_measure' => 'Units of Measure',
        'products.add' => 'Product Add',

        // One Sales group, consolidating what used to be four separate top-level entries
        // (Customers, Carts, Quotes, Orders) — every one of them a sell-side document or the
        // customers/carts that precede one, so the split read as organizational accident rather
        // than a real boundary. The group's own children keep each document's own create shortcut
        // as a `+` (ROW_AFFORDANCES below) rather than a second, separate row for it — see that
        // constant's docblock for why the row-plus-icon duplication doesn't come back here.
        'sales' => 'Sales',
        // #669 sidebar follow-up: dropped the "List " prefix once the row also carries a `+` for
        // Add Customer — the row is no longer only the list, and "List Customers" undersold what
        // it does now. Every row a create item attaches to gets the same treatment below.
        'sales.customers' => 'Customers',
        'sales.customers_add' => 'Add Customer',

        'sales.quotes' => 'Quotes',
        'sales.quotes_create' => 'Create Quote',

        // "Sales Orders", not "Orders": this group also holds Purchase Orders' sibling document
        // one level up (ProcurementBundle's own Purchases group), and a sidebar with two rows both
        // called "Orders" is exactly the ambiguity Create Order's own accessible name below was
        // already written to avoid on the icon — the row itself should not need the same fix.
        'sales.orders' => 'Sales Orders',
        'sales.orders_create' => 'Create Order',
        // #539 stage 6. Beside the orders rather than a group of its own: an invoice is raised from
        // an order and read beside one, and a second top-level group for a document with one grid
        // would put more distance between them than the workflow has.
        'sales.invoices' => 'Invoices',
        // The invoice's own Create entry, beside its list — added once admin_invoice_create grew a
        // standalone path (a company picker, same as Create Order/Create Quote), which is what the
        // rule below tests for. The comment above this constant used to say invoices could not have
        // one; that stopped being true and the sidebar had simply not caught up.
        'sales.invoices_create' => 'Create Invoice',
        // #586, beside the invoices for the same reason they sit beside the orders: a credit note is
        // raised from an invoice and read next to one. It is not under Settings either — Credit Memo
        // Types belongs there because it is configuration, and this is the document.
        'sales.credit_notes' => 'Credit Notes',
        // The credit note's own Create entry, beside its list.
        //
        // The rule this follows, stated because the sidebar had drifted into looking arbitrary: a
        // document gets a Create entry when a blank one can be RAISED FROM NOTHING — when the
        // screen behind it can start from an empty form and ask for whatever it needs, the
        // customer included. Quotes and Orders have one because their create pages pick a company,
        // and Invoices now does too (see sales.invoices_create above). Credit notes qualify the
        // moment admin_credit_memo_new does the same, and #586 is explicit that a standalone credit
        // — goodwill, a pricing correction, credit raised before anyone decides which invoice it
        // lands against — is a first-class case rather than a degraded one.
        //
        // What does NOT get one is a document that can only exist by CONVERSION from another. Sales
        // returns are that shape today. The test of the rule is not "is there a create route" — it
        // is "can that route answer a GET with an empty query".
        'sales.credit_notes_create' => 'Create Credit Note',
        // #596, after the credit notes and before the carts. A sales return is the document a
        // credit note is often raised FROM, so it reads in the order the workflow happens: the
        // order, what was billed, what came back, what was credited. It is not under a warehouse
        // group either — receiving one moves stock, but the document is a customer document and the
        // person who raises it is the person who talks to the customer.
        'sales.sales_returns' => 'Sales Returns',
        // Last in the group: a cart precedes an order rather than following one, but it is also
        // the lowest-commitment of everything here — nothing is owed, raised, or owned yet — so it
        // reads better as the group's tail than wedged in front of the documents that follow from it.
        'sales.carts' => 'Carts',

        'users' => 'Users',
        'users.staff' => 'Staff Users',
        'users.customers' => 'Customer Users',

        'settings' => 'Settings',
        'settings.config' => 'Config',
        'settings.base_currency' => 'Base Currency',
        'settings.branding' => 'Branding',
        'settings.company_info' => 'Company Information',
        'settings.registration' => 'Registration Settings',
        'settings.document_prefixes' => 'Document Prefixes',
        'settings.email_templates' => 'Email Templates',
        'settings.payment_terms' => 'Payment Terms',
        'settings.credit_memo_types' => 'Credit Memo Types',
        'settings.sales_tax' => 'Sales Tax',
        'settings.shipping_zones' => 'Shipping Zones',
        'settings.warehouses' => 'Warehouses',
        'settings.fulfillment_regions' => 'Fulfillment Regions',
        'settings.guest_fulfillment_regions' => 'Guest Fulfillment Regions',
        'settings.custom_fields' => 'Custom Fields',
        'settings.redirection' => 'Redirection',

        // The Apps group's bundle-contributed entries (Shipping/Fees/Tax/Payments/injection
        // points) are not listed: they already have a per-bundle on/off on App Management, and
        // they come and go with the bundles themselves, so they have no stable place here.
        'apps' => 'Apps',
        'apps.all_apps' => 'All Apps',
        'apps.frontend_menu' => 'Frontend Menu',
        // The core connector directory (#741) — every connector type is itself a bundle, so this
        // sits beside All Apps rather than getting its own top-level group for what is, for now, a
        // single link with nothing under it yet.
        'apps.connectors' => 'Connectors',

        'system' => 'System',
        'system.onboarding' => 'Onboarding',
        'system.email_log' => 'Email Log',
        'system.error_log' => 'Error Log',
        'system.audit_log' => 'Audit Log',
        'system.database_console' => 'Database Console',
        'system.mail_queue' => 'Mail Queue',
        // The unified admin view over every import run of any type (docs/plans/2026-09-18-
        // unified-import-framework.md) — beside Mail Queue for the same reason: an operator's
        // view onto a background queue, not a per-feature progress page any more.
        'system.import_log' => 'Import Log',

        'technical_docs' => 'Technical Docs',

        'help' => 'Help',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::ITEMS);
    }

    public static function label(string $key): string
    {
        return self::ITEMS[$key] ?? $key;
    }

    /** The group key a sub-entry belongs to, or null for a top-level entry or a group itself. */
    public static function groupOf(string $key): ?string
    {
        $dot = strpos($key, '.');

        return $dot === false ? null : substr($key, 0, $dot);
    }

    /**
     * Route name for every key that links directly to a page. Absent for the seven group-only
     * top-level keys (products, sales, users, settings, apps, system, technical_docs), which
     * render as a `<div class="nav-group">`, never an `<a>`, and for "dashboard"/"help" which do
     * have a route but are single links, not groups.
     */
    private const ROUTES = [
        'dashboard' => 'admin_dashboard',

        'products.details' => 'admin_product_detail_index',
        'products.pricing' => 'admin_product_price_index',
        'products.inventory' => 'admin_inventory_index',
        'products.backorders' => 'admin_backorder_index',
        'products.import' => 'admin_product_import_index',
        'products.pricing_groups' => 'admin_price_list_index',
        'products.company_pricing' => 'admin_company_fulfillment_region_index',
        'products.categories' => 'admin_category_index',
        'products.tracking_policies' => 'admin_tracking_policy_index',
        'products.units_of_measure' => 'admin_product_unit_of_measure_index',
        'products.add' => 'admin_product_inventory_create',

        'sales.customers' => 'admin_company_index',
        'sales.customers_add' => 'admin_company_create',

        'sales.quotes' => 'admin_estimate_index',
        'sales.quotes_create' => 'admin_estimate_create',

        'sales.orders' => 'admin_order_index',
        'sales.invoices' => 'admin_invoice_index',
        'sales.invoices_create' => 'admin_invoice_create',
        'sales.credit_notes' => 'admin_credit_memo_index',
        'sales.credit_notes_create' => 'admin_credit_memo_new',
        'sales.sales_returns' => 'admin_sales_return_index',
        'sales.orders_create' => 'admin_order_create',

        'sales.carts' => 'admin_cart_index',

        'users.staff' => 'admin_user_staff_index',
        'users.customers' => 'admin_user_customer_index',

        'settings.config' => 'admin_settings',
        'settings.base_currency' => 'admin_base_currency',
        'settings.branding' => 'admin_branding',
        'settings.company_info' => 'admin_company_info',
        'settings.registration' => 'admin_registration_settings',
        'settings.document_prefixes' => 'admin_document_prefixes',
        'settings.email_templates' => 'admin_email_template',
        'settings.payment_terms' => 'admin_payment_terms',
        'settings.credit_memo_types' => 'admin_credit_memo_types',
        'settings.sales_tax' => 'admin_sales_tax',
        'settings.shipping_zones' => 'admin_shipping_zones',
        'settings.warehouses' => 'admin_warehouse',
        'settings.fulfillment_regions' => 'admin_fulfillment_region',
        'settings.guest_fulfillment_regions' => 'admin_guest_fulfillment_regions',
        'settings.custom_fields' => 'admin_custom_field_index',
        'settings.redirection' => 'admin_redirect_index',

        'apps.all_apps' => 'admin_bundle_management_index',
        'apps.frontend_menu' => 'admin_frontend_menu_management_index',
        'apps.connectors' => 'admin_connectors_index',

        'system.onboarding' => 'admin_onboarding',
        'system.email_log' => 'admin_email_log',
        'system.error_log' => 'admin_error_log',
        'system.audit_log' => 'admin_audit_log',
        'system.database_console' => 'admin_db_console',
        'system.mail_queue' => 'admin_messenger_queue',
        'system.import_log' => 'admin_import_log',

        'technical_docs' => 'admin_technical_docs_view',

        'help' => 'admin_help_index',
    ];

    /** Only Product Add carries a query param today. @var array<string, array<string, mixed>> */
    private const ROUTE_PARAMS = [
        'products.add' => ['redirect' => 'admin_product_detail_index'],
    ];

    /**
     * Route-name prefixes (the template's `route starts with '...'` checks) for the handful of
     * entries a bare `route == '...'` can't cover — several distinct routes belonging under one
     * entry (Product Inventory, Product Import, Redirection, Email Templates, Customer Pricing,
     * Custom Fields, Staff/Customer Users, Carts). Every other key matches by exact route equality.
     *
     * @var array<string, list<string>>
     */
    private const ROUTE_PREFIXES = [
        // Product Add renders only as the `+` on this row now (ROW_AFFORDANCES below), so its own
        // route has to highlight THIS row directly or nothing highlights at all while an admin is
        // on it — there is no longer a second, Product-Add-only row for admin_product_inventory_
        // create to light up on its own.
        'products.details' => ['admin_product_detail_index', 'admin_product_inventory_create'],
        'products.inventory' => ['admin_inventory_'],
        // The queue index and the per-order release screen both highlight this one entry.
        'products.backorders' => ['admin_backorder_'],
        'products.import' => ['admin_product_import_'],
        'products.company_pricing' => ['admin_company_fulfillment_region_'],
        // The list, the edit form (same route with ?edit=) and the two POST endpoints all highlight
        // this one entry, the way Redirection and Email Templates do.
        'products.tracking_policies' => ['admin_tracking_policy_'],
        // The list, the ?edit= form and the two POST endpoints all highlight the one entry, the way
        // Tracking Policies does.
        'products.units_of_measure' => ['admin_product_unit_of_measure_'],
        'users.staff' => ['admin_user_staff_'],
        'users.customers' => ['admin_user_customer_'],
        // Add Customer renders only as the `+` here now — same reasoning as Product Add above.
        'sales.customers' => ['admin_company_index', 'admin_company_create'],
        // Create Quote renders only as the `+` here now — same reasoning as Product Add above.
        'sales.quotes' => ['admin_estimate_index', 'admin_estimate_create'],
        // Create Order renders only as the `+` here now — same reasoning as Product Add above.
        'sales.orders' => ['admin_order_index', 'admin_order_create'],
        // Every admin_invoice_* screen — the grid, the detail page, the two documents, the payments
        // screen, and (since Create Invoice renders only as the `+` here now) the create screen too
        // — highlights this one entry, the way Carts covers admin_cart_*.
        'sales.invoices' => ['admin_invoice_'],
        // Every admin_credit_memo_* screen — the grid, the draft editor of an EXISTING note, the
        // detail page and the POST endpoints — highlights this one entry, the way Invoices covers
        // admin_invoice_*. admin_credit_memo_type* is deliberately NOT covered: those are the
        // Settings screens for the classification, and they highlight their own entry there.
        //
        // admin_credit_memo_new is back on this list: it left when the Create Credit Note item
        // arrived as a second, sibling ROW, because a route in both lists would have lit two
        // siblings at once. Create Credit Note renders only as the `+` here now, not a sibling row,
        // so there is only one row left to light up — leaving admin_credit_memo_new off this list
        // would highlight nothing at all while creating one, which is worse than the collision this
        // originally avoided.
        'sales.credit_notes' => ['admin_credit_memo_index', 'admin_credit_memo_new', 'admin_credit_memo_detail', 'admin_credit_memo_edit', 'admin_credit_memo_action', 'admin_credit_memo_apply', 'admin_credit_memo_refund'],
        // Every admin_sales_return_* screen highlights this one entry. A blanket prefix is safe here
        // in a way it is not for the credit note: there is no `sales_return` settings screen for it
        // to swallow, because the RMA has no configured classification table behind it.
        'sales.sales_returns' => ['admin_sales_return_'],
        // The one entry in this group that is a single link rather than a document with a create
        // shortcut — same reasoning Product Inventory/Product Import/etc. above get for a route
        // this catalog otherwise has no other name for.
        'sales.carts' => ['admin_cart_'],
        'settings.config' => ['admin_setting'],
        // admin_warehouse, admin_warehouse_create and admin_warehouse_update all highlight the one
        // entry — the same treatment Email Templates and Redirection get.
        'settings.warehouses' => ['admin_warehouse'],
        'settings.email_templates' => ['admin_email_template'],
        'settings.custom_fields' => ['admin_custom_field'],
        'settings.redirection' => ['admin_redirect'],
        'apps.frontend_menu' => ['admin_frontend_menu_management'],
        // The list and every per-run detail page (admin_import_log_detail) highlight this one
        // entry, the way Redirection and Email Templates do. Kill/process-queue are POST-only
        // actions that redirect straight back to the list, so they never render on their own.
        'system.import_log' => ['admin_import_log'],
    ];

    /**
     * The four entries the hardcoded template wraps in `{% if is_granted('ROLE_TECH_SUPPORT') %}`
     * on top of their admin_menu_enabled() guard. Purely a rendering detail carried over
     * unchanged from the old template — not something an override provider can grant or revoke,
     * since this is a genuine access-control-adjacent check, not the "hide a link" kind.
     *
     * @var array<string, string>
     */
    private const REQUIRES_ROLE = [
        'apps.all_apps' => 'ROLE_TECH_SUPPORT',
        'system.error_log' => 'ROLE_TECH_SUPPORT',
        'system.database_console' => 'ROLE_TECH_SUPPORT',
        'system.mail_queue' => 'ROLE_TECH_SUPPORT',
    ];

    /**
     * The create entries that render as a `+` on the right of their own list row instead of a
     * second, separate row of their own (queue item 35): key => [attachTo, icon name, accessible
     * name or null].
     *
     * NOT additive any more. It was, at first — each flagged item kept the sidebar row it already
     * had, so a list row grew an icon and nothing else changed. That put the same destination on
     * the sidebar twice: "List Orders" as its own row AND a `+` beside it that goes to Create Order,
     * which reads as two links for one workflow rather than one link with a shortcut on it. So a
     * flagged item now renders ONLY as the icon (AdminMenuTreeBuilder::assemble() drops its own row
     * once it has successfully attached) — which is also why every row a flag attaches to had its
     * label's "List " prefix dropped in ITEMS above: "List Orders" undersold the row once it was
     * also the `+` destination's home, "Orders" does not.
     *
     * The fallback is unchanged: a flag naming a target that does not resolve, or that an admin has
     * hidden, keeps its own row — see assertAffordanceTargetsExist()/reportUnresolvableAffordances()
     * and the "additive" tests in AdminMenuTreeBuilderTest that cover exactly that case. Removing an
     * entry from this map puts the item back to a plain row with no icon anywhere, same as before
     * this feature existed.
     *
     * Each names its parent row by KEY. Not "the row above me": order here is numeric, bundles
     * inject into these same groups and App Management can reorder any of it, so a positional rule
     * would eventually attach Create Order to something else and look entirely correct doing it.
     * A name that stops resolving draws no icon and logs why, which is the failure you can find.
     *
     * A stated accessible name only where the label alone would be wrong for somebody who lands on
     * the icon with no row around it:
     *  - Product Add is a screen name in the wrong order, and the app's own quick-create menu
     *    already calls the thing "New Product".
     *  - "Create Order" is genuinely ambiguous in a sidebar that also has Purchase Orders. Sales is
     *    what this one makes, and the print screen already says so.
     * Create Quote, Create Invoice, Create Credit Note and Add Customer each name one unambiguous
     * thing and are left to speak for themselves, which is what makes the field worth having as an
     * option rather than a requirement.
     *
     * @var array<string, array{string, string, ?string}>
     */
    private const ROW_AFFORDANCES = [
        'products.add' => ['products.details', 'plus', 'New Product'],
        'sales.customers_add' => ['sales.customers', 'plus', null],
        'sales.quotes_create' => ['sales.quotes', 'plus', null],
        'sales.invoices_create' => ['sales.invoices', 'plus', null],
        'sales.credit_notes_create' => ['sales.credit_notes', 'plus', null],
        // Its row now says "Sales Orders", not "Orders" (see ITEMS above) — but the icon itself is
        // reached with no row context around it, so it keeps stating the fuller "Create Sales
        // Order" as its accessible name; a screen reader landing on a bare icon still has to be
        // told what it is without leaning on a row label that is not being read.
        'sales.orders_create' => ['sales.orders', 'plus', 'Create Sales Order'],
    ];

    /**
     * Raw inline SVG markup, verbatim from the template, keyed by top-level entry.
     *
     * Raw markup here and a NAME in ROW_AFFORDANCES above is not an inconsistency, though it reads
     * like one. This constant is core's own, unreachable from any UI. An affordance icon is not:
     * /admin/bundles/admin-menu/custom/create lets a person create a sidebar item, and those specs
     * reach App\Menu\Admin\AdminMenuTreeBuilder by the same path a bundle's do — so a raw-SVG
     * field on that form would be user-supplied markup rendered unescaped into every admin page.
     * A name cannot be. See App\Menu\Admin\AdminMenuIconSet.
     */
    private const ICONS = [
        'dashboard' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
        'products' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.27 6.96 8.73 5.04 8.73-5.04"/><path d="M12 22.08V12"/></svg>',
        // One icon for the consolidated Sales group (Customers/Quotes/Orders/Invoices/Credit
        // Notes/Sales Returns/Carts) — the bag-and-receipt glyph that used to be Orders' alone,
        // since a sale is what every one of those documents is a step toward or a record of.
        'sales' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>',
        'users' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'settings' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.1a2 2 0 0 1-1-1.72v-.51a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>',
        'apps' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
        'system' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
        'technical_docs' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M9 15h6"/><path d="M9 11h1"/></svg>',
        'help' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    ];

    /**
     * The seven group containers' own "is this group open" test — deliberately its own hardcoded
     * expression per group in the old template, not simply the union of its children's own
     * highlight tests: Apps in particular opens for ANY `admin_bundle_*` route (e.g. a specific
     * bundle's own config screen reached via an injection-point link, never one of this catalog's
     * two static Apps children), not just its two static children's exact routes. Reproduced
     * verbatim here rather than derived, so that breadth survives the refactor unchanged.
     *
     * @var array<string, array{exact: list<string>, prefix: list<string>}>
     */
    private const GROUP_MATCH = [
        'products' => ['exact' => [], 'prefix' => ['admin_product_', 'admin_price_list_', 'admin_category_', 'admin_company_fulfillment_region_', 'admin_inventory_', 'admin_backorder_']],
        // The union of what were four separate groups' own matches (Customers/Carts/Quotes/Orders)
        // before they consolidated into Sales — nothing here narrowed, so a page that used to open
        // one of those four still opens Sales today.
        // admin_credit_memo_ is listed route by route rather than as one prefix, on purpose:
        // `admin_credit_memo_types` and its siblings are the SETTINGS screens for the
        // classification, and a blanket `admin_credit_memo_` prefix here would pull them into the
        // Sales group and leave the Settings group closed on its own page.
        'sales' => [
            'exact' => ['admin_credit_memo_index', 'admin_credit_memo_new', 'admin_credit_memo_detail', 'admin_credit_memo_edit', 'admin_credit_memo_action', 'admin_credit_memo_apply', 'admin_credit_memo_application_withdraw', 'admin_credit_memo_refund', 'admin_credit_memo_refund_delete'],
            'prefix' => ['admin_company_', 'admin_estimate_', 'admin_order_', 'admin_invoice_', 'admin_sales_return_', 'admin_cart_'],
        ],
        'users' => ['exact' => [], 'prefix' => ['admin_user_']],
        'settings' => [
            'exact' => ['admin_base_currency', 'admin_warehouse', 'admin_fulfillment_region', 'admin_guest_fulfillment_regions', 'admin_payment_terms', 'admin_credit_memo_types', 'admin_sales_tax', 'admin_shipping_zones', 'admin_registration_settings', 'admin_branding', 'admin_company_info', 'admin_document_prefixes'],
            'prefix' => ['admin_setting', 'admin_email_template', 'admin_custom_field', 'admin_redirect', 'admin_warehouse'],
        ],
        'apps' => ['exact' => [], 'prefix' => ['admin_bundle_', 'admin_frontend_menu_management']],
        'system' => ['exact' => ['admin_onboarding', 'admin_email_log', 'admin_error_log', 'admin_audit_log'], 'prefix' => ['admin_db_', 'admin_messenger_', 'admin_import_log']],
        'technical_docs' => ['exact' => ['admin_technical_docs_view'], 'prefix' => []],
    ];

    /**
     * The default admin sidebar tree, one App\Menu\Admin\AdminMenuNode per catalog entry, in
     * catalog order with catalog order preserved as each node's `order` (spaced by 10 so an
     * override can slot something between two defaults without renumbering the rest).
     *
     * This is the tree App\Twig\AdminMenuExtension::adminMenuTree() starts from before merging any
     * active AdminMenuOverrideProviderInterface's overrides on top — with none active (or none
     * registered, i.e. modules/AdminMenuBundle deleted), this is the whole tree, unchanged.
     *
     * @return list<AdminMenuNode>
     */
    public static function defaultTree(): array
    {
        $nodes = [];
        $order = 0;

        foreach (self::ITEMS as $key => $label) {
            $order += 10;
            $route = self::ROUTES[$key] ?? null;

            if (isset(self::GROUP_MATCH[$key])) {
                $exact = self::GROUP_MATCH[$key]['exact'];
                $prefixes = self::GROUP_MATCH[$key]['prefix'];
            } else {
                $exact = $route !== null && !isset(self::ROUTE_PREFIXES[$key]) ? [$route] : [];
                $prefixes = self::ROUTE_PREFIXES[$key] ?? [];
            }

            [$attachTo, $affordanceIcon, $accessibleName] = self::ROW_AFFORDANCES[$key] ?? [null, null, null];

            $nodes[] = new AdminMenuNode(
                key: $key,
                label: $label,
                route: $route,
                icon: self::ICONS[$key] ?? null,
                parent: self::groupOf($key),
                order: $order,
                requiresRole: self::REQUIRES_ROLE[$key] ?? null,
                routeParams: self::ROUTE_PARAMS[$key] ?? [],
                exactRoutes: $exact,
                routePrefixes: $prefixes,
                attachTo: $attachTo,
                affordanceIcon: $affordanceIcon,
                accessibleName: $accessibleName,
            );
        }

        return $nodes;
    }
}
