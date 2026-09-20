<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Split the warehouse out of the fulfillment region (#546).
 *
 * `fulfillment_region` was doing two unrelated jobs: it was the row stock was counted against AND
 * the row that decided which companies may buy, what guests see and at what price. Stock sits in a
 * building, not in a sales territory. Afterwards:
 *
 *   warehouse ──── warehouse_fulfillment_region ──── fulfillment_region
 *   stock            UNIQUE(fulfillment_region_id)    companies, guest visibility, pricing
 *
 * Ids are preserved on both sides and the pairing is id-to-id, so every existing foreign key value
 * stays valid and nothing has to be re-pointed by lookup.
 *
 * ## Why this creates `warehouse` instead of renaming `fulfillment_region` to it
 *
 * The plan called for `ALTER TABLE fulfillment_region RENAME TO warehouse`, on the reasoning that
 * SQLite rewrites the REFERENCES clause in all six referencing tables and gets four of them right,
 * leaving two rebuilds instead of four. That arithmetic no longer holds:
 *
 *  - #539 added a SEVENTH referencing table, `invoice_inventory_reservation`, so the rename gets
 *    five right and two wrong.
 *  - The commercial columns then have to come off `warehouse`, and SQLite's floor here is 3.26 —
 *    no `DROP COLUMN` until 3.35. So the rename route needs a rebuild of the PARENT table too, and
 *    `DROP TABLE warehouse` mid-rebuild is the exact hazard bin/ci-migration-replay exists to
 *    catch: every child carries ON DELETE CASCADE, and `PRAGMA foreign_keys = OFF` is silently
 *    ignored inside a transaction. It would also leave every child's REFERENCES clause pointing at
 *    a table that does not exist for the duration, which is a state ALTER TABLE RENAME refuses to
 *    work in.
 *  - Leaving those columns in place instead is not available either: `guest_visible` and
 *    `default_for_new_company` are NOT NULL with no default, so the first INSERT of a Warehouse —
 *    which no longer maps them — would fail.
 *
 * So the rename route is one hazardous parent rebuild plus two child rebuilds plus five column
 * renames. This route is five child rebuilds and no parent rebuild at all: nothing is dropped that
 * anything else references, so there is no cascade to get wrong.
 *
 * The payoff on the other side is that `fulfillment_region`, `company_fulfillment_region` and
 * `cart_item` are not touched by this migration in any way. Those two tables were the ones the
 * plan expected to rebuild, precisely to undo what the rename did to them; not doing the rename
 * means there is nothing to undo. Their `fulfillment_region_id` columns still name the same
 * column and still point at the same table, holding the same integers.
 *
 * ## The rebuilt tables
 *
 * The five that count stock: `product_inventory`, `order_inventory_reservation`,
 * `invoice_inventory_reservation`, `inventory_bucket_change_log` and
 * `inventory_reconciliation_discrepancy`. Each is create-copy-drop-rename, which is the only way
 * SQLite can change a foreign key. Nothing references any of them, so dropping the old one
 * cascades to nothing.
 *
 * Both reservation copies carry `product_id IN (SELECT id FROM product_core)`. Those tables
 * declare ON DELETE CASCADE on `product_id`, so a row naming a product that no longer exists is
 * not a state either table can hold — but a database that somehow carries one would make this
 * migration unrunnable rather than merely wrong, and the chain has to survive real data.
 *
 * ## One-to-one, in a many-to-many shape
 *
 * `warehouse_fulfillment_region` is a real join table and `uniq_wfr_region` is the only thing
 * holding the mapping at one-to-one. Enabling a region served by more than one warehouse is
 * dropping that index plus an allocation policy in the application — never a data migration.
 * `priority` is there for that day; every row created here gets 0 and nothing reads it yet.
 */
final class Version20260821090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#546: split the physical warehouse out of the commercial fulfillment region, joined one-to-one.';
    }

    public function up(Schema $schema): void
    {
        // The building. Same ids as the region it came from, so every foreign key value that
        // pointed at fulfillment_region is already correct for warehouse.
        $this->addSql('CREATE TABLE warehouse (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(160) NOT NULL, status VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL)');
        $this->addSql('INSERT INTO warehouse (id, name, status, created_at) SELECT id, name, status, created_at FROM fulfillment_region');

        // The mapping. Id-to-id because until a moment ago they were one row.
        $this->addSql('CREATE TABLE warehouse_fulfillment_region (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, priority INTEGER DEFAULT 0 NOT NULL, warehouse_id INTEGER NOT NULL, fulfillment_region_id INTEGER NOT NULL, CONSTRAINT FK_E3954FD05080ECDE FOREIGN KEY (warehouse_id) REFERENCES warehouse (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_E3954FD048027C30 FOREIGN KEY (fulfillment_region_id) REFERENCES fulfillment_region (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_E3954FD05080ECDE ON warehouse_fulfillment_region (warehouse_id)');
        // The ONLY thing making this one-to-one. Dropping it enables many-to-many.
        $this->addSql('CREATE UNIQUE INDEX uniq_wfr_region ON warehouse_fulfillment_region (fulfillment_region_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_wfr_pair ON warehouse_fulfillment_region (warehouse_id, fulfillment_region_id)');
        $this->addSql('INSERT INTO warehouse_fulfillment_region (warehouse_id, fulfillment_region_id, priority) SELECT id, id, 0 FROM fulfillment_region');

        // --- the five stock tables, re-pointed at warehouse -------------------------------------

        $this->addSql('CREATE TABLE product_inventory_wh (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, quantity INTEGER NOT NULL, reserved_quantity INTEGER NOT NULL, cart_hold_quantity INTEGER NOT NULL, sales_hold_quantity INTEGER NOT NULL, pending_quantity INTEGER NOT NULL, approved_quantity INTEGER NOT NULL, manual_adjustment INTEGER NOT NULL, updated_at DATETIME NOT NULL, product_id INTEGER NOT NULL, warehouse_id INTEGER DEFAULT NULL, CONSTRAINT FK_DF8DFCBB4584665A FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_DF8DFCBB5080ECDE FOREIGN KEY (warehouse_id) REFERENCES warehouse (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO product_inventory_wh (id, quantity, reserved_quantity, cart_hold_quantity, sales_hold_quantity, pending_quantity, approved_quantity, manual_adjustment, updated_at, product_id, warehouse_id) SELECT id, quantity, reserved_quantity, cart_hold_quantity, sales_hold_quantity, pending_quantity, approved_quantity, manual_adjustment, updated_at, product_id, fulfillment_region_id FROM product_inventory WHERE product_id IN (SELECT id FROM product_core)');
        $this->addSql('DROP TABLE product_inventory');
        $this->addSql('ALTER TABLE product_inventory_wh RENAME TO product_inventory');
        $this->addSql('CREATE INDEX IDX_DF8DFCBB4584665A ON product_inventory (product_id)');
        $this->addSql('CREATE INDEX IDX_DF8DFCBB5080ECDE ON product_inventory (warehouse_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_product_inventory_product_location ON product_inventory (product_id, warehouse_id)');

        $this->addSql('CREATE TABLE order_inventory_reservation_wh (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, bucket VARCHAR(20) NOT NULL, quantity INTEGER NOT NULL, synced_quantity INTEGER NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, order_id INTEGER NOT NULL, product_id INTEGER NOT NULL, warehouse_id INTEGER NOT NULL, CONSTRAINT FK_33119368D9F6D38 FOREIGN KEY (order_id) REFERENCES sales_order (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_33119364584665A FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_33119365080ECDE FOREIGN KEY (warehouse_id) REFERENCES warehouse (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO order_inventory_reservation_wh (id, bucket, quantity, synced_quantity, created_at, updated_at, order_id, product_id, warehouse_id) SELECT id, bucket, quantity, synced_quantity, created_at, updated_at, order_id, product_id, fulfillment_region_id FROM order_inventory_reservation WHERE product_id IN (SELECT id FROM product_core)');
        $this->addSql('DROP TABLE order_inventory_reservation');
        $this->addSql('ALTER TABLE order_inventory_reservation_wh RENAME TO order_inventory_reservation');
        $this->addSql('CREATE INDEX IDX_33119368D9F6D38 ON order_inventory_reservation (order_id)');
        $this->addSql('CREATE INDEX IDX_33119364584665A ON order_inventory_reservation (product_id)');
        $this->addSql('CREATE INDEX IDX_33119365080ECDE ON order_inventory_reservation (warehouse_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_reservation_order_product_warehouse ON order_inventory_reservation (order_id, product_id, warehouse_id)');

        $this->addSql('CREATE TABLE invoice_inventory_reservation_wh (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, bucket VARCHAR(20) NOT NULL, quantity INTEGER NOT NULL, synced_quantity INTEGER NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, invoice_id INTEGER NOT NULL, product_id INTEGER NOT NULL, warehouse_id INTEGER NOT NULL, CONSTRAINT FK_5905971C2989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5905971C4584665A FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5905971C5080ECDE FOREIGN KEY (warehouse_id) REFERENCES warehouse (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO invoice_inventory_reservation_wh (id, bucket, quantity, synced_quantity, created_at, updated_at, invoice_id, product_id, warehouse_id) SELECT id, bucket, quantity, synced_quantity, created_at, updated_at, invoice_id, product_id, fulfillment_region_id FROM invoice_inventory_reservation WHERE product_id IN (SELECT id FROM product_core)');
        $this->addSql('DROP TABLE invoice_inventory_reservation');
        $this->addSql('ALTER TABLE invoice_inventory_reservation_wh RENAME TO invoice_inventory_reservation');
        $this->addSql('CREATE INDEX IDX_5905971C2989F1FD ON invoice_inventory_reservation (invoice_id)');
        $this->addSql('CREATE INDEX IDX_5905971C4584665A ON invoice_inventory_reservation (product_id)');
        $this->addSql('CREATE INDEX IDX_5905971C5080ECDE ON invoice_inventory_reservation (warehouse_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_invoice_reservation_invoice_product_warehouse ON invoice_inventory_reservation (invoice_id, product_id, warehouse_id)');

        $this->addSql('CREATE TABLE inventory_bucket_change_log_wh (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, bucket VARCHAR(20) NOT NULL, previous_quantity INTEGER NOT NULL, new_quantity INTEGER NOT NULL, "action" VARCHAR(60) NOT NULL, triggered_by VARCHAR(190) NOT NULL, occurred_at DATETIME NOT NULL, product_id INTEGER NOT NULL, warehouse_id INTEGER NOT NULL, CONSTRAINT FK_C211BCE94584665A FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_C211BCE95080ECDE FOREIGN KEY (warehouse_id) REFERENCES warehouse (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO inventory_bucket_change_log_wh (id, bucket, previous_quantity, new_quantity, "action", triggered_by, occurred_at, product_id, warehouse_id) SELECT id, bucket, previous_quantity, new_quantity, "action", triggered_by, occurred_at, product_id, fulfillment_region_id FROM inventory_bucket_change_log WHERE product_id IN (SELECT id FROM product_core)');
        $this->addSql('DROP TABLE inventory_bucket_change_log');
        $this->addSql('ALTER TABLE inventory_bucket_change_log_wh RENAME TO inventory_bucket_change_log');
        $this->addSql('CREATE INDEX IDX_C211BCE94584665A ON inventory_bucket_change_log (product_id)');
        $this->addSql('CREATE INDEX IDX_C211BCE95080ECDE ON inventory_bucket_change_log (warehouse_id)');

        $this->addSql('CREATE TABLE inventory_reconciliation_discrepancy_wh (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, bucket VARCHAR(20) NOT NULL, cached_quantity INTEGER NOT NULL, recomputed_quantity INTEGER NOT NULL, source VARCHAR(60) NOT NULL, occurred_at DATETIME NOT NULL, product_id INTEGER NOT NULL, warehouse_id INTEGER NOT NULL, CONSTRAINT FK_AC550DBF4584665A FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_AC550DBF5080ECDE FOREIGN KEY (warehouse_id) REFERENCES warehouse (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO inventory_reconciliation_discrepancy_wh (id, bucket, cached_quantity, recomputed_quantity, source, occurred_at, product_id, warehouse_id) SELECT id, bucket, cached_quantity, recomputed_quantity, source, occurred_at, product_id, fulfillment_region_id FROM inventory_reconciliation_discrepancy WHERE product_id IN (SELECT id FROM product_core)');
        $this->addSql('DROP TABLE inventory_reconciliation_discrepancy');
        $this->addSql('ALTER TABLE inventory_reconciliation_discrepancy_wh RENAME TO inventory_reconciliation_discrepancy');
        $this->addSql('CREATE INDEX IDX_AC550DBF4584665A ON inventory_reconciliation_discrepancy (product_id)');
        $this->addSql('CREATE INDEX IDX_AC550DBF5080ECDE ON inventory_reconciliation_discrepancy (warehouse_id)');
    }

    public function down(Schema $schema): void
    {
        // Reversible because the split is id-preserving: warehouse.id IS the fulfillment_region.id
        // it was cut from, so putting the five stock tables back on fulfillment_region is a column
        // rename in the copy, not a lookup. Any warehouse created AFTER this migration ran has a
        // region of the same id (nothing creates one without the other), so the values still
        // resolve; the mapping rows and the warehouse table itself are what is lost.
        $this->addSql('CREATE TABLE product_inventory_fr (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, quantity INTEGER NOT NULL, reserved_quantity INTEGER NOT NULL, cart_hold_quantity INTEGER NOT NULL, sales_hold_quantity INTEGER NOT NULL, pending_quantity INTEGER NOT NULL, approved_quantity INTEGER NOT NULL, manual_adjustment INTEGER NOT NULL, updated_at DATETIME NOT NULL, product_id INTEGER NOT NULL, fulfillment_region_id INTEGER DEFAULT NULL, CONSTRAINT FK_DF8DFCBB4584665A FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_DF8DFCBB48027C30 FOREIGN KEY (fulfillment_region_id) REFERENCES fulfillment_region (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO product_inventory_fr (id, quantity, reserved_quantity, cart_hold_quantity, sales_hold_quantity, pending_quantity, approved_quantity, manual_adjustment, updated_at, product_id, fulfillment_region_id) SELECT id, quantity, reserved_quantity, cart_hold_quantity, sales_hold_quantity, pending_quantity, approved_quantity, manual_adjustment, updated_at, product_id, warehouse_id FROM product_inventory');
        $this->addSql('DROP TABLE product_inventory');
        $this->addSql('ALTER TABLE product_inventory_fr RENAME TO product_inventory');
        $this->addSql('CREATE INDEX IDX_DF8DFCBB4584665A ON product_inventory (product_id)');
        $this->addSql('CREATE INDEX IDX_DF8DFCBB48027C30 ON product_inventory (fulfillment_region_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_product_inventory_product_location ON product_inventory (product_id, fulfillment_region_id)');

        $this->addSql('CREATE TABLE order_inventory_reservation_fr (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, bucket VARCHAR(20) NOT NULL, quantity INTEGER NOT NULL, synced_quantity INTEGER NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, order_id INTEGER NOT NULL, product_id INTEGER NOT NULL, fulfillment_region_id INTEGER NOT NULL, CONSTRAINT FK_33119368D9F6D38 FOREIGN KEY (order_id) REFERENCES sales_order (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_33119364584665A FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_331193648027C30 FOREIGN KEY (fulfillment_region_id) REFERENCES fulfillment_region (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO order_inventory_reservation_fr (id, bucket, quantity, synced_quantity, created_at, updated_at, order_id, product_id, fulfillment_region_id) SELECT id, bucket, quantity, synced_quantity, created_at, updated_at, order_id, product_id, warehouse_id FROM order_inventory_reservation');
        $this->addSql('DROP TABLE order_inventory_reservation');
        $this->addSql('ALTER TABLE order_inventory_reservation_fr RENAME TO order_inventory_reservation');
        $this->addSql('CREATE INDEX IDX_33119368D9F6D38 ON order_inventory_reservation (order_id)');
        $this->addSql('CREATE INDEX IDX_33119364584665A ON order_inventory_reservation (product_id)');
        $this->addSql('CREATE INDEX IDX_331193648027C30 ON order_inventory_reservation (fulfillment_region_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_reservation_order_product_region ON order_inventory_reservation (order_id, product_id, fulfillment_region_id)');

        $this->addSql('CREATE TABLE invoice_inventory_reservation_fr (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, bucket VARCHAR(20) NOT NULL, quantity INTEGER NOT NULL, synced_quantity INTEGER NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, invoice_id INTEGER NOT NULL, product_id INTEGER NOT NULL, fulfillment_region_id INTEGER NOT NULL, CONSTRAINT FK_5905971C2989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5905971C4584665A FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_5905971C48027C30 FOREIGN KEY (fulfillment_region_id) REFERENCES fulfillment_region (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO invoice_inventory_reservation_fr (id, bucket, quantity, synced_quantity, created_at, updated_at, invoice_id, product_id, fulfillment_region_id) SELECT id, bucket, quantity, synced_quantity, created_at, updated_at, invoice_id, product_id, warehouse_id FROM invoice_inventory_reservation');
        $this->addSql('DROP TABLE invoice_inventory_reservation');
        $this->addSql('ALTER TABLE invoice_inventory_reservation_fr RENAME TO invoice_inventory_reservation');
        $this->addSql('CREATE INDEX IDX_5905971C2989F1FD ON invoice_inventory_reservation (invoice_id)');
        $this->addSql('CREATE INDEX IDX_5905971C4584665A ON invoice_inventory_reservation (product_id)');
        $this->addSql('CREATE INDEX IDX_5905971C48027C30 ON invoice_inventory_reservation (fulfillment_region_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_invoice_reservation_invoice_product_region ON invoice_inventory_reservation (invoice_id, product_id, fulfillment_region_id)');

        $this->addSql('CREATE TABLE inventory_bucket_change_log_fr (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, bucket VARCHAR(20) NOT NULL, previous_quantity INTEGER NOT NULL, new_quantity INTEGER NOT NULL, "action" VARCHAR(60) NOT NULL, triggered_by VARCHAR(190) NOT NULL, occurred_at DATETIME NOT NULL, product_id INTEGER NOT NULL, fulfillment_region_id INTEGER NOT NULL, CONSTRAINT FK_C211BCE94584665A FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_C211BCE948027C30 FOREIGN KEY (fulfillment_region_id) REFERENCES fulfillment_region (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO inventory_bucket_change_log_fr (id, bucket, previous_quantity, new_quantity, "action", triggered_by, occurred_at, product_id, fulfillment_region_id) SELECT id, bucket, previous_quantity, new_quantity, "action", triggered_by, occurred_at, product_id, warehouse_id FROM inventory_bucket_change_log');
        $this->addSql('DROP TABLE inventory_bucket_change_log');
        $this->addSql('ALTER TABLE inventory_bucket_change_log_fr RENAME TO inventory_bucket_change_log');
        $this->addSql('CREATE INDEX IDX_C211BCE94584665A ON inventory_bucket_change_log (product_id)');
        $this->addSql('CREATE INDEX IDX_C211BCE948027C30 ON inventory_bucket_change_log (fulfillment_region_id)');

        $this->addSql('CREATE TABLE inventory_reconciliation_discrepancy_fr (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, bucket VARCHAR(20) NOT NULL, cached_quantity INTEGER NOT NULL, recomputed_quantity INTEGER NOT NULL, source VARCHAR(60) NOT NULL, occurred_at DATETIME NOT NULL, product_id INTEGER NOT NULL, fulfillment_region_id INTEGER NOT NULL, CONSTRAINT FK_AC550DBF4584665A FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_AC550DBF48027C30 FOREIGN KEY (fulfillment_region_id) REFERENCES fulfillment_region (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO inventory_reconciliation_discrepancy_fr (id, bucket, cached_quantity, recomputed_quantity, source, occurred_at, product_id, fulfillment_region_id) SELECT id, bucket, cached_quantity, recomputed_quantity, source, occurred_at, product_id, warehouse_id FROM inventory_reconciliation_discrepancy');
        $this->addSql('DROP TABLE inventory_reconciliation_discrepancy');
        $this->addSql('ALTER TABLE inventory_reconciliation_discrepancy_fr RENAME TO inventory_reconciliation_discrepancy');
        $this->addSql('CREATE INDEX IDX_AC550DBF4584665A ON inventory_reconciliation_discrepancy (product_id)');
        $this->addSql('CREATE INDEX IDX_AC550DBF48027C30 ON inventory_reconciliation_discrepancy (fulfillment_region_id)');

        $this->addSql('DROP TABLE warehouse_fulfillment_region');
        $this->addSql('DROP TABLE warehouse');
    }
}
