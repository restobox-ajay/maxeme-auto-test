<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Inventory depth: warehouse, bin, lot, serial, expiry (#550).
 *
 * Adds a layer that says *where* `product_inventory.quantity` physically is — which bin, which lot,
 * which serial, what condition — **without changing what the number means**:
 *
 *     product_inventory(product, warehouse).quantity
 *       == SUM(inventory_detail.quantity) WHERE status = 'available' AND warehouse = that warehouse
 *
 * ## Why this migration is purely additive, and why that matters
 *
 * Nothing here rebuilds, drops or rewrites an existing table, and nothing writes an existing row.
 * Five new tables and one `ADD COLUMN`, all with defaults, so replaying the chain over real data
 * cannot lose any of it — which is the failure mode `bin/ci-migration-replay` exists to catch and
 * the one that made Version20260730150000 and Version20260821090000 delicate.
 *
 * `ADD COLUMN` is cheap in SQLite and needs no rebuild as long as the default is a constant. The
 * floor here is **3.26**: no `DROP COLUMN` (3.35), no `ALTER COLUMN`. Partial and expression indexes
 * are 3.8/3.9 and therefore available. Nothing below relies on `PRAGMA foreign_keys = OFF`, which is
 * silently ignored inside a transaction — there is nothing to cascade, because nothing is dropped.
 *
 * ## `product_core.inventory_mode` is core's column, deliberately
 *
 * It sits on `product_core` rather than in a bundle-owned table because core has to decide whether
 * to render an editable quantity field, and it cannot ask the bundle that without depending on it.
 * Every existing row gets `'simple'` — the mode that means "nothing changes" — so the column is
 * inert until somebody opts a product in. And `'dimensional'` only *means* anything while a
 * App\Contract\Inventory\DimensionalInventoryProviderInterface is registered: with the bundle
 * deleted, App\Service\Inventory\InventoryModeResolver reads a stored `'dimensional'` back as
 * `'simple'`, so the column is never a lockout.
 *
 * ## The three guards Doctrine's mapping layer cannot express
 *
 * Emitted here and nowhere else, which means a database built by replaying this chain has them and
 * a database built from entity metadata (which is how BOTH test suites build theirs) does not:
 *
 *  1. `uniq_inventory_detail` — over `COALESCE(location_id,0), COALESCE(lot_id,0), COALESCE(serial,'')`.
 *     A plain unique index would not collapse NULLs, which SQLite treats as distinct, so it would
 *     permit exactly the duplicate rows it is supposed to forbid.
 *  2. `uniq_live_serial` — partial, `WHERE serial IS NOT NULL AND quantity > 0`. A serial may have
 *     many rows across its life but only ever ONE holding stock. This is what makes double-selling
 *     a serial impossible at the database level.
 *  3. `CHECK (quantity >= 0)` on `inventory_detail`, and `CHECK (quantity > 0)` on
 *     `inventory_movement`. Nothing guards non-serial quantity otherwise: two pickers each taking 20
 *     from a row of 25 reaches −15 with nothing complaining.
 *
 * Because the tests cannot see any of them, the *enforcing* layer is deliberately the application:
 * `InventoryDetailRepository::findOrCreate()` is the only way a row is made, and the decrement is a
 * conditional `UPDATE ... WHERE id = :id AND quantity >= :n` whose affected-row count is checked.
 * The `CHECK` stops the write; the conditional `UPDATE` turns it into "someone got there first"
 * instead of a constraint exception in a picker's face. Both halves are needed, and the schema is
 * the backstop rather than the mechanism — which is also what keeps the two schemas behaving the
 * same way.
 *
 * ## Timestamp
 *
 * Chosen well above Version20260821090000 (#546, the previous end of the chain) with a deliberate
 * gap, because #548 (back orders) is being built in parallel and also adds migrations. Two parallel
 * stages picking the same number is a merge conflict that only surfaces when they meet.
 */
final class Version20260821160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#550: add the inventory depth layer — bins, lots, serials, statuses and the movement ledger beneath product_inventory.quantity.';
    }

    public function up(Schema $schema): void
    {
        // --- opt-in flag, on core ---------------------------------------------------------------
        // Constant default, so SQLite does this without a table rebuild.
        $this->addSql("ALTER TABLE product_core ADD COLUMN inventory_mode VARCHAR(16) DEFAULT 'simple' NOT NULL");

        // --- bins -------------------------------------------------------------------------------
        $this->addSql("CREATE TABLE warehouse_location (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, code VARCHAR(64) NOT NULL, type VARCHAR(16) NOT NULL, sort_key INTEGER DEFAULT 0 NOT NULL, status VARCHAR(16) DEFAULT 'Active' NOT NULL, warehouse_id INTEGER NOT NULL, CONSTRAINT FK_7ED6EB6D5080ECDE FOREIGN KEY (warehouse_id) REFERENCES warehouse (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)");
        $this->addSql('CREATE INDEX IDX_7ED6EB6D5080ECDE ON warehouse_location (warehouse_id)');
        // Pick-path traversal order: what a picker's round and a cycle count both walk.
        $this->addSql('CREATE INDEX idx_wh_location_pick_path ON warehouse_location (warehouse_id, sort_key)');
        $this->addSql('CREATE UNIQUE INDEX uniq_wh_location ON warehouse_location (warehouse_id, code)');

        // --- lots -------------------------------------------------------------------------------
        $this->addSql('CREATE TABLE inventory_lot (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, code VARCHAR(64) NOT NULL, expiry DATE DEFAULT NULL, received_at DATETIME DEFAULT NULL, source VARCHAR(160) DEFAULT NULL, product_id INTEGER NOT NULL, CONSTRAINT FK_72EB467F4584665A FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_72EB467F4584665A ON inventory_lot (product_id)');
        // Deliberately NOT unique on (product_id, code): vendors reuse batch codes across production
        // runs with different expiry dates, and UNIQUE would force two genuinely different batches
        // into one row. A lot's identity is its row id, which is why every screen shows code AND
        // expiry — the code alone is ambiguous to the person holding the box.
        $this->addSql('CREATE INDEX idx_lot_lookup ON inventory_lot (product_id, code)');
        $this->addSql('CREATE INDEX idx_lot_expiry ON inventory_lot (product_id, expiry)');

        // --- the detail rows themselves ---------------------------------------------------------
        // CHECK (quantity >= 0) is in the CREATE because SQLite cannot add a constraint later
        // without a full table rebuild, and this is the one chance to have it for free.
        $this->addSql('CREATE TABLE inventory_detail (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, serial VARCHAR(120) DEFAULT NULL, status VARCHAR(16) NOT NULL, quantity INTEGER DEFAULT 0 NOT NULL CHECK (quantity >= 0), updated_at DATETIME NOT NULL, product_id INTEGER NOT NULL, warehouse_id INTEGER NOT NULL, location_id INTEGER DEFAULT NULL, lot_id INTEGER DEFAULT NULL, CONSTRAINT FK_2EDAE3384584665A FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2EDAE3385080ECDE FOREIGN KEY (warehouse_id) REFERENCES warehouse (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2EDAE33864D218E FOREIGN KEY (location_id) REFERENCES warehouse_location (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2EDAE338A8CBA5F7 FOREIGN KEY (lot_id) REFERENCES inventory_lot (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_2EDAE3384584665A ON inventory_detail (product_id)');
        $this->addSql('CREATE INDEX IDX_2EDAE3385080ECDE ON inventory_detail (warehouse_id)');
        $this->addSql('CREATE INDEX IDX_2EDAE338A8CBA5F7 ON inventory_detail (lot_id)');
        $this->addSql('CREATE INDEX idx_detail_lookup ON inventory_detail (product_id, warehouse_id, status)');
        $this->addSql('CREATE INDEX idx_detail_location ON inventory_detail (location_id)');
        $this->addSql('CREATE INDEX idx_detail_serial ON inventory_detail (serial)');

        // A detail row IS its (product, warehouse, location, lot, serial, status) combination.
        // COALESCE because SQLite treats NULLs as distinct in a unique index, so the plain form
        // would happily allow two "bin unspecified, lot unspecified, available" rows for the same
        // product — the exact duplicate this forbids.
        $this->addSql("CREATE UNIQUE INDEX uniq_inventory_detail ON inventory_detail (product_id, warehouse_id, COALESCE(location_id, 0), COALESCE(lot_id, 0), COALESCE(serial, ''), status)");
        // A serial may have many rows across its life, but only ever ONE with quantity > 0.
        $this->addSql('CREATE UNIQUE INDEX uniq_live_serial ON inventory_detail (product_id, serial) WHERE serial IS NOT NULL AND quantity > 0');
        // The hot read: what is sellable, per product per warehouse.
        $this->addSql("CREATE INDEX idx_detail_available ON inventory_detail (product_id, warehouse_id) WHERE status = 'available' AND quantity > 0");

        // --- the ledger -------------------------------------------------------------------------
        $this->addSql('CREATE TABLE inventory_movement_group (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, client_operation_id VARCHAR(64) NOT NULL, type VARCHAR(16) NOT NULL, reason VARCHAR(255) DEFAULT NULL, actor VARCHAR(160) DEFAULT NULL, reference VARCHAR(160) DEFAULT NULL, occurred_at DATETIME NOT NULL)');
        $this->addSql('CREATE INDEX idx_movement_group_occurred ON inventory_movement_group (occurred_at)');
        $this->addSql('CREATE INDEX idx_movement_group_type ON inventory_movement_group (type)');
        // The idempotency key: a resubmitted form or a retried command re-applies nothing.
        $this->addSql('CREATE UNIQUE INDEX uniq_movement_group_op ON inventory_movement_group (client_operation_id)');

        $this->addSql('CREATE TABLE inventory_movement (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, quantity INTEGER NOT NULL CHECK (quantity > 0), group_id INTEGER NOT NULL, product_id INTEGER NOT NULL, from_detail_id INTEGER DEFAULT NULL, to_detail_id INTEGER DEFAULT NULL, CONSTRAINT FK_40972F66FE54D947 FOREIGN KEY (group_id) REFERENCES inventory_movement_group (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_40972F664584665A FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_40972F66C84C9601 FOREIGN KEY (from_detail_id) REFERENCES inventory_detail (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_40972F66E973D4CA FOREIGN KEY (to_detail_id) REFERENCES inventory_detail (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_40972F66FE54D947 ON inventory_movement (group_id)');
        $this->addSql('CREATE INDEX idx_movement_from ON inventory_movement (from_detail_id)');
        $this->addSql('CREATE INDEX idx_movement_to ON inventory_movement (to_detail_id)');
        $this->addSql('CREATE INDEX idx_movement_product ON inventory_movement (product_id)');
    }

    public function down(Schema $schema): void
    {
        // Dropping the five tables loses the breakdown, not the number: every
        // `product_inventory.quantity` was maintained in the same transaction as the movement that
        // changed it and is already sitting there correct. A product at 47 across three bins is
        // still a product at 47.
        //
        // `inventory_mode` cannot come off — SQLite's floor here is 3.26 and DROP COLUMN arrives in
        // 3.35 — so it is reset to 'simple' instead. Correct rather than merely convenient: with the
        // tables gone there is nothing that could honour 'dimensional', and leaving the value would
        // leave a product pointing at a breakdown that no longer exists. The reset is also exactly
        // what the application would compute for those rows, so this does not leave state its own
        // code would immediately disagree with.
        $this->addSql("UPDATE product_core SET inventory_mode = 'simple' WHERE inventory_mode <> 'simple'");

        $this->addSql('DROP TABLE inventory_movement');
        $this->addSql('DROP TABLE inventory_movement_group');
        $this->addSql('DROP TABLE inventory_detail');
        $this->addSql('DROP TABLE inventory_lot');
        $this->addSql('DROP TABLE warehouse_location');
    }
}
