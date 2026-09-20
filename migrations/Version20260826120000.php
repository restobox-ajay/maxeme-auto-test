<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Procurement: vendor, purchase order, receipt and bill (#555).
 *
 * The buy side. The system knew how goods leave and nothing about how they arrive; this adds who we
 * buy from, what we ordered, what turned up, and what we were charged.
 *
 * ## Purely additive, and why that is worth stating
 *
 * Eleven new tables and nothing else. No table is rebuilt, no column is dropped, no existing row is
 * written. Replaying the chain over real data therefore cannot lose any of it — which is the
 * failure mode `bin/ci-migration-replay` exists to catch and the one that made
 * Version20260730150000 and Version20260821090000 delicate.
 *
 * Nothing here relies on `PRAGMA foreign_keys = OFF`, which is silently ignored inside a
 * transaction, because there is nothing to cascade: nothing is dropped. The SQLite floor stays 3.26
 * — no `DROP COLUMN` (3.35), no `ALTER COLUMN`, and the one `CHECK` below is in a `CREATE TABLE`
 * where it is free.
 *
 * ## No foreign key to product_core from any line table
 *
 * `purchase_order_line`, `purchase_receipt_line` and `vendor_bill_line` all carry `product_id` and
 * none of them carries a `FOREIGN KEY` for it. This is the same deliberate omission
 * `sales_order_line` has, for the same reason, and it is the one thing in this migration that is
 * NOT what Doctrine's metadata would emit.
 *
 * A document line snapshots what was bought and has to outlive the product's deletion. Product
 * imports delete rows routinely, and this database already holds two thousand order lines naming
 * products that no longer exist — every one a correct record of something that was bought or sold.
 * An FK there made a migration unrunnable once; see the "No foreign key here, still" note on
 * Version20260823102000.
 *
 * The three foreign keys that DO point at core rows — `warehouse`, `inventory_lot`,
 * `warehouse_location` — are kept, because those are live infrastructure this app owns rather than
 * snapshots of somebody else's paperwork, and every one of them is `SET NULL` on the snapshot side.
 * `procurement_product_rule.product_id` cascades, because a policy about a product that no longer
 * exists is meaningless rather than historical.
 *
 * ## Data outlives the bundle
 *
 * `purchase_receipt.movement_group_id` is `ON DELETE SET NULL`, not `CASCADE`. Losing #550's
 * movement history must never delete the record that goods arrived; the two are separate facts and
 * only the join between them is allowed to go. The same reasoning puts `SET NULL` on
 * `purchase_receipt.purchase_order_id` — deleting a purchase order must not delete the evidence
 * that its goods came.
 *
 * ## The one guard Doctrine's mapping layer cannot express
 *
 * `CHECK (quantity > 0)` on `purchase_receipt_line`. A receipt line for zero units records nothing
 * and one for a negative quantity is a return wearing a receipt's clothes. ReceivingService refuses
 * both with a message a receiver can act on, and this is the backstop — present in a migrated
 * database and absent from the metadata-built schema both test suites use, exactly like #550's
 * three guards, and for the same reason: the enforcing layer is deliberately the application.
 *
 * ## Timestamp
 *
 * Above Version20260823104000, the previous end of the chain, with a deliberate gap: #552
 * (warehouse operations) is being built in parallel and also adds migrations. Two parallel stages
 * picking the same number is a merge conflict that only surfaces when they meet — it happened
 * between #539's stages 3 and 4.
 */
final class Version20260826120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#555: add the buy side — vendors, purchase orders, receipts and bills, with receiving joined to the movement ledger.';
    }

    public function up(Schema $schema): void
    {
        // --- who we buy from --------------------------------------------------------------------
        $this->addSql("CREATE TABLE vendor (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(200) NOT NULL, account_number VARCHAR(80) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, phone VARCHAR(60) DEFAULT NULL, payment_term VARCHAR(80) DEFAULT NULL, currency VARCHAR(3) DEFAULT 'CAD' NOT NULL, status VARCHAR(16) DEFAULT 'Active' NOT NULL, notes CLOB DEFAULT NULL, created_at DATETIME NOT NULL)");
        $this->addSql('CREATE INDEX idx_vendor_name ON vendor (name)');
        $this->addSql('CREATE INDEX idx_vendor_status ON vendor (status)');

        // `payment_term` above names a row in the existing raw-SQL payment_term table and is
        // deliberately not a foreign key into it: that table is admin-managed outside the ORM (one
        // of the tables RawSqlTablesSurviveTheChainTest protects), and the value is copied onto
        // every document as a snapshot anyway.

        $this->addSql('CREATE TABLE vendor_address (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, label VARCHAR(80) DEFAULT NULL, address_1 VARCHAR(200) NOT NULL, address_2 VARCHAR(200) DEFAULT NULL, city VARCHAR(120) NOT NULL, province VARCHAR(8) DEFAULT NULL, postal_code VARCHAR(20) DEFAULT NULL, country VARCHAR(2) NOT NULL, is_default BOOLEAN DEFAULT 0 NOT NULL, vendor_id INTEGER NOT NULL, CONSTRAINT FK_133957EEF603EE73 FOREIGN KEY (vendor_id) REFERENCES vendor (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_vendor_address_vendor ON vendor_address (vendor_id)');

        // --- what we asked for ------------------------------------------------------------------
        $this->addSql("CREATE TABLE purchase_order (vendor_name VARCHAR(200) NOT NULL, document_date VARCHAR(10) NOT NULL, currency VARCHAR(3) DEFAULT 'CAD' NOT NULL, subtotal NUMERIC(12, 2) DEFAULT '0.00' NOT NULL, tax NUMERIC(12, 2) DEFAULT '0.00' NOT NULL, total NUMERIC(12, 2) DEFAULT '0.00' NOT NULL, created_at DATETIME NOT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, po_number VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL, expected_date VARCHAR(10) DEFAULT NULL, vendor_address CLOB DEFAULT NULL, payment_term VARCHAR(80) DEFAULT NULL, notes CLOB DEFAULT NULL, vendor_id INTEGER NOT NULL, warehouse_id INTEGER NOT NULL, CONSTRAINT FK_21E210B2F603EE73 FOREIGN KEY (vendor_id) REFERENCES vendor (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_21E210B25080ECDE FOREIGN KEY (warehouse_id) REFERENCES warehouse (id) NOT DEFERRABLE INITIALLY IMMEDIATE)");
        $this->addSql('CREATE INDEX IDX_21E210B25080ECDE ON purchase_order (warehouse_id)');
        $this->addSql('CREATE INDEX idx_po_vendor ON purchase_order (vendor_id)');
        $this->addSql('CREATE INDEX idx_po_status ON purchase_order (status)');
        // The expected-arrivals view's only query: what is due in, by date.
        $this->addSql('CREATE INDEX idx_po_expected ON purchase_order (expected_date)');
        $this->addSql('CREATE UNIQUE INDEX uniq_po_number ON purchase_order (po_number)');

        // product_id carries NO foreign key — see the class docblock.
        $this->addSql("CREATE TABLE purchase_order_line (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, sku VARCHAR(80) DEFAULT NULL, vendor_sku VARCHAR(80) DEFAULT NULL, quantity_ordered NUMERIC(12, 2) NOT NULL, quantity_received NUMERIC(12, 2) DEFAULT '0.00' NOT NULL, unit_cost NUMERIC(12, 4) NOT NULL, subtotal NUMERIC(12, 2) NOT NULL, sort_order INTEGER DEFAULT 0 NOT NULL, purchase_order_id INTEGER NOT NULL, product_id INTEGER DEFAULT NULL, CONSTRAINT FK_90D6D92BA45D7E6A FOREIGN KEY (purchase_order_id) REFERENCES purchase_order (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)");
        $this->addSql('CREATE INDEX idx_po_line_order ON purchase_order_line (purchase_order_id)');
        $this->addSql('CREATE INDEX idx_po_line_product ON purchase_order_line (product_id)');

        $this->addSql('CREATE TABLE purchase_order_log (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, user_name VARCHAR(255) DEFAULT NULL, comment CLOB NOT NULL, type VARCHAR(64) NOT NULL, vendor_notified BOOLEAN DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, purchase_order_id INTEGER NOT NULL, CONSTRAINT FK_4928D56EA45D7E6A FOREIGN KEY (purchase_order_id) REFERENCES purchase_order (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_po_log_order ON purchase_order_log (purchase_order_id)');

        // --- what turned up ---------------------------------------------------------------------
        // movement_group_id is the join to #550, and it is SET NULL: data outlives the bundle, and
        // losing the movement history must not delete the record that goods arrived.
        $this->addSql('CREATE TABLE purchase_receipt (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, receipt_number VARCHAR(32) NOT NULL, client_operation_id VARCHAR(64) DEFAULT NULL, vendor_name VARCHAR(200) NOT NULL, packing_slip VARCHAR(80) DEFAULT NULL, received_at DATETIME NOT NULL, received_by VARCHAR(160) DEFAULT NULL, notes CLOB DEFAULT NULL, purchase_order_id INTEGER DEFAULT NULL, vendor_id INTEGER NOT NULL, warehouse_id INTEGER NOT NULL, movement_group_id INTEGER DEFAULT NULL, CONSTRAINT FK_48437C3CA45D7E6A FOREIGN KEY (purchase_order_id) REFERENCES purchase_order (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_48437C3CF603EE73 FOREIGN KEY (vendor_id) REFERENCES vendor (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_48437C3C5080ECDE FOREIGN KEY (warehouse_id) REFERENCES warehouse (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_48437C3C174B16B FOREIGN KEY (movement_group_id) REFERENCES inventory_movement_group (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_48437C3C5080ECDE ON purchase_receipt (warehouse_id)');
        $this->addSql('CREATE INDEX IDX_48437C3C174B16B ON purchase_receipt (movement_group_id)');
        $this->addSql('CREATE INDEX idx_receipt_po ON purchase_receipt (purchase_order_id)');
        $this->addSql('CREATE INDEX idx_receipt_vendor ON purchase_receipt (vendor_id)');
        $this->addSql('CREATE INDEX idx_receipt_received ON purchase_receipt (received_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_receipt_number ON purchase_receipt (receipt_number)');
        // The caller's idempotency key: a resubmitted receiving form books one delivery in once.
        // Unique, and nullable so NULLs may repeat — SQLite treats them as distinct, which is
        // exactly right here, because a receipt written by a command or an import with no natural
        // key is not a duplicate of every other one.
        $this->addSql('CREATE UNIQUE INDEX uniq_receipt_operation ON purchase_receipt (client_operation_id)');

        // CHECK (quantity > 0) is in the CREATE because SQLite cannot add a constraint later
        // without a full table rebuild, and this is the one chance to have it for free.
        // product_id carries NO foreign key — see the class docblock.
        $this->addSql('CREATE TABLE purchase_receipt_line (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, sku VARCHAR(80) DEFAULT NULL, serial VARCHAR(120) DEFAULT NULL, quantity NUMERIC(12, 2) NOT NULL CHECK (quantity > 0), unit_cost NUMERIC(12, 4) DEFAULT NULL, movement_applied BOOLEAN DEFAULT 0 NOT NULL, purchase_receipt_id INTEGER NOT NULL, purchase_order_line_id INTEGER DEFAULT NULL, product_id INTEGER DEFAULT NULL, lot_id INTEGER DEFAULT NULL, location_id INTEGER DEFAULT NULL, CONSTRAINT FK_837578DA276FFF5C FOREIGN KEY (purchase_receipt_id) REFERENCES purchase_receipt (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_837578DA6D136516 FOREIGN KEY (purchase_order_line_id) REFERENCES purchase_order_line (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_837578DAA8CBA5F7 FOREIGN KEY (lot_id) REFERENCES inventory_lot (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_837578DA64D218E FOREIGN KEY (location_id) REFERENCES warehouse_location (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_837578DAA8CBA5F7 ON purchase_receipt_line (lot_id)');
        $this->addSql('CREATE INDEX IDX_837578DA64D218E ON purchase_receipt_line (location_id)');
        $this->addSql('CREATE INDEX idx_receipt_line_receipt ON purchase_receipt_line (purchase_receipt_id)');
        $this->addSql('CREATE INDEX idx_receipt_line_product ON purchase_receipt_line (product_id)');
        $this->addSql('CREATE INDEX idx_receipt_line_po_line ON purchase_receipt_line (purchase_order_line_id)');

        // --- what we were charged ---------------------------------------------------------------
        $this->addSql("CREATE TABLE vendor_bill (vendor_name VARCHAR(200) NOT NULL, document_date VARCHAR(10) NOT NULL, currency VARCHAR(3) DEFAULT 'CAD' NOT NULL, subtotal NUMERIC(12, 2) DEFAULT '0.00' NOT NULL, tax NUMERIC(12, 2) DEFAULT '0.00' NOT NULL, total NUMERIC(12, 2) DEFAULT '0.00' NOT NULL, created_at DATETIME NOT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, bill_number VARCHAR(32) NOT NULL, vendor_invoice_no VARCHAR(80) DEFAULT NULL, status VARCHAR(20) NOT NULL, due_date VARCHAR(10) DEFAULT NULL, amount_paid NUMERIC(12, 2) DEFAULT '0.00' NOT NULL, notes CLOB DEFAULT NULL, vendor_id INTEGER NOT NULL, purchase_order_id INTEGER DEFAULT NULL, CONSTRAINT FK_50EC1C3CF603EE73 FOREIGN KEY (vendor_id) REFERENCES vendor (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_50EC1C3CA45D7E6A FOREIGN KEY (purchase_order_id) REFERENCES purchase_order (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)");
        $this->addSql('CREATE INDEX IDX_50EC1C3CF603EE73 ON vendor_bill (vendor_id)');
        $this->addSql('CREATE INDEX IDX_50EC1C3CA45D7E6A ON vendor_bill (purchase_order_id)');
        // The duplicate-payment guard's index. Paying the same invoice twice is the single most
        // expensive clerical error in AP, and it happens because the same PDF arrives twice by
        // email. Deliberately NOT unique: vendors do reuse their own numbers, so this backs a
        // warning rather than a block.
        $this->addSql('CREATE INDEX idx_bill_vendor_invoice ON vendor_bill (vendor_id, vendor_invoice_no)');
        $this->addSql('CREATE INDEX idx_bill_status ON vendor_bill (status)');
        $this->addSql('CREATE INDEX idx_bill_due ON vendor_bill (due_date)');
        $this->addSql('CREATE UNIQUE INDEX uniq_bill_number ON vendor_bill (bill_number)');

        // product_id carries NO foreign key — see the class docblock.
        $this->addSql('CREATE TABLE vendor_bill_line (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(255) NOT NULL, sku VARCHAR(80) DEFAULT NULL, vendor_sku VARCHAR(80) DEFAULT NULL, quantity NUMERIC(12, 2) NOT NULL, unit_cost NUMERIC(12, 4) NOT NULL, subtotal NUMERIC(12, 2) NOT NULL, sort_order INTEGER DEFAULT 0 NOT NULL, vendor_bill_id INTEGER NOT NULL, purchase_order_line_id INTEGER DEFAULT NULL, product_id INTEGER DEFAULT NULL, CONSTRAINT FK_E86C0DDD4FB2A9A FOREIGN KEY (vendor_bill_id) REFERENCES vendor_bill (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_E86C0DDD6D136516 FOREIGN KEY (purchase_order_line_id) REFERENCES purchase_order_line (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_bill_line_bill ON vendor_bill_line (vendor_bill_id)');
        $this->addSql('CREATE INDEX idx_bill_line_po_line ON vendor_bill_line (purchase_order_line_id)');
        $this->addSql('CREATE INDEX idx_bill_line_product ON vendor_bill_line (product_id)');

        $this->addSql('CREATE TABLE vendor_bill_log (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, user_name VARCHAR(255) DEFAULT NULL, comment CLOB NOT NULL, type VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, vendor_bill_id INTEGER NOT NULL, CONSTRAINT FK_F43581264FB2A9A FOREIGN KEY (vendor_bill_id) REFERENCES vendor_bill (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_bill_log_bill ON vendor_bill_log (vendor_bill_id)');

        // --- what a receiver must capture -------------------------------------------------------
        // #550 gives a product the ABILITY to carry a lot, an expiry and a serial and — correctly,
        // for an adjustment screen — no way to say it MUST. Receiving is the one moment those can
        // be captured at all, so this says which products need what. A product with no row here
        // needs nothing, which is exactly how receiving would behave without this bundle. The FK
        // cascades because a policy about a deleted product is meaningless rather than historical.
        $this->addSql('CREATE TABLE procurement_product_rule (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, lot_required BOOLEAN DEFAULT 0 NOT NULL, expiry_required BOOLEAN DEFAULT 0 NOT NULL, serial_required BOOLEAN DEFAULT 0 NOT NULL, location_required BOOLEAN DEFAULT 0 NOT NULL, product_id INTEGER NOT NULL, CONSTRAINT FK_62C658054584665A FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_procurement_rule_product ON procurement_product_rule (product_id)');
    }

    /**
     * Child tables first, so nothing is dropped while something still references it.
     *
     * Nothing outside this migration references any of these, so there is no cascade to get wrong —
     * which is the whole reason this direction is safe where a table rebuild would not be.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE procurement_product_rule');
        $this->addSql('DROP TABLE vendor_bill_log');
        $this->addSql('DROP TABLE vendor_bill_line');
        $this->addSql('DROP TABLE vendor_bill');
        $this->addSql('DROP TABLE purchase_receipt_line');
        $this->addSql('DROP TABLE purchase_receipt');
        $this->addSql('DROP TABLE purchase_order_log');
        $this->addSql('DROP TABLE purchase_order_line');
        $this->addSql('DROP TABLE purchase_order');
        $this->addSql('DROP TABLE vendor_address');
        $this->addSql('DROP TABLE vendor');
    }
}
