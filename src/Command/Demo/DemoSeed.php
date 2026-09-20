<?php

declare(strict_types=1);

namespace App\Command\Demo;

/**
 * The tags that make demo data identifiable, and therefore removable.
 *
 * ## Why tags rather than a `demo` flag column
 *
 * Every row these seeders write is an ordinary row. There is no `is_demo` column anywhere in this
 * schema and adding one would be the wrong shape twice over: it would push a fixture concern into
 * production tables, and it would be a second answer to "where did this row come from" next to the
 * one the schema already has — `product_core.sync_source`, which exists precisely to name the feed
 * that owns a row (see App\EventSubscriber\ProductSyncSourceGuard). So the seeders reuse columns the
 * app already has, and this class is the single place that states which values mean "demo".
 *
 * The convention predates this file. The first demo seeder (2026-08-09, since bit-rotted away)
 * tagged companies with `code LIKE 'DEMO-%'` and products with `sync_source = 'demo-seed'`. Both are
 * kept verbatim so a database seeded by that script is still recognised by this one. The rest of the
 * tags are new and follow the same rule: a column an admin can see, carrying a value an admin can
 * read.
 *
 * ## Why the foreign keys make this necessary rather than merely tidy
 *
 * `company` and `product_core` are referenced by `sales_order`, `invoice` and `estimate` with
 * `ON DELETE NO ACTION`. That is deliberate on the application's part — an accounting document must
 * not vanish because someone tidied a customer record — and it means demo cleanup can never be one
 * `DELETE FROM company`. DemoDataCleaner owns that ordering problem; this class owns only the
 * predicates it deletes by.
 */
final class DemoSeed
{
    /**
     * `company.code` prefix. The admin company list shows the code, so a demo account is obvious on
     * screen as well as greppable in SQL.
     */
    public const COMPANY_CODE_PREFIX = 'DEMO-';

    /**
     * `product_core.sync_source`. Not a display column, but the one column in this schema whose
     * documented job is naming the owner of a row — and setting it also stops ProductSyncSourceGuard
     * writing an error_log row for every product these seeders create.
     */
    public const PRODUCT_SYNC_SOURCE = 'demo-seed';

    /**
     * `vendor.account_number` prefix. A vendor has no code column; the account number is the field an
     * admin recognises a supplier by, and it is shown on the vendor screen.
     */
    public const VENDOR_ACCOUNT_PREFIX = 'DEMO-';

    /**
     * Name prefix for the rows that are shared infrastructure rather than documents: fulfillment
     * regions, warehouses, price lists and tracking policies.
     *
     * These four are why the tag is a visible NAME prefix rather than something hidden. A reviewer
     * opening Settings has to be able to tell at a glance which region is theirs and which one a
     * seeder invented, because deleting the wrong one takes stock with it.
     */
    public const SHARED_NAME_PREFIX = 'Demo ';

    /**
     * What the seeders write into `transfer_order.notes` and `pick_list.notes`.
     *
     * Those two documents hang off a warehouse and carry no code of their own, so the note is the
     * tag. It is a full sentence rather than a marker because it is displayed: an admin reading a
     * transfer wants to know why it exists, and "this is demo data" is the honest answer.
     */
    public const DOCUMENT_NOTE = 'Seeded by the demo-seed fixtures. Safe to delete.';

    /** The actor every seeded document action is attributed to. */
    public const ACTOR_LABEL = 'Demo data seeder';

    /*
     * SQL predicate fragments, so the cleaner and the "is this database already seeded?" probe
     * cannot drift apart. They are fragments rather than whole statements because the same
     * predicate is needed both standalone and inside an IN (SELECT ...) subquery.
     *
     * They carry literals rather than placeholders on purpose: a LIKE pattern bound as a parameter
     * reads identically but cannot be pasted into a psql/sqlite session to check what it matches,
     * and these are exactly the strings someone will want to paste when a cleanup goes wrong. The
     * literals are constants in this file and never come from input.
     */
    public const WHERE_COMPANY = "code LIKE 'DEMO-%'";
    public const WHERE_PRODUCT = "sync_source = 'demo-seed'";
    public const WHERE_VENDOR = "account_number LIKE 'DEMO-%'";
    public const WHERE_SHARED_NAME = "name LIKE 'Demo %'";
    public const WHERE_DOCUMENT_NOTE = "notes LIKE '%demo-seed fixtures%'";

    /**
     * The demo products the WAREHOUSE seeder owns, as opposed to the sell seeder's.
     *
     * Both carry the same `sync_source`, because both are demo products and the tag says who wrote
     * the row, not which command did. What separates them is the thing that actually differs: the
     * dimensional ones are the ones whose stock the depth layer maintains, and they are the ones
     * `app:seed-warehouse-data --force` has to take with it — a purchase order cannot be deleted out
     * from under the product it ordered.
     */
    public const WHERE_DIMENSIONAL_PRODUCT = "sync_source = 'demo-seed' AND inventory_mode = 'dimensional'";

    /**
     * Sales orders the warehouse seeder raised so its pick lists would have something to pick.
     *
     * They are ordinary demo orders and the sell-side purge would take them anyway; this exists so
     * that the warehouse purge can take them on its own, since it must — their lines name products
     * it is about to delete, and `sales_order_line -> product_core` is ON DELETE NO ACTION.
     */
    public const WHERE_WAREHOUSE_ORDER = "special_instructions LIKE '%demo-seed fixtures%'";

    private function __construct()
    {
    }
}
