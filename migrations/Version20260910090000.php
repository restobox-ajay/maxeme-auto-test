<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Units of measure, phase 2: widen the quantity and money columns (#601, #645).
 *
 * ```
 * quantities            INTEGER  or NUMERIC(12, 2)  ->  NUMERIC(14, 4)
 * unit prices and rates NUMERIC(12, 2) or (12, 4)   ->  NUMERIC(18, 6)
 * money TOTALS          NUMERIC(12, 2)                  UNCHANGED
 * ```
 *
 * 69 columns across 26 tables. **Not one value is written.** A widening changes what a column may
 * hold, never what it holds: every figure in every row comes back byte for byte, and the branch
 * reports `COUNT(*)` and a positional content checksum on both sides to prove it, hashed by column
 * ORDER rather than by column NAME.
 *
 * ## Why a total is not a rate
 *
 * `subtotal`, `tax`, `total`, `amount` and `amount_paid` stay at `NUMERIC(12, 2)` throughout. A
 * total is money — it is what somebody pays, and it has two decimal places because currency does.
 * A rate is a price per one base unit, and six decimals is what keeps `$10.00 per case of 12`
 * from becoming `0.83` and losing two cents on every line of 600. #601 states the operational half
 * of that rule and it is unchanged by this migration: round ONCE, at the line, then sum the
 * rounded lines.
 *
 * ## Which columns, and why more of them than #645 enumerates
 *
 * #645 names `product_inventory` (14 quantity columns), `inventory_detail`, `transfer_order_line`,
 * `pick_task` and `cart_item`, and asks for a survey. The survey found the rest of the same layer,
 * and leaving any of it behind would put the narrowing one level down from where the number is
 * written:
 *
 *   - `inventory_movement.quantity` is the ledger `product_inventory` is derived from. A wide
 *     bucket over an INTEGER ledger is a bucket that cannot be recomputed from its own history.
 *   - `inventory_bucket_change_log` and `inventory_reconciliation_discrepancy` record what those
 *     buckets held. An audit trail narrower than the thing it audits reports a value that was
 *     never stored.
 *   - `order_inventory_reservation` and `invoice_inventory_reservation` hold the same figure the
 *     buckets do, from the other side of the reconciler.
 *   - `inventory_reorder_rule` and `vendor_price.order_multiple` are quantities of stock too.
 *   - `product_inventory.manual_adjustment` is the fifteenth quantity column on that table — an
 *     adjustment in base units, not a flag, so it widens with the fourteen beside it.
 *   - the document lines already carried `NUMERIC(12, 2)` quantities, which is decimals but not
 *     enough of them: 12/3 of a case is 0.3333 and (12, 2) rounds it. #601 rejected one decimal
 *     for exactly this reason, and two is the same argument one place further along.
 *   - `product_core`'s five prices and `product_pricing.price` are the price grid #601 settles as
 *     "per that product's base unit". A per-unit price that the grid cannot express is the
 *     rounding this phase exists to make impossible.
 *
 * ## What did NOT widen, deliberately
 *
 * `sales_tax.rate` (a percentage of money, not a price per unit), `fee.default_value` and
 * `product_fee.value` (fee configuration, which #601 never brings into the unit model), and
 * `shipping_zone.free_shipping_minimum` / `delivery_fee` (a threshold and a flat charge). Every
 * `sort_order`, `sort_key`, `version` and map coordinate stays `INTEGER`: they count positions,
 * not goods.
 *
 * ## The rebuild, and the bug it walks around
 *
 * SQLite has no `ALTER COLUMN`, so a type change is the documented create-copy-drop-recreate. That
 * is the shape Version20260730150000 got wrong: **`PRAGMA foreign_keys = OFF` is silently ignored
 * inside a transaction**, so `DROP TABLE` cascaded and deleted the children of the table being
 * rebuilt while the migration exited 0. Ten of the 26 tables here are referenced by others and four
 * of those have `ON DELETE CASCADE` children — `product_core` above all, which **23 tables cascade
 * off**, from `product_pricing` and `product_inventory` down to `inventory_movement`. So this runs
 * `isTransactional(): false` with the pragma bracketing the whole thing, where it takes effect.
 * `postUp()` then runs `PRAGMA foreign_key_check` and refuses to let the migration stand if the
 * rebuild orphaned a single row.
 *
 * ## The DDL is the chain's own, not SchemaTool's
 *
 * Each `CREATE TABLE` below is the text the previous migrations produced, with only the type token
 * of the widened columns rewritten. Lifting it from `doctrine:schema:create --dump-sql` instead
 * would have reordered columns and renamed constraints on the tables that already carry the
 * index-name drift `bin/ci-migration-replay` documents — changes a widening has no business
 * making, and ones that a by-ORDER checksum would (correctly) report as a difference.
 *
 * Indexes are re-issued by name because SQLite does not carry an index across a table rebuild. The
 * lists below are `sqlite_master`'s, so an index that existed before this migration exists after
 * it, under the same name and over the same columns.
 *
 * ## sqlite_sequence
 *
 * `DROP TABLE` takes the table's `AUTOINCREMENT` high-water mark with it, and re-inserting the
 * rows only restores it as far as `MAX(id)` — so a table whose newest rows had been deleted would
 * come back ready to hand out an id it had already used. The counter is saved before the rebuilds
 * and restored after, which is also what keeps `sqlite_sequence` itself checksum-identical.
 *
 * ## Timestamp
 *
 * Above Version20260909090000 (#643's unit model), the previous end of the chain.
 */
final class Version20260910090000 extends AbstractMigration
{
    /**
     * See the class docblock: the pragma that keeps `DROP TABLE` from cascading is a no-op inside
     * a transaction, and this migration drops 26 tables, nine of them parents of cascading
     * children.
     */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return '#645: widen 69 quantity columns to NUMERIC(14, 4) and unit price/rate columns to '
            . 'NUMERIC(18, 6) across 26 tables. Money totals are unchanged and no value is written.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql('CREATE TEMPORARY TABLE __seq__645 AS SELECT name, seq FROM sqlite_sequence');

        $this->rebuild(
            'cart_item',
            'id, quantity, created_at, updated_at, cart_id, product_id, fulfillment_region_id',
            'CREATE TABLE cart_item (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, quantity NUMERIC(14, 4) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, cart_id INTEGER NOT NULL, product_id INTEGER NOT NULL, fulfillment_region_id INTEGER NOT NULL, CONSTRAINT FK_F0FE25271AD5CDBF FOREIGN KEY (cart_id) REFERENCES cart (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_F0FE25274584665A FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_F0FE252748027C30 FOREIGN KEY (fulfillment_region_id) REFERENCES fulfillment_region (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
            [
                'CREATE INDEX IDX_F0FE25271AD5CDBF ON cart_item (cart_id)',
                'CREATE INDEX IDX_F0FE25274584665A ON cart_item (product_id)',
                'CREATE INDEX IDX_F0FE252748027C30 ON cart_item (fulfillment_region_id)',
                'CREATE UNIQUE INDEX uniq_cart_item_cart_product ON cart_item (cart_id, product_id)',
            ],
        );

        $this->rebuild(
            'credit_memo_line',
            'id, name, sku, location, quantity, unit, tax_code, price, subtotal, sort_order, credit_memo_id, invoice_line_id, product_id',
            'CREATE TABLE credit_memo_line ( id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, sku VARCHAR(80) DEFAULT NULL, location VARCHAR(120) DEFAULT NULL, quantity NUMERIC(14, 4) NOT NULL, unit VARCHAR(80) DEFAULT NULL, tax_code VARCHAR(80) DEFAULT NULL, price NUMERIC(18, 6) NOT NULL, subtotal NUMERIC(12, 2) NOT NULL, sort_order INTEGER DEFAULT 0 NOT NULL, credit_memo_id INTEGER NOT NULL, invoice_line_id INTEGER DEFAULT NULL, product_id INTEGER DEFAULT NULL, CONSTRAINT FK_4B3489CA8E574316 FOREIGN KEY (credit_memo_id) REFERENCES credit_memo (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_4B3489CABFA24391 FOREIGN KEY (invoice_line_id) REFERENCES invoice_line (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_4B3489CA4584665A FOREIGN KEY (product_id) REFERENCES product_core (id) NOT DEFERRABLE INITIALLY IMMEDIATE )',
            [
                'CREATE INDEX IDX_4B3489CA4584665A ON credit_memo_line (product_id)',
                'CREATE INDEX IDX_4B3489CA8E574316 ON credit_memo_line (credit_memo_id)',
                'CREATE INDEX IDX_4B3489CABFA24391 ON credit_memo_line (invoice_line_id)',
            ],
        );

        $this->rebuild(
            'debit_memo_line',
            'id, debit_memo_id, vendor_bill_line_id, product_id, name, sku, quantity, unit_cost, subtotal, sort_order',
            'CREATE TABLE debit_memo_line (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, debit_memo_id INTEGER NOT NULL, vendor_bill_line_id INTEGER DEFAULT NULL, product_id INTEGER DEFAULT NULL, name VARCHAR(255) NOT NULL, sku VARCHAR(80) DEFAULT NULL, quantity NUMERIC(14, 4) NOT NULL, unit_cost NUMERIC(18, 6) NOT NULL, subtotal NUMERIC(12, 2) NOT NULL, sort_order INTEGER DEFAULT 0 NOT NULL, CONSTRAINT FK_debit_memo_line_memo FOREIGN KEY (debit_memo_id) REFERENCES debit_memo (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_debit_memo_line_bill_line FOREIGN KEY (vendor_bill_line_id) REFERENCES vendor_bill_line (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)',
            [
                'CREATE INDEX idx_debit_memo_line_memo ON debit_memo_line (debit_memo_id)',
            ],
        );

        $this->rebuild(
            'estimate_line',
            'id, name, location, sku, quantity, weight, unit, tax_code, cost, price, subtotal, sort_order, estimate_id, product_id',
            'CREATE TABLE estimate_line (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, location VARCHAR(120) DEFAULT NULL, sku VARCHAR(80) DEFAULT NULL, quantity NUMERIC(14, 4) NOT NULL, weight VARCHAR(80) DEFAULT NULL, unit VARCHAR(80) DEFAULT NULL, tax_code VARCHAR(80) DEFAULT NULL, cost NUMERIC(18, 6) NOT NULL, price NUMERIC(18, 6) DEFAULT NULL, subtotal NUMERIC(12, 2) DEFAULT NULL, sort_order INTEGER DEFAULT 0 NOT NULL, estimate_id INTEGER NOT NULL, product_id INTEGER DEFAULT NULL, CONSTRAINT FK_9715EDF785F23082 FOREIGN KEY (estimate_id) REFERENCES estimate (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_9715EDF74584665A FOREIGN KEY (product_id) REFERENCES product_core (id) NOT DEFERRABLE INITIALLY IMMEDIATE)',
            [
                'CREATE INDEX IDX_9715EDF74584665A ON estimate_line (product_id)',
                'CREATE INDEX IDX_9715EDF785F23082 ON estimate_line (estimate_id)',
            ],
        );

        $this->rebuild(
            'goods_receipt_line',
            'id, name, sku, serial, quantity, unit_cost, movement_applied, goods_receipt_id, purchase_order_line_id, product_id, lot_id, location_id',
            'CREATE TABLE "goods_receipt_line" (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, sku VARCHAR(80) DEFAULT NULL, serial VARCHAR(120) DEFAULT NULL, quantity NUMERIC(14, 4) NOT NULL CHECK (quantity > 0), unit_cost NUMERIC(18, 6) DEFAULT NULL, movement_applied BOOLEAN DEFAULT 0 NOT NULL, goods_receipt_id INTEGER NOT NULL, purchase_order_line_id INTEGER DEFAULT NULL, product_id INTEGER DEFAULT NULL, lot_id INTEGER DEFAULT NULL, location_id INTEGER DEFAULT NULL, CONSTRAINT FK_837578DA276FFF5C FOREIGN KEY (goods_receipt_id) REFERENCES "goods_receipt" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_837578DA6D136516 FOREIGN KEY (purchase_order_line_id) REFERENCES purchase_order_line (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_837578DAA8CBA5F7 FOREIGN KEY (lot_id) REFERENCES inventory_lot (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_837578DA64D218E FOREIGN KEY (location_id) REFERENCES warehouse_location (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)',
            [
                'CREATE INDEX IDX_837578DA64D218E ON "goods_receipt_line" (location_id)',
                'CREATE INDEX IDX_837578DAA8CBA5F7 ON "goods_receipt_line" (lot_id)',
                'CREATE INDEX idx_receipt_line_po_line ON "goods_receipt_line" (purchase_order_line_id)',
                'CREATE INDEX idx_receipt_line_product ON "goods_receipt_line" (product_id)',
                'CREATE INDEX idx_receipt_line_receipt ON "goods_receipt_line" (goods_receipt_id)',
            ],
        );

        $this->rebuild(
            'inventory_bucket_change_log',
            'id, bucket, previous_quantity, new_quantity, action, triggered_by, occurred_at, product_id, warehouse_id, group_id',
            'CREATE TABLE "inventory_bucket_change_log" (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, bucket VARCHAR(20) NOT NULL, previous_quantity NUMERIC(14, 4) NOT NULL, new_quantity NUMERIC(14, 4) NOT NULL, "action" VARCHAR(60) NOT NULL, triggered_by VARCHAR(190) NOT NULL, occurred_at DATETIME NOT NULL, product_id INTEGER NOT NULL, warehouse_id INTEGER NOT NULL, group_id INTEGER DEFAULT NULL REFERENCES inventory_movement_group (id) ON DELETE SET NULL, CONSTRAINT FK_C211BCE94584665A FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_C211BCE95080ECDE FOREIGN KEY (warehouse_id) REFERENCES warehouse (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
            [
                'CREATE INDEX IDX_C211BCE94584665A ON inventory_bucket_change_log (product_id)',
                'CREATE INDEX IDX_C211BCE95080ECDE ON inventory_bucket_change_log (warehouse_id)',
                'CREATE INDEX idx_bucket_change_log_group ON inventory_bucket_change_log (group_id)',
            ],
        );

        $this->rebuild(
            'inventory_detail',
            'id, serial, status, quantity, updated_at, product_id, warehouse_id, location_id, lot_id, expect_resolution',
            'CREATE TABLE "inventory_detail" ( id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, serial VARCHAR(120) DEFAULT NULL, status VARCHAR(16) NOT NULL, quantity NUMERIC(14, 4) DEFAULT 0 NOT NULL, updated_at DATETIME NOT NULL, product_id INTEGER NOT NULL, warehouse_id INTEGER NOT NULL, location_id INTEGER DEFAULT NULL, lot_id INTEGER DEFAULT NULL, expect_resolution BOOLEAN DEFAULT 0 NOT NULL, CONSTRAINT FK_2EDAE3384584665A FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2EDAE3385080ECDE FOREIGN KEY (warehouse_id) REFERENCES warehouse (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2EDAE33864D218E FOREIGN KEY (location_id) REFERENCES warehouse_location (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2EDAE338A8CBA5F7 FOREIGN KEY (lot_id) REFERENCES inventory_lot (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE )',
            [
                'CREATE INDEX IDX_2EDAE3384584665A ON inventory_detail (product_id)',
                'CREATE INDEX IDX_2EDAE3385080ECDE ON inventory_detail (warehouse_id)',
                'CREATE INDEX IDX_2EDAE338A8CBA5F7 ON inventory_detail (lot_id)',
                'CREATE INDEX idx_detail_available ON inventory_detail (product_id, warehouse_id) WHERE status = \'available\' AND quantity > 0',
                'CREATE INDEX idx_detail_location ON inventory_detail (location_id)',
                'CREATE INDEX idx_detail_lookup ON inventory_detail (product_id, warehouse_id, status)',
                'CREATE INDEX idx_detail_serial ON inventory_detail (serial)',
                'CREATE UNIQUE INDEX uniq_inventory_detail ON inventory_detail (product_id, warehouse_id, COALESCE(location_id, 0), COALESCE(lot_id, 0), COALESCE(serial, \'\'), status)',
                'CREATE UNIQUE INDEX uniq_live_serial ON inventory_detail (product_id, serial) WHERE serial IS NOT NULL AND quantity > 0',
            ],
        );

        $this->rebuild(
            'inventory_movement',
            'id, quantity, group_id, product_id, from_detail_id, to_detail_id, reverses_movement_id',
            'CREATE TABLE "inventory_movement" ( id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, quantity NUMERIC(14, 4) NOT NULL CHECK (quantity > 0), group_id INTEGER NOT NULL, product_id INTEGER NOT NULL, from_detail_id INTEGER DEFAULT NULL, to_detail_id INTEGER DEFAULT NULL, reverses_movement_id INTEGER DEFAULT NULL REFERENCES inventory_movement (id) ON DELETE SET NULL, CONSTRAINT FK_40972F66FE54D947 FOREIGN KEY (group_id) REFERENCES inventory_movement_group (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_40972F664584665A FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_40972F66C84C9601 FOREIGN KEY (from_detail_id) REFERENCES "inventory_detail" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_40972F66E973D4CA FOREIGN KEY (to_detail_id) REFERENCES "inventory_detail" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE )',
            [
                'CREATE INDEX IDX_40972F664584665A ON inventory_movement (product_id)',
                'CREATE INDEX IDX_40972F66FE54D947 ON inventory_movement (group_id)',
                'CREATE INDEX idx_movement_from ON inventory_movement (from_detail_id)',
                'CREATE INDEX idx_movement_reverses ON inventory_movement (reverses_movement_id)',
                'CREATE INDEX idx_movement_to ON inventory_movement (to_detail_id)',
            ],
        );

        $this->rebuild(
            'inventory_reconciliation_discrepancy',
            'id, bucket, cached_quantity, recomputed_quantity, source, occurred_at, product_id, warehouse_id',
            'CREATE TABLE "inventory_reconciliation_discrepancy" (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, bucket VARCHAR(20) NOT NULL, cached_quantity NUMERIC(14, 4) NOT NULL, recomputed_quantity NUMERIC(14, 4) NOT NULL, source VARCHAR(60) NOT NULL, occurred_at DATETIME NOT NULL, product_id INTEGER NOT NULL, warehouse_id INTEGER NOT NULL, CONSTRAINT FK_AC550DBF4584665A FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_AC550DBF5080ECDE FOREIGN KEY (warehouse_id) REFERENCES warehouse (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
            [
                'CREATE INDEX IDX_AC550DBF4584665A ON inventory_reconciliation_discrepancy (product_id)',
                'CREATE INDEX IDX_AC550DBF5080ECDE ON inventory_reconciliation_discrepancy (warehouse_id)',
            ],
        );

        $this->rebuild(
            'inventory_reorder_rule',
            'id, product_id, warehouse_id, reorder_point, reorder_quantity, safety_stock_quantity',
            'CREATE TABLE inventory_reorder_rule (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, product_id INTEGER NOT NULL, warehouse_id INTEGER NOT NULL, reorder_point NUMERIC(14, 4) NOT NULL, reorder_quantity NUMERIC(14, 4) DEFAULT NULL, safety_stock_quantity NUMERIC(14, 4) DEFAULT NULL, CONSTRAINT FK_inventory_reorder_rule_product FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_inventory_reorder_rule_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouse (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
            [
                'CREATE INDEX IDX_inventory_reorder_rule_warehouse ON inventory_reorder_rule (warehouse_id)',
                'CREATE UNIQUE INDEX uniq_inventory_reorder_rule ON inventory_reorder_rule (product_id, warehouse_id)',
            ],
        );

        $this->rebuild(
            'invoice_inventory_reservation',
            'id, bucket, quantity, synced_quantity, created_at, updated_at, invoice_id, product_id, warehouse_id',
            'CREATE TABLE "invoice_inventory_reservation" (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, bucket VARCHAR(20) NOT NULL, quantity NUMERIC(14, 4) NOT NULL, synced_quantity NUMERIC(14, 4) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, invoice_id INTEGER NOT NULL, product_id INTEGER NOT NULL, warehouse_id INTEGER NOT NULL, CONSTRAINT FK_5905971C2989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5905971C4584665A FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5905971C5080ECDE FOREIGN KEY (warehouse_id) REFERENCES warehouse (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
            [
                'CREATE INDEX IDX_5905971C2989F1FD ON invoice_inventory_reservation (invoice_id)',
                'CREATE INDEX IDX_5905971C4584665A ON invoice_inventory_reservation (product_id)',
                'CREATE INDEX IDX_5905971C5080ECDE ON invoice_inventory_reservation (warehouse_id)',
                'CREATE UNIQUE INDEX uniq_invoice_reservation_invoice_product_warehouse ON invoice_inventory_reservation (invoice_id, product_id, warehouse_id)',
            ],
        );

        $this->rebuild(
            'invoice_line',
            'id, invoice_id, sales_order_line_id, product_id, name, location, sku, quantity, weight, unit, tax_code, cost, price, subtotal, batch, sort_order',
            'CREATE TABLE invoice_line (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            invoice_id INTEGER NOT NULL,
            sales_order_line_id INTEGER DEFAULT NULL,
            -- Deliberately NO foreign key to product_core, matching sales_order_line.
            -- A document line snapshots a product; it must outlive that product\'s deletion,
            -- and product imports delete rows routinely. The dev database has 2000 order lines
            -- pointing at products that no longer exist, and every one of them is a correct
            -- record of something that was sold. A constraint here makes the invoice stricter
            -- than the order it copies, so the copy cannot hold what the original holds.
            product_id INTEGER DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            location VARCHAR(120) DEFAULT NULL,
            sku VARCHAR(80) DEFAULT NULL,
            quantity NUMERIC(14, 4) NOT NULL,
            weight VARCHAR(80) DEFAULT NULL,
            unit VARCHAR(80) DEFAULT NULL,
            tax_code VARCHAR(80) DEFAULT NULL,
            cost NUMERIC(18, 6) NOT NULL,
            price NUMERIC(18, 6) NOT NULL,
            subtotal NUMERIC(12, 2) NOT NULL,
            batch VARCHAR(120) DEFAULT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            CONSTRAINT fk_invoice_line_invoice FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE CASCADE,
            CONSTRAINT fk_invoice_line_order_line FOREIGN KEY (sales_order_line_id) REFERENCES sales_order_line (id) ON DELETE SET NULL
            )',
            [
                'CREATE INDEX idx_invoice_line_invoice ON invoice_line (invoice_id)',
                'CREATE INDEX idx_invoice_line_order_line ON invoice_line (sales_order_line_id)',
                'CREATE INDEX idx_invoice_line_product ON invoice_line (product_id)',
            ],
        );

        $this->rebuild(
            'order_inventory_reservation',
            'id, bucket, quantity, synced_quantity, created_at, updated_at, order_id, product_id, warehouse_id',
            'CREATE TABLE "order_inventory_reservation" (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, bucket VARCHAR(20) NOT NULL, quantity NUMERIC(14, 4) NOT NULL, synced_quantity NUMERIC(14, 4) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, order_id INTEGER NOT NULL, product_id INTEGER NOT NULL, warehouse_id INTEGER NOT NULL, CONSTRAINT FK_33119368D9F6D38 FOREIGN KEY (order_id) REFERENCES sales_order (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_33119364584665A FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_33119365080ECDE FOREIGN KEY (warehouse_id) REFERENCES warehouse (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
            [
                'CREATE INDEX IDX_33119364584665A ON order_inventory_reservation (product_id)',
                'CREATE INDEX IDX_33119365080ECDE ON order_inventory_reservation (warehouse_id)',
                'CREATE INDEX IDX_33119368D9F6D38 ON order_inventory_reservation (order_id)',
                'CREATE UNIQUE INDEX uniq_reservation_order_product_warehouse ON order_inventory_reservation (order_id, product_id, warehouse_id, bucket)',
            ],
        );

        $this->rebuild(
            'pick_task',
            'id, order_number, sku, name, sort_key, quantity_requested, quantity_picked, quantity_missing, pick_list_id, order_id, order_line_id, product_id, suggested_location_id',
            'CREATE TABLE pick_task (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, order_number VARCHAR(64) NOT NULL, sku VARCHAR(80) DEFAULT NULL, name VARCHAR(255) NOT NULL, sort_key INTEGER DEFAULT 0 NOT NULL, quantity_requested NUMERIC(14, 4) NOT NULL CHECK (quantity_requested > 0), quantity_picked NUMERIC(14, 4) DEFAULT 0 NOT NULL CHECK (quantity_picked >= 0), quantity_missing NUMERIC(14, 4) DEFAULT 0 NOT NULL CHECK (quantity_missing >= 0), pick_list_id INTEGER NOT NULL, order_id INTEGER DEFAULT NULL, order_line_id INTEGER DEFAULT NULL, product_id INTEGER DEFAULT NULL, suggested_location_id INTEGER DEFAULT NULL, CONSTRAINT FK_pick_task_list FOREIGN KEY (pick_list_id) REFERENCES pick_list (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_pick_task_order FOREIGN KEY (order_id) REFERENCES sales_order (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_pick_task_order_line FOREIGN KEY (order_line_id) REFERENCES sales_order_line (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_pick_task_product FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_pick_task_location FOREIGN KEY (suggested_location_id) REFERENCES warehouse_location (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)',
            [
                'CREATE INDEX IDX_pick_task_location ON pick_task (suggested_location_id)',
                'CREATE INDEX IDX_pick_task_order_line ON pick_task (order_line_id)',
                'CREATE INDEX IDX_pick_task_product ON pick_task (product_id)',
                'CREATE INDEX idx_pick_task_list_route ON pick_task (pick_list_id, sort_key)',
                'CREATE INDEX idx_pick_task_order ON pick_task (order_id)',
            ],
        );

        $this->rebuild(
            'product_core',
            'id, sku, name, description, is_private, status, plant, type, unit, weight, cost_price, original_price, default_price, deposit, suggested_price_type, suggested_price_value, visible, featured, deleted, sales_tax_code, shipping_class, sync_source, remarks, short_description, long_description, created_at, updated_at, category_id, inventory_mode, tracking_policy_id, unit_id',
            'CREATE TABLE product_core (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, sku VARCHAR(80) NOT NULL, name VARCHAR(255) NOT NULL, description CLOB DEFAULT NULL, is_private BOOLEAN NOT NULL, status VARCHAR(32) NOT NULL, plant VARCHAR(120) DEFAULT NULL, type VARCHAR(120) DEFAULT NULL, unit VARCHAR(80) DEFAULT NULL, weight VARCHAR(80) DEFAULT NULL, cost_price NUMERIC(18, 6) DEFAULT NULL, original_price NUMERIC(18, 6) DEFAULT NULL, default_price NUMERIC(18, 6) DEFAULT NULL, deposit NUMERIC(18, 6) DEFAULT NULL, suggested_price_type VARCHAR(20) DEFAULT NULL, suggested_price_value NUMERIC(18, 6) DEFAULT NULL, visible BOOLEAN NOT NULL, featured BOOLEAN NOT NULL, deleted BOOLEAN NOT NULL, sales_tax_code VARCHAR(40) DEFAULT NULL, shipping_class VARCHAR(80) DEFAULT NULL, sync_source VARCHAR(120) DEFAULT NULL, remarks CLOB DEFAULT NULL, short_description CLOB DEFAULT NULL, long_description CLOB DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, category_id INTEGER DEFAULT NULL, inventory_mode VARCHAR(16) DEFAULT \'simple\' NOT NULL, tracking_policy_id INTEGER DEFAULT NULL REFERENCES tracking_policy (id) ON DELETE SET NULL, unit_id INTEGER DEFAULT NULL REFERENCES unit_of_measure (id) ON DELETE RESTRICT, CONSTRAINT FK_E665A7EE12469DE2 FOREIGN KEY (category_id) REFERENCES product_category (id) NOT DEFERRABLE INITIALLY IMMEDIATE)',
            [
                'CREATE INDEX IDX_E665A7EE12469DE2 ON product_core (category_id)',
                'CREATE INDEX IDX_product_core_tracking_policy ON product_core (tracking_policy_id)',
                'CREATE INDEX IDX_product_core_unit ON product_core (unit_id)',
                'CREATE UNIQUE INDEX uniq_product_core_sku ON product_core (sku)',
            ],
        );

        $this->rebuild(
            'product_inventory',
            'id, quantity, reserved_quantity, cart_hold_quantity, sales_hold_quantity, pending_quantity, approved_quantity, manual_adjustment, updated_at, product_id, warehouse_id, allow_backorder, max_backorder_quantity, backorder_cap_shrinks_on_restock, auto_release_on_restock, backordered_quantity, received_quantity, incoming_quantity, quarantine_quantity, transfer_out_quantity, write_off_quantity, transfer_in_quantity',
            'CREATE TABLE "product_inventory" (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, quantity NUMERIC(14, 4) NOT NULL, reserved_quantity NUMERIC(14, 4) NOT NULL, cart_hold_quantity NUMERIC(14, 4) NOT NULL, sales_hold_quantity NUMERIC(14, 4) NOT NULL, pending_quantity NUMERIC(14, 4) NOT NULL, approved_quantity NUMERIC(14, 4) NOT NULL, manual_adjustment NUMERIC(14, 4) NOT NULL, updated_at DATETIME NOT NULL, product_id INTEGER NOT NULL, warehouse_id INTEGER DEFAULT NULL, allow_backorder BOOLEAN DEFAULT 0 NOT NULL, max_backorder_quantity NUMERIC(14, 4) DEFAULT NULL, backorder_cap_shrinks_on_restock BOOLEAN DEFAULT 0 NOT NULL, auto_release_on_restock BOOLEAN DEFAULT 0 NOT NULL, backordered_quantity NUMERIC(14, 4) DEFAULT 0 NOT NULL, received_quantity NUMERIC(14, 4) DEFAULT 0 NOT NULL, incoming_quantity NUMERIC(14, 4) DEFAULT 0 NOT NULL, quarantine_quantity NUMERIC(14, 4) DEFAULT 0 NOT NULL, transfer_out_quantity NUMERIC(14, 4) DEFAULT 0 NOT NULL, write_off_quantity NUMERIC(14, 4) DEFAULT 0 NOT NULL, transfer_in_quantity NUMERIC(14, 4) DEFAULT 0 NOT NULL, CONSTRAINT FK_DF8DFCBB4584665A FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_DF8DFCBB5080ECDE FOREIGN KEY (warehouse_id) REFERENCES warehouse (id) NOT DEFERRABLE INITIALLY IMMEDIATE)',
            [
                'CREATE INDEX IDX_DF8DFCBB4584665A ON product_inventory (product_id)',
                'CREATE INDEX IDX_DF8DFCBB5080ECDE ON product_inventory (warehouse_id)',
                'CREATE UNIQUE INDEX uniq_product_inventory_product_location ON product_inventory (product_id, warehouse_id)',
            ],
        );

        $this->rebuild(
            'product_pricing',
            'id, price, rule_type, rule_value, currency, created_at, product_id, price_list_id',
            'CREATE TABLE product_pricing (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, price NUMERIC(18, 6) NOT NULL, rule_type VARCHAR(20) DEFAULT NULL, rule_value NUMERIC(18, 6) DEFAULT NULL, currency VARCHAR(3) NOT NULL, created_at DATETIME NOT NULL, product_id INTEGER NOT NULL, price_list_id INTEGER NOT NULL, CONSTRAINT FK_3428B7834584665A FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_3428B7835688DED7 FOREIGN KEY (price_list_id) REFERENCES price_list (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
            [
                'CREATE INDEX IDX_3428B7834584665A ON product_pricing (product_id)',
                'CREATE INDEX IDX_3428B7835688DED7 ON product_pricing (price_list_id)',
                'CREATE UNIQUE INDEX uniq_product_pricing_product_list ON product_pricing (product_id, price_list_id)',
            ],
        );

        $this->rebuild(
            'purchase_order_line',
            'id, name, sku, vendor_sku, quantity_ordered, quantity_received, unit_cost, subtotal, sort_order, purchase_order_id, product_id',
            'CREATE TABLE purchase_order_line (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, sku VARCHAR(80) DEFAULT NULL, vendor_sku VARCHAR(80) DEFAULT NULL, quantity_ordered NUMERIC(14, 4) NOT NULL, quantity_received NUMERIC(14, 4) DEFAULT \'0.00\' NOT NULL, unit_cost NUMERIC(18, 6) NOT NULL, subtotal NUMERIC(12, 2) NOT NULL, sort_order INTEGER DEFAULT 0 NOT NULL, purchase_order_id INTEGER NOT NULL, product_id INTEGER DEFAULT NULL, CONSTRAINT FK_90D6D92BA45D7E6A FOREIGN KEY (purchase_order_id) REFERENCES purchase_order (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
            [
                'CREATE INDEX idx_po_line_order ON purchase_order_line (purchase_order_id)',
                'CREATE INDEX idx_po_line_product ON purchase_order_line (product_id)',
            ],
        );

        $this->rebuild(
            'rfq_line',
            'id, rfq_id, product_id, name, sku, quantity, notes, sort_order',
            'CREATE TABLE rfq_line (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, rfq_id INTEGER NOT NULL, product_id INTEGER DEFAULT NULL, name VARCHAR(255) NOT NULL, sku VARCHAR(80) DEFAULT NULL, quantity NUMERIC(14, 4) NOT NULL, notes CLOB DEFAULT NULL, sort_order INTEGER DEFAULT 0 NOT NULL, CONSTRAINT FK_rfq_line_rfq FOREIGN KEY (rfq_id) REFERENCES rfq (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
            [
                'CREATE INDEX idx_rfq_line_rfq ON rfq_line (rfq_id)',
            ],
        );

        $this->rebuild(
            'rfq_vendor_reply_line',
            'id, reply_id, rfq_line_id, unit_cost, subtotal, notes',
            'CREATE TABLE rfq_vendor_reply_line (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, reply_id INTEGER NOT NULL, rfq_line_id INTEGER DEFAULT NULL, unit_cost NUMERIC(18, 6) DEFAULT NULL, subtotal NUMERIC(12, 2) DEFAULT NULL, notes CLOB DEFAULT NULL, CONSTRAINT FK_rfq_reply_line_reply FOREIGN KEY (reply_id) REFERENCES rfq_vendor_reply (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_rfq_reply_line_rfq_line FOREIGN KEY (rfq_line_id) REFERENCES rfq_line (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)',
            [
                'CREATE INDEX idx_rfq_reply_line_reply ON rfq_vendor_reply_line (reply_id)',
            ],
        );

        $this->rebuild(
            'sales_order_line',
            'id, name, location, sku, quantity, weight, unit, tax_code, cost, price, subtotal, batch, sort_order, order_id, product_id, backordered_quantity, fulfillment_status, restock_eta',
            'CREATE TABLE sales_order_line (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, location VARCHAR(120) DEFAULT NULL, sku VARCHAR(80) DEFAULT NULL, quantity NUMERIC(14, 4) NOT NULL, weight VARCHAR(80) DEFAULT NULL, unit VARCHAR(80) DEFAULT NULL, tax_code VARCHAR(80) DEFAULT NULL, cost NUMERIC(18, 6) NOT NULL, price NUMERIC(18, 6) NOT NULL, subtotal NUMERIC(12, 2) NOT NULL, batch VARCHAR(120) DEFAULT NULL, sort_order INTEGER DEFAULT 0 NOT NULL, order_id INTEGER NOT NULL, product_id INTEGER DEFAULT NULL, backordered_quantity NUMERIC(14, 4) DEFAULT \'0.00\' NOT NULL, fulfillment_status VARCHAR(32) DEFAULT \'Fulfilled\' NOT NULL, restock_eta DATE DEFAULT NULL, CONSTRAINT FK_93D9398D8D9F6D38 FOREIGN KEY (order_id) REFERENCES sales_order (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_93D9398D4584665A FOREIGN KEY (product_id) REFERENCES product_core (id) NOT DEFERRABLE INITIALLY IMMEDIATE)',
            [
                'CREATE INDEX IDX_93D9398D4584665A ON sales_order_line (product_id)',
                'CREATE INDEX IDX_93D9398D8D9F6D38 ON sales_order_line (order_id)',
            ],
        );

        $this->rebuild(
            'sales_return_line',
            'id, quantity, name, sku, reason, disposition, sort_order, sales_return_id, product_id, invoice_line_id',
            'CREATE TABLE sales_return_line ( id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, quantity NUMERIC(14, 4) NOT NULL, name VARCHAR(255) NOT NULL, sku VARCHAR(80) DEFAULT NULL, reason CLOB DEFAULT NULL, disposition VARCHAR(32) DEFAULT NULL, sort_order INTEGER DEFAULT 0 NOT NULL, sales_return_id INTEGER NOT NULL, product_id INTEGER NOT NULL, invoice_line_id INTEGER DEFAULT NULL, CONSTRAINT FK_F4E915DB1E3A4E9C FOREIGN KEY (sales_return_id) REFERENCES sales_return (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_F4E915DB4584665A FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_F4E915DBBFA24391 FOREIGN KEY (invoice_line_id) REFERENCES invoice_line (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE )',
            [
                'CREATE INDEX IDX_F4E915DB1E3A4E9C ON sales_return_line (sales_return_id)',
                'CREATE INDEX IDX_F4E915DB4584665A ON sales_return_line (product_id)',
                'CREATE INDEX IDX_F4E915DBBFA24391 ON sales_return_line (invoice_line_id)',
            ],
        );

        $this->rebuild(
            'transfer_order_line',
            'id, serial, sku, name, quantity_requested, quantity_dispatched, quantity_received, transfer_order_id, product_id, lot_id',
            'CREATE TABLE transfer_order_line (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, serial VARCHAR(120) DEFAULT NULL, sku VARCHAR(80) DEFAULT NULL, name VARCHAR(255) NOT NULL, quantity_requested NUMERIC(14, 4) NOT NULL CHECK (quantity_requested > 0), quantity_dispatched NUMERIC(14, 4) DEFAULT 0 NOT NULL CHECK (quantity_dispatched >= 0), quantity_received NUMERIC(14, 4) DEFAULT 0 NOT NULL CHECK (quantity_received >= 0), transfer_order_id INTEGER NOT NULL, product_id INTEGER NOT NULL, lot_id INTEGER DEFAULT NULL, CONSTRAINT FK_transfer_line_order FOREIGN KEY (transfer_order_id) REFERENCES transfer_order (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_transfer_line_product FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_transfer_line_lot FOREIGN KEY (lot_id) REFERENCES inventory_lot (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)',
            [
                'CREATE INDEX IDX_transfer_line_lot ON transfer_order_line (lot_id)',
                'CREATE INDEX IDX_transfer_line_order ON transfer_order_line (transfer_order_id)',
                'CREATE INDEX idx_transfer_line_product ON transfer_order_line (product_id)',
            ],
        );

        $this->rebuild(
            'vendor_bill_line',
            'id, name, sku, vendor_sku, quantity, unit_cost, subtotal, sort_order, vendor_bill_id, purchase_order_line_id, product_id',
            'CREATE TABLE vendor_bill_line (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, sku VARCHAR(80) DEFAULT NULL, vendor_sku VARCHAR(80) DEFAULT NULL, quantity NUMERIC(14, 4) NOT NULL, unit_cost NUMERIC(18, 6) NOT NULL, subtotal NUMERIC(12, 2) NOT NULL, sort_order INTEGER DEFAULT 0 NOT NULL, vendor_bill_id INTEGER NOT NULL, purchase_order_line_id INTEGER DEFAULT NULL, product_id INTEGER DEFAULT NULL, CONSTRAINT FK_E86C0DDD4FB2A9A FOREIGN KEY (vendor_bill_id) REFERENCES vendor_bill (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_E86C0DDD6D136516 FOREIGN KEY (purchase_order_line_id) REFERENCES purchase_order_line (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)',
            [
                'CREATE INDEX idx_bill_line_bill ON vendor_bill_line (vendor_bill_id)',
                'CREATE INDEX idx_bill_line_po_line ON vendor_bill_line (purchase_order_line_id)',
                'CREATE INDEX idx_bill_line_product ON vendor_bill_line (product_id)',
            ],
        );

        $this->rebuild(
            'vendor_price',
            'id, vendor_id, product_id, vendor_sku, unit_cost, currency, order_multiple, is_active, notes, created_at',
            'CREATE TABLE vendor_price (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, vendor_id INTEGER NOT NULL, product_id INTEGER NOT NULL, vendor_sku VARCHAR(80) DEFAULT NULL, unit_cost NUMERIC(18, 6) NOT NULL, currency VARCHAR(3) DEFAULT \'CAD\' NOT NULL, order_multiple NUMERIC(14, 4) DEFAULT 1 NOT NULL, is_active BOOLEAN DEFAULT 1 NOT NULL, notes CLOB DEFAULT NULL, created_at DATETIME NOT NULL, CONSTRAINT FK_vendor_price_vendor FOREIGN KEY (vendor_id) REFERENCES vendor (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_vendor_price_product FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
            [
                'CREATE INDEX idx_vendor_price_product ON vendor_price (product_id)',
                'CREATE UNIQUE INDEX uniq_vendor_price_vendor_product ON vendor_price (vendor_id, product_id)',
            ],
        );

        $this->rebuild(
            'vendor_return_line',
            'id, vendor_return_id, product_id, goods_receipt_line_id, quantity, name, sku, reason, sort_order',
            'CREATE TABLE vendor_return_line (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, vendor_return_id INTEGER NOT NULL, product_id INTEGER NOT NULL, goods_receipt_line_id INTEGER DEFAULT NULL, quantity NUMERIC(14, 4) NOT NULL, name VARCHAR(255) NOT NULL, sku VARCHAR(80) DEFAULT NULL, reason CLOB DEFAULT NULL, sort_order INTEGER DEFAULT 0 NOT NULL, CONSTRAINT FK_vendor_return_line_return FOREIGN KEY (vendor_return_id) REFERENCES vendor_return (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_vendor_return_line_product FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_vendor_return_line_receipt_line FOREIGN KEY (goods_receipt_line_id) REFERENCES goods_receipt_line (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)',
            [
                'CREATE INDEX idx_vendor_return_line_return ON vendor_return_line (vendor_return_id)',
            ],
        );

        // Restore the AUTOINCREMENT high-water marks the drops took with them. Both statements are
        // no-ops for a table whose counter the re-insert already put back where it was.
        $this->addSql('INSERT INTO sqlite_sequence (name, seq) SELECT name, seq FROM __seq__645 WHERE name NOT IN (SELECT name FROM sqlite_sequence)');
        $this->addSql('UPDATE sqlite_sequence SET seq = (SELECT s.seq FROM __seq__645 s WHERE s.name = sqlite_sequence.name) WHERE EXISTS (SELECT 1 FROM __seq__645 s WHERE s.name = sqlite_sequence.name AND s.seq > sqlite_sequence.seq)');
        $this->addSql('DROP TABLE __seq__645');

        $this->addSql('PRAGMA foreign_keys = ON');
    }

    /**
     * Proves the thing this migration is most likely to get wrong, rather than assuming it.
     *
     * With foreign keys off for the length of the rebuild, an orphaned child is not refused at the
     * moment it is created — it simply sits there, and the migration reports success. This is the
     * check that turns that into a failure.
     */
    public function postUp(Schema $schema): void
    {
        $violations = $this->connection->executeQuery('PRAGMA foreign_key_check')->fetchAllAssociative();

        if ($violations !== []) {
            throw new \RuntimeException(sprintf(
                'The rebuild orphaned %d row(s) — the first is in %s. The database is NOT in a state '
                . 'to keep: restore it and work out which parent was dropped while a child pointed at it.',
                \count($violations),
                (string) ($violations[0]['table'] ?? 'an unknown table'),
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Going back means rebuilding 26 tables to re-declare 69 columns narrower than they are, '
            . 'and a narrower column cannot promise to hold what a wider one was allowed to accept — '
            . 'any quantity or rate written with more decimals than the old declaration would be '
            . 'rounded on the way through. Restore from a backup instead.'
        );
    }

    /**
     * The documented SQLite table rebuild: copy out, drop, recreate, copy back — the same helper
     * Version20260806140000 used for the same reason (that migration changed a column type too).
     *
     * The column list is named explicitly in both directions rather than using SELECT *, so this
     * fails loudly against a table whose shape has drifted instead of silently copying whatever
     * happens to be there in whatever order it happens to be in. It is also what keeps the copy
     * positional: the temp table's columns are in the source table's order, and they go back into
     * a table whose column order is unchanged.
     *
     * @param list<string> $indexes
     */
    private function rebuild(string $table, string $columns, string $createTable, array $indexes): void
    {
        $this->addSql(sprintf('CREATE TEMPORARY TABLE __temp__%s AS SELECT %s FROM %s', $table, $columns, $table));
        $this->addSql(sprintf('DROP TABLE %s', $table));
        $this->addSql($createTable);
        $this->addSql(sprintf('INSERT INTO %s (%s) SELECT %s FROM __temp__%s', $table, $columns, $columns, $table));
        $this->addSql(sprintf('DROP TABLE __temp__%s', $table));

        foreach ($indexes as $index) {
            $this->addSql($index);
        }
    }
}
