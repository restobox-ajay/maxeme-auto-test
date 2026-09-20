<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #637/#638: vendor price list, RFQ, vendor return, debit memo — four new purchase-side documents,
 * completing the mirror the epic (#639) maps out.
 *
 * ```
 *                  PURCHASE                     SALES
 * quote            RFQ            #637 THIS     Estimate       built
 * order            PurchaseOrder  built         SalesOrder     built
 * goods move       GoodsReceipt   built         Shipment       #593 BLOCKED
 * money document   VendorBill     built         Invoice        built
 * return           VendorReturn   #638 THIS     SalesReturn    built #596
 * credit / debit   DebitMemo      #638 THIS     CreditMemo     built #586
 * ```
 *
 * ## Purely additive
 *
 * Eleven new tables and nothing else — no table rebuilt, no column dropped, no existing row
 * written. Production is the sales-only app: nothing on the purchase side has production rows, so
 * every one of these tables is created and stays empty until somebody uses the screens. The SQLite
 * floor stays 3.26 — no DROP COLUMN, no ALTER COLUMN — matching Version20260826120000's own
 * discipline for exactly the same reason.
 *
 * ## No foreign key to product_core from any line table that snapshots what was bought
 *
 * `rfq_line`, `rfq_vendor_reply_line` (via its rfq_line, not directly) and `debit_memo_line` all
 * carry `product_id` with no `FOREIGN KEY` behind it — the same deliberate omission
 * `purchase_order_line`/`sales_order_line` already carry, for the same reason: a document line
 * snapshots what was asked for or debited and has to outlive the product's deletion.
 *
 * `vendor_price` and `vendor_return_line` are the two exceptions, and deliberately: neither is a
 * frozen snapshot of paperwork. `vendor_price` is live reference data an admin edits in place — the
 * mirror of `product_pricing`, which also carries a real FK — and a rate for a deleted product is
 * meaningless rather than historical, so it cascades. `vendor_return_line` names the actual physical
 * goods a real movement was just written against in the same transaction; a return whose product
 * later vanishes still needs the row it moved stock for to explain itself, but that is `SalesReturnLine`'s
 * own call restated (its `product_id` is NOT NULL with no cascade-through-deletion concern raised
 * there either) — matching it exactly rather than diverging for no stated reason.
 *
 * ## Two small, deliberately narrow additions to InventoryDepthBundle, not new tables
 *
 * `InventoryMovementGroup::TYPE_VENDOR_RETURN` and `InventoryDetail::STATUS_RETURNED_TO_VENDOR` are
 * PHP-level additions to existing enumerations (VARCHAR columns, no CHECK constraint, no schema
 * change) — see those constants' own docblocks for why the new status folds into the existing
 * `write_off` bucket rather than needing one of its own. Nothing here alters `product_inventory` or
 * any bucket column.
 *
 * ## Timestamp
 *
 * Above Version20260908120000 (#642's goods_receipt rename), the previous end of the chain,
 * following the "gap above the chain end" convention Version20260826120000 documents.
 *
 * Renumbered twice while this branch was open — 090000 was taken by #635's vendor_address renames
 * and 120000 by #642's — which is why the gap above the chain end is a convention worth keeping
 * rather than picking the next round number. Ordering against those two does not matter: this
 * migration only CREATES tables. It does depend on #642 having already run, though, since
 * vendor_return's foreign keys name goods_receipt and goods_receipt_line by their new names.
 */
final class Version20260908180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#637/#638: add vendor_price, rfq(+line, vendor_reply, vendor_reply_line), vendor_return(+line), debit_memo(+line, application, refund).';
    }

    public function up(Schema $schema): void
    {
        // --- #637: vendor price list --------------------------------------------------------------
        $this->addSql("CREATE TABLE vendor_price (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, vendor_id INTEGER NOT NULL, product_id INTEGER NOT NULL, vendor_sku VARCHAR(80) DEFAULT NULL, unit_cost NUMERIC(12, 4) NOT NULL, currency VARCHAR(3) DEFAULT 'CAD' NOT NULL, order_multiple INTEGER DEFAULT 1 NOT NULL, is_active BOOLEAN DEFAULT 1 NOT NULL, notes CLOB DEFAULT NULL, created_at DATETIME NOT NULL, CONSTRAINT FK_vendor_price_vendor FOREIGN KEY (vendor_id) REFERENCES vendor (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_vendor_price_product FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)");
        $this->addSql('CREATE INDEX idx_vendor_price_product ON vendor_price (product_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_vendor_price_vendor_product ON vendor_price (vendor_id, product_id)');

        // --- #637: RFQ ------------------------------------------------------------------------------
        $this->addSql('CREATE TABLE rfq (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, document_number VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL, warehouse_id INTEGER DEFAULT NULL, notes CLOB DEFAULT NULL, created_at DATETIME NOT NULL, sent_at DATETIME DEFAULT NULL, closed_at DATETIME DEFAULT NULL, CONSTRAINT FK_rfq_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouse (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_rfq_status ON rfq (status)');
        $this->addSql('CREATE UNIQUE INDEX uniq_rfq_number ON rfq (document_number)');

        // product_id carries NO foreign key — see the class docblock.
        $this->addSql('CREATE TABLE rfq_line (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, rfq_id INTEGER NOT NULL, product_id INTEGER DEFAULT NULL, name VARCHAR(255) NOT NULL, sku VARCHAR(80) DEFAULT NULL, quantity NUMERIC(12, 2) NOT NULL, notes CLOB DEFAULT NULL, sort_order INTEGER DEFAULT 0 NOT NULL, CONSTRAINT FK_rfq_line_rfq FOREIGN KEY (rfq_id) REFERENCES rfq (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_rfq_line_rfq ON rfq_line (rfq_id)');

        // One vendor's priced answer — extends AbstractPurchaseDocument, so it carries the same
        // header columns purchase_order/vendor_bill do (vendor_name, document_date, currency,
        // subtotal, tax, total, created_at) alongside its own.
        $this->addSql("CREATE TABLE rfq_vendor_reply (vendor_name VARCHAR(200) NOT NULL, document_date VARCHAR(10) NOT NULL, currency VARCHAR(3) DEFAULT 'CAD' NOT NULL, subtotal NUMERIC(12, 2) DEFAULT '0.00' NOT NULL, tax NUMERIC(12, 2) DEFAULT '0.00' NOT NULL, total NUMERIC(12, 2) DEFAULT '0.00' NOT NULL, created_at DATETIME NOT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, rfq_id INTEGER NOT NULL, reply_number VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL, replied_at DATETIME DEFAULT NULL, notes CLOB DEFAULT NULL, purchase_order_id INTEGER DEFAULT NULL, vendor_id INTEGER NOT NULL, CONSTRAINT FK_rfq_reply_rfq FOREIGN KEY (rfq_id) REFERENCES rfq (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_rfq_reply_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_order (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_rfq_reply_vendor FOREIGN KEY (vendor_id) REFERENCES vendor (id) NOT DEFERRABLE INITIALLY IMMEDIATE)");
        $this->addSql('CREATE INDEX idx_rfq_reply_status ON rfq_vendor_reply (status)');
        $this->addSql('CREATE UNIQUE INDEX uniq_rfq_reply_number ON rfq_vendor_reply (reply_number)');
        $this->addSql('CREATE UNIQUE INDEX uniq_rfq_reply_vendor ON rfq_vendor_reply (rfq_id, vendor_id)');

        $this->addSql('CREATE TABLE rfq_vendor_reply_line (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, reply_id INTEGER NOT NULL, rfq_line_id INTEGER DEFAULT NULL, unit_cost NUMERIC(12, 4) DEFAULT NULL, subtotal NUMERIC(12, 2) DEFAULT NULL, notes CLOB DEFAULT NULL, CONSTRAINT FK_rfq_reply_line_reply FOREIGN KEY (reply_id) REFERENCES rfq_vendor_reply (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_rfq_reply_line_rfq_line FOREIGN KEY (rfq_line_id) REFERENCES rfq_line (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_rfq_reply_line_reply ON rfq_vendor_reply_line (reply_id)');

        // --- #638: vendor return ---------------------------------------------------------------------
        $this->addSql('CREATE TABLE vendor_return (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, document_number VARCHAR(32) NOT NULL, vendor_id INTEGER NOT NULL, status VARCHAR(20) NOT NULL, purchase_order_id INTEGER DEFAULT NULL, goods_receipt_id INTEGER DEFAULT NULL, warehouse_id INTEGER DEFAULT NULL, requested_at DATETIME NOT NULL, authorised_at DATETIME DEFAULT NULL, shipped_at DATETIME DEFAULT NULL, return_to_address CLOB DEFAULT NULL, reason CLOB DEFAULT NULL, notes CLOB DEFAULT NULL, CONSTRAINT FK_vendor_return_vendor FOREIGN KEY (vendor_id) REFERENCES vendor (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_vendor_return_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_order (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_vendor_return_receipt FOREIGN KEY (goods_receipt_id) REFERENCES goods_receipt (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_vendor_return_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouse (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_vendor_return_vendor ON vendor_return (vendor_id)');
        $this->addSql('CREATE INDEX idx_vendor_return_status ON vendor_return (status)');
        $this->addSql('CREATE UNIQUE INDEX uniq_vendor_return_number ON vendor_return (document_number)');

        // product_id DOES carry a foreign key here — see the class docblock: this line names the
        // actual physical goods a movement was just written against, matching sales_return_line's
        // own required, cascading product_id exactly.
        $this->addSql('CREATE TABLE vendor_return_line (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, vendor_return_id INTEGER NOT NULL, product_id INTEGER NOT NULL, goods_receipt_line_id INTEGER DEFAULT NULL, quantity NUMERIC(12, 2) NOT NULL, name VARCHAR(255) NOT NULL, sku VARCHAR(80) DEFAULT NULL, reason CLOB DEFAULT NULL, sort_order INTEGER DEFAULT 0 NOT NULL, CONSTRAINT FK_vendor_return_line_return FOREIGN KEY (vendor_return_id) REFERENCES vendor_return (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_vendor_return_line_product FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_vendor_return_line_receipt_line FOREIGN KEY (goods_receipt_line_id) REFERENCES goods_receipt_line (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_vendor_return_line_return ON vendor_return_line (vendor_return_id)');

        // --- #638: debit memo ------------------------------------------------------------------------
        $this->addSql("CREATE TABLE debit_memo (vendor_name VARCHAR(200) NOT NULL, document_date VARCHAR(10) NOT NULL, currency VARCHAR(3) DEFAULT 'CAD' NOT NULL, subtotal NUMERIC(12, 2) DEFAULT '0.00' NOT NULL, tax NUMERIC(12, 2) DEFAULT '0.00' NOT NULL, total NUMERIC(12, 2) DEFAULT '0.00' NOT NULL, created_at DATETIME NOT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, document_number VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL, vendor_bill_id INTEGER DEFAULT NULL, vendor_return_id INTEGER DEFAULT NULL, reason CLOB DEFAULT NULL, restock BOOLEAN DEFAULT 0 NOT NULL, vendor_id INTEGER NOT NULL, CONSTRAINT FK_debit_memo_bill FOREIGN KEY (vendor_bill_id) REFERENCES vendor_bill (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_debit_memo_return FOREIGN KEY (vendor_return_id) REFERENCES vendor_return (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_debit_memo_vendor FOREIGN KEY (vendor_id) REFERENCES vendor (id) NOT DEFERRABLE INITIALLY IMMEDIATE)");
        $this->addSql('CREATE INDEX idx_debit_memo_status ON debit_memo (status)');
        $this->addSql('CREATE UNIQUE INDEX uniq_debit_memo_number ON debit_memo (document_number)');

        // product_id carries NO foreign key — see the class docblock.
        $this->addSql('CREATE TABLE debit_memo_line (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, debit_memo_id INTEGER NOT NULL, vendor_bill_line_id INTEGER DEFAULT NULL, product_id INTEGER DEFAULT NULL, name VARCHAR(255) NOT NULL, sku VARCHAR(80) DEFAULT NULL, quantity NUMERIC(12, 2) NOT NULL, unit_cost NUMERIC(12, 4) NOT NULL, subtotal NUMERIC(12, 2) NOT NULL, sort_order INTEGER DEFAULT 0 NOT NULL, CONSTRAINT FK_debit_memo_line_memo FOREIGN KEY (debit_memo_id) REFERENCES debit_memo (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_debit_memo_line_bill_line FOREIGN KEY (vendor_bill_line_id) REFERENCES vendor_bill_line (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_debit_memo_line_memo ON debit_memo_line (debit_memo_id)');

        $this->addSql('CREATE TABLE debit_memo_application (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, debit_memo_id INTEGER NOT NULL, vendor_bill_id INTEGER NOT NULL, amount NUMERIC(12, 2) NOT NULL, applied_at DATE NOT NULL, CONSTRAINT FK_debit_memo_app_memo FOREIGN KEY (debit_memo_id) REFERENCES debit_memo (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_debit_memo_app_bill FOREIGN KEY (vendor_bill_id) REFERENCES vendor_bill (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_debit_memo_application_bill ON debit_memo_application (vendor_bill_id)');
        $this->addSql('CREATE INDEX idx_debit_memo_application_memo ON debit_memo_application (debit_memo_id)');

        $this->addSql('CREATE TABLE debit_memo_refund (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, debit_memo_id INTEGER NOT NULL, user_id INTEGER DEFAULT NULL, refunded_at DATE NOT NULL, method VARCHAR(64) NOT NULL, amount NUMERIC(12, 2) NOT NULL, comment VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, CONSTRAINT FK_debit_memo_refund_memo FOREIGN KEY (debit_memo_id) REFERENCES debit_memo (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_debit_memo_refund_user FOREIGN KEY (user_id) REFERENCES admin_user (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX idx_debit_memo_refund_memo ON debit_memo_refund (debit_memo_id)');
    }

    /**
     * Child tables first, so nothing is dropped while something still references it — same
     * direction Version20260826120000's own down() uses, for the same reason.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE debit_memo_refund');
        $this->addSql('DROP TABLE debit_memo_application');
        $this->addSql('DROP TABLE debit_memo_line');
        $this->addSql('DROP TABLE debit_memo');
        $this->addSql('DROP TABLE vendor_return_line');
        $this->addSql('DROP TABLE vendor_return');
        $this->addSql('DROP TABLE rfq_vendor_reply_line');
        $this->addSql('DROP TABLE rfq_vendor_reply');
        $this->addSql('DROP TABLE rfq_line');
        $this->addSql('DROP TABLE rfq');
        $this->addSql('DROP TABLE vendor_price');
    }
}
