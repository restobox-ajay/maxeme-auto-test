<?php

declare(strict_types=1);

namespace App\Command\Demo;

use Doctrine\DBAL\Connection;

/**
 * Removes everything the two demo seeders wrote, in the one order the foreign keys permit.
 *
 * ## Why this is SQL and not `EntityManager::remove()`
 *
 * Everywhere else in this codebase, "go through the app, not around it" means calling the entity's
 * own named action. There is no named action for *un-seeding*: no controller deletes a company that
 * has orders against it, and no service deletes a product that has been sold. The application has
 * no opinion on this operation because the application never performs it — it exists only because a
 * fixture has to be re-runnable.
 *
 * Doing it through the ORM would also be worse rather than better. `remove()` on ~2,000 rows means
 * hydrating ~2,000 objects, and every one of them would pass under InventoryReconciliationSubscriber
 * and SalesOrderDerivedStatusSubscriber on the way out — recomputing sales holds and derived
 * statuses for documents that are being deleted in the same transaction. That is a lot of work to
 * arrive at an empty table, and each of those subscribers has to reach for rows that other DELETEs
 * in the same pass have already taken away.
 *
 * So this is deliberately blunt, and the discipline it keeps instead is that it only ever deletes
 * rows matched by a DemoSeed tag or reachable from one by a foreign key.
 *
 * ## The ordering problem, stated once
 *
 * `PRAGMA foreign_keys = ON` is set on every connection (App\Doctrine\SqliteWalMiddleware), so the
 * declared ON DELETE behaviour is real. Most child rows are `ON DELETE CASCADE` and need no
 * statement here. The ones that matter are the `NO ACTION` edges, because those are the ones that
 * will refuse a DELETE:
 *
 *   sales_order  -> company        NO ACTION    an order outlives a tidied customer record
 *   invoice      -> company        NO ACTION
 *   invoice      -> sales_order    NO ACTION    an invoice outlives its order (Invoice::$salesOrder)
 *   estimate     -> company        NO ACTION
 *   estimate     -> sales_order    NO ACTION    converted_order_id
 *   invoice_payment -> company     NO ACTION    money is never cascaded away (#708: company-scoped)
 *   invoice_payment_application -> invoice  NO ACTION  a claim outlives no invoice it settled
 *   sales_order_log -> sales_order NO ACTION
 *   estimate_log -> estimate       NO ACTION
 *   sales_order_line -> product_core  NO ACTION a sold line keeps naming its product
 *   estimate_line -> product_core  NO ACTION
 *   product_inventory -> warehouse NO ACTION
 *   purchase_order    -> warehouse NO ACTION
 *   goods_receipt  -> warehouse NO ACTION
 *   goods_receipt  -> inventory_movement_group  SET NULL, but see below
 *   fulfillment_region -> price_list NO ACTION    guest_price_list_id
 *   credit_memo    -> company      NO ACTION    (app:seed-demo-volume)
 *   credit_memo_application -> invoice NO ACTION
 *   credit_memo_line -> product_core NO ACTION
 *   sales_return   -> company      NO ACTION
 *   debit_memo     -> vendor       NO ACTION
 *   debit_memo_application -> vendor_bill NO ACTION
 *   vendor_return  -> vendor       NO ACTION
 *   vendor_bill_payment_application -> vendor_bill  NO ACTION  (#708, was CASCADE)
 *   rfq            -> warehouse    NO ACTION
 *   rfq_vendor_reply -> vendor / purchase_order  NO ACTION
 *
 * Everything below is that list, sorted. Each step says which edge forced its position.
 *
 * ## Two layers, because the commands are layered
 *
 * `app:seed-warehouse-data` builds on what `app:seed-demo-data` created (its warehouses, its
 * products, its companies). So `--force` on the warehouse seeder purges only the warehouse layer and
 * leaves the sell side alone, while `--force` on the demo seeder purges both — you cannot delete the
 * products out from under a purchase order.
 */
final class DemoDataCleaner
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** True when either seeder has already run against this database. */
    public function isSeeded(): bool
    {
        return $this->countOf('company', DemoSeed::WHERE_COMPANY) > 0
            || $this->countOf('product_core', DemoSeed::WHERE_PRODUCT) > 0
            || $this->countOf('vendor', DemoSeed::WHERE_VENDOR) > 0;
    }

    /** True when the warehouse/buy layer specifically has already been seeded. */
    public function isWarehouseSeeded(): bool
    {
        return $this->hasDepthLayer() || $this->hasProcurement() || $this->hasWarehouseDocuments();
    }

    /*
     * The three layer probes below exist because `app:seed-warehouse-data` is one command made of
     * three, one per bundle, and each step has to be able to refuse to run twice on its own. See
     * that command's docblock for why the steps are in the bundles rather than in core.
     */

    /** InventoryDepthBundle's step: the dimensional products and the bins. */
    public function hasDepthLayer(): bool
    {
        return $this->countOf('product_core', DemoSeed::WHERE_DIMENSIONAL_PRODUCT) > 0;
    }

    /** ProcurementBundle's step: the vendors everything else on the buy side hangs off. */
    public function hasProcurement(): bool
    {
        return $this->countOf('vendor', DemoSeed::WHERE_VENDOR) > 0;
    }

    /** WarehouseOpsBundle's step: transfers and pick rounds. */
    public function hasWarehouseDocuments(): bool
    {
        return $this->countOf('transfer_order', DemoSeed::WHERE_DOCUMENT_NOTE) > 0
            || $this->countOf('pick_list', DemoSeed::WHERE_DOCUMENT_NOTE) > 0;
    }

    /**
     * Everything `app:seed-warehouse-data` wrote: vendors and their paperwork, receipts, transfers,
     * pick lists, bins, lots, and every movement recorded against a demo product.
     *
     * @return array<string, int> rows deleted, keyed by table, in the order they were deleted
     */
    public function purgeWarehouseLayer(): array
    {
        $demoProducts = $this->in('product_core', 'id', DemoSeed::WHERE_PRODUCT);
        $demoVendors = $this->in('vendor', 'id', DemoSeed::WHERE_VENDOR);
        $demoWarehouses = $this->in('warehouse', 'id', DemoSeed::WHERE_SHARED_NAME);

        // Captured BEFORE the movements are deleted: a group is only reachable through its
        // movements, and goods_receipt.movement_group_id points at one. Collect the ids while the
        // join still exists, delete the receipts and movements, then delete the groups by id.
        $groupIds = $this->columnOf(sprintf(
            'SELECT DISTINCT group_id FROM inventory_movement WHERE product_id IN %s',
            $demoProducts,
        ));

        $deleted = [];

        // -- Debit memos first: debit_memo_application -> vendor_bill is NO ACTION, and the
        //    applications go with their memo (CASCADE). debit_memo -> vendor is NO ACTION too.
        $deleted['debit_memo'] = $this->delete('debit_memo', sprintf('vendor_id IN %s', $demoVendors));

        // -- Vendor returns, before vendors (vendor_return -> vendor is NO ACTION). Lines CASCADE.
        $deleted['vendor_return'] = $this->delete('vendor_return', sprintf('vendor_id IN %s', $demoVendors));

        // -- RFQs before purchase orders and vendors: a reply names both with NO ACTION. An RFQ
        //    reaches a vendor only through its replies, so a draft with none is matched by the note
        //    app:seed-demo-volume writes on it. Lines and replies CASCADE.
        $deleted['rfq'] = $this->delete('rfq', sprintf(
            '%s OR id IN (SELECT rfq_id FROM rfq_vendor_reply WHERE vendor_id IN %s) OR warehouse_id IN %s',
            DemoSeed::WHERE_DOCUMENT_NOTE,
            $demoVendors,
            $demoWarehouses,
        ));

        // -- Vendor payments before vendor bills: #708 added vendor_bill_payment_application ->
        //    vendor_bill as a NO ACTION edge (a claim outlives no bill it settled), where before
        //    that date vendor_bill_payment -> vendor_bill was CASCADE and needed no statement here.
        //    The pool (vendor_bill_payment) is matched through its vendor, and
        //    vendor_bill_payment_application -> vendor_bill_payment is CASCADE, so deleting the pool
        //    takes every claim against it with it and frees the bill to be deleted next.
        $deleted['vendor_bill_payment'] = $this->delete('vendor_bill_payment', sprintf('vendor_id IN %s', $demoVendors));

        // -- Vendor bills. vendor_bill_log/line are CASCADE off vendor_bill; the bill itself is
        //    matched through its vendor, which is the only tag it carries.
        $deleted['vendor_bill'] = $this->delete('vendor_bill', sprintf('vendor_id IN %s', $demoVendors));

        // -- Receipts before movement groups (goods_receipt.movement_group_id) and before
        //    warehouses (goods_receipt -> warehouse is NO ACTION).
        $deleted['goods_receipt'] = $this->delete('goods_receipt', sprintf('vendor_id IN %s', $demoVendors));

        // -- Purchase orders, likewise before warehouses.
        $deleted['purchase_order'] = $this->delete('purchase_order', sprintf('vendor_id IN %s', $demoVendors));

        // -- vendor_address is CASCADE, so the vendor row is enough.
        $deleted['vendor'] = $this->delete('vendor', DemoSeed::WHERE_VENDOR);

        // -- Warehouse operations. Both hang off a warehouse with CASCADE, but they are deleted
        //    explicitly and by their own note tag so that a re-seed does not depend on the warehouse
        //    also being torn down (purgeSellLayer is what does that, and it may not run).
        $deleted['pick_list'] = $this->delete('pick_list', sprintf(
            '%s OR warehouse_id IN %s',
            DemoSeed::WHERE_DOCUMENT_NOTE,
            $demoWarehouses,
        ));
        $deleted['transfer_order'] = $this->delete('transfer_order', sprintf(
            '%s OR from_warehouse_id IN %s OR to_warehouse_id IN %s',
            DemoSeed::WHERE_DOCUMENT_NOTE,
            $demoWarehouses,
            $demoWarehouses,
        ));

        // -- The depth layer. inventory_movement must go before inventory_detail: a movement names
        //    the two detail rows it moved between, and those FKs are CASCADE in the wrong direction
        //    for us (deleting a detail row would take unrelated movements with it).
        $deleted['inventory_movement'] = $this->delete('inventory_movement', sprintf('product_id IN %s', $demoProducts));
        $deleted['inventory_movement_group'] = $groupIds === []
            ? 0
            : $this->delete('inventory_movement_group', sprintf('id IN (%s)', implode(',', $groupIds)));
        $deleted['inventory_detail'] = $this->delete('inventory_detail', sprintf('product_id IN %s', $demoProducts));
        $deleted['inventory_lot'] = $this->delete('inventory_lot', sprintf('product_id IN %s', $demoProducts));
        $deleted['inventory_bucket_change_log'] = $this->delete(
            'inventory_bucket_change_log',
            sprintf('product_id IN %s', $demoProducts),
        );

        // -- Bins. CASCADE off warehouse, but the sell layer may not be purged in this call.
        $deleted['warehouse_location'] = $this->delete('warehouse_location', sprintf('warehouse_id IN %s', $demoWarehouses));

        // -- Per-product procurement rules and the tracking policies products point at.
        //    product_core.tracking_policy_id is ON DELETE SET NULL, so the policy can go last and the
        //    products simply stop naming it.
        $deleted['procurement_product_rule'] = $this->delete(
            'procurement_product_rule',
            sprintf('product_id IN %s', $demoProducts),
        );
        $deleted['tracking_policy'] = $this->delete('tracking_policy', DemoSeed::WHERE_SHARED_NAME);

        // -- The warehouse seeder's own sales orders, and then its own products.
        //
        //    These are the last two steps rather than the first because everything above references
        //    them, and they are in the WAREHOUSE purge at all — rather than only in the sell-side one
        //    — because a second `--force` on this command would otherwise hit
        //    `UNIQUE constraint failed: product_core.sku` re-creating a product it had left behind.
        //
        //    Order matters twice over: sales_order_log -> sales_order is NO ACTION, and
        //    sales_order_line -> product_core is NO ACTION, so the logs go before the orders and the
        //    orders go before the products. product_inventory and every depth row are CASCADE off
        //    product_core, so the product row is enough for those.
        $warehouseOrders = $this->in('sales_order', 'id', DemoSeed::WHERE_WAREHOUSE_ORDER);
        $deleted['sales_order_log (warehouse)'] = $this->delete('sales_order_log', sprintf('order_id IN %s', $warehouseOrders));
        $deleted['sales_order (warehouse)'] = $this->delete('sales_order', DemoSeed::WHERE_WAREHOUSE_ORDER);
        $deleted['product_core (dimensional)'] = $this->delete('product_core', DemoSeed::WHERE_DIMENSIONAL_PRODUCT);

        return array_filter($deleted, static fn (int $rows): bool => $rows > 0);
    }

    /**
     * Everything `app:seed-demo-data` wrote, plus the warehouse layer that stands on it.
     *
     * @return array<string, int> rows deleted, keyed by table, in the order they were deleted
     */
    public function purgeAll(): array
    {
        $deleted = $this->purgeWarehouseLayer();

        $demoCompanies = $this->in('company', 'id', DemoSeed::WHERE_COMPANY);
        $demoProducts = $this->in('product_core', 'id', DemoSeed::WHERE_PRODUCT);
        $demoWarehouses = $this->in('warehouse', 'id', DemoSeed::WHERE_SHARED_NAME);
        $demoRegions = $this->in('fulfillment_region', 'id', DemoSeed::WHERE_SHARED_NAME);
        $demoPriceLists = $this->in('price_list', 'id', DemoSeed::WHERE_SHARED_NAME);

        // -- Money first: invoice_payment -> company is NO ACTION, because a payment is an
        //    accounting record and the schema refuses to let one be cascaded away behind someone's
        //    back. #708 turned the payment into a pool scoped to the company rather than to one
        //    invoice, so the pool is matched directly rather than through a subquery on its
        //    invoices — and invoice_payment_application -> invoice_payment is CASCADE, so deleting
        //    the pool takes every claim against it with it, including claims against invoices this
        //    purge has not reached yet. That is what frees invoice_payment_application -> invoice
        //    (also NO ACTION) before the invoice itself is deleted below.
        $deleted['invoice_payment'] = $this->delete('invoice_payment', sprintf('company_id IN %s', $demoCompanies));

        // -- Credit memos before invoices (credit_memo_application -> invoice is NO ACTION, and goes
        //    with its memo), before products (credit_memo_line -> product_core is NO ACTION) and
        //    before companies. Lines, addresses, applications and refunds CASCADE.
        $deleted['credit_memo'] = $this->delete('credit_memo', sprintf('company_id IN %s', $demoCompanies));

        // -- Sales returns before companies (sales_return -> company is NO ACTION). Lines CASCADE.
        $deleted['sales_return'] = $this->delete('sales_return', sprintf('company_id IN %s', $demoCompanies));

        // -- Estimates before orders (estimate.converted_order_id is NO ACTION) and their logs
        //    before them (estimate_log -> estimate is NO ACTION). estimate_line and estimate_address
        //    are CASCADE.
        $deleted['estimate_log'] = $this->delete('estimate_log', sprintf(
            'estimate_id IN (SELECT id FROM estimate WHERE company_id IN %s)',
            $demoCompanies,
        ));
        $deleted['estimate'] = $this->delete('estimate', sprintf('company_id IN %s', $demoCompanies));

        // -- Invoices before orders (invoice.sales_order_id is NO ACTION). invoice_line, invoice_log,
        //    invoice_address and invoice_inventory_reservation are all CASCADE.
        $deleted['invoice'] = $this->delete('invoice', sprintf('company_id IN %s', $demoCompanies));

        // -- Order logs before orders (sales_order_log -> sales_order is NO ACTION). Lines,
        //    addresses, order_inventory_reservation, backorder_fulfillment_entry and
        //    custom_field_value_order are CASCADE.
        $deleted['sales_order_log'] = $this->delete('sales_order_log', sprintf(
            'order_id IN (SELECT id FROM sales_order WHERE company_id IN %s)',
            $demoCompanies,
        ));
        $deleted['sales_order'] = $this->delete('sales_order', sprintf('company_id IN %s', $demoCompanies));

        // -- Stock rows before products AND before warehouses: product_inventory -> warehouse is the
        //    one NO ACTION edge on that table, which is why a demo warehouse cannot simply be
        //    dropped while any product still has a row in it.
        $deleted['product_inventory'] = $this->delete('product_inventory', sprintf(
            'product_id IN %s OR warehouse_id IN %s',
            $demoProducts,
            $demoWarehouses,
        ));

        // -- Products. product_pricing, product_image, product_fee, company_product_access and
        //    custom_field_value_product are CASCADE; sales_order_line and estimate_line were the
        //    NO ACTION references and are already gone.
        $deleted['product_core'] = $this->delete('product_core', DemoSeed::WHERE_PRODUCT);

        // -- Categories, after their products: product_core.category_id is SET NULL, so leaving them
        //    would not break anything — it would just accumulate four more of them on every re-seed,
        //    which is why they carry a name tag at all.
        $deleted['product_category'] = $this->delete('product_category', DemoSeed::WHERE_SHARED_NAME);

        // -- Customer users are SET NULL on company, so deleting the company would orphan rather than
        //    remove them. They have to be named explicitly.
        $deleted['customer_user'] = $this->delete('customer_user', sprintf('company_id IN %s', $demoCompanies));

        // -- Companies. company_address, company_note, company_fulfillment_region,
        //    company_payment_method and the custom-field values are CASCADE.
        $deleted['company'] = $this->delete('company', DemoSeed::WHERE_COMPANY);

        // -- Regions before price lists: fulfillment_region.guest_price_list_id is NO ACTION.
        //    warehouse_fulfillment_region is CASCADE off both sides.
        $deleted['fulfillment_region'] = $this->delete('fulfillment_region', DemoSeed::WHERE_SHARED_NAME);
        $deleted['warehouse'] = $this->delete('warehouse', DemoSeed::WHERE_SHARED_NAME);

        // -- Price lists last. product_pricing is CASCADE and its products are gone anyway; the
        //    NO ACTION references from fulfillment_region and company_fulfillment_region were both
        //    removed above. If this statement fails, something outside the demo set is pointing at a
        //    demo price list, and that is worth failing loudly over rather than silently orphaning.
        $deleted['price_list'] = $this->delete('price_list', DemoSeed::WHERE_SHARED_NAME);

        unset($demoRegions, $demoPriceLists);

        return array_filter($deleted, static fn (int $rows): bool => $rows > 0);
    }

    private function delete(string $table, string $where): int
    {
        return (int) $this->connection->executeStatement(sprintf('DELETE FROM %s WHERE %s', $table, $where));
    }

    private function countOf(string $table, string $where): int
    {
        return (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE %s', $table, $where));
    }

    /**
     * An `IN (SELECT ...)` fragment. Returned as SQL rather than as an id list because the demo set
     * changes size as the purge proceeds, and a subquery re-reads it at each statement where a
     * captured list would go stale.
     */
    private function in(string $table, string $column, string $where): string
    {
        return sprintf('(SELECT %s FROM %s WHERE %s)', $column, $table, $where);
    }

    /** @return list<int> */
    private function columnOf(string $sql): array
    {
        return array_map(static fn (mixed $v): int => (int) $v, $this->connection->fetchFirstColumn($sql));
    }
}
