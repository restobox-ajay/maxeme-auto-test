<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Warehouse operations: pick lists, transfer orders and bin-map coordinates (#552).
 *
 * Everything #552 adds is a faster front end on the movement layer #550 built. That shows up here
 * as what this migration does NOT contain: there is no second stock table, no allocation table, no
 * per-pick balance. What a pick or a dispatch did to stock is an `inventory_movement_group` written
 * by InventoryDepthBundle\Movement\StockMovementService, exactly like a manual adjustment, and the
 * tables below only say what was ASKED for and what was reported back.
 *
 * ## Purely additive, deliberately
 *
 * Four new tables and four `ADD COLUMN`s, every one nullable or defaulted, and not a single rebuild,
 * drop or UPDATE of an existing row. Replaying the chain over real data therefore cannot lose any of
 * it — the failure mode `bin/ci-migration-replay` exists to catch, and the one that made
 * Version20260730150000 and Version20260821090000 delicate. Nothing here relies on
 * `PRAGMA foreign_keys = OFF`, which is silently ignored inside a transaction, because nothing is
 * dropped and there is no cascade to get wrong.
 *
 * SQLite floor stays 3.26: `ADD COLUMN` with a constant default (here, NULL) needs no rebuild, and
 * no `DROP COLUMN` (3.35) or `ALTER COLUMN` appears anywhere.
 *
 * ## The map columns go on `warehouse_location`, which #550 owns
 *
 * The plan puts them there rather than in a side table, and it is the right call: a bin's zone,
 * aisle and coordinates are properties of the bin, and a parallel table keyed on `location_id` would
 * be a second place a bin can exist. All four are nullable, so a warehouse that never opens the map
 * never fills them and `sort_key` keeps driving picking on its own. Deleting THIS bundle leaves four
 * unread nullable columns on a table that still works; that is the cost, and it is the smaller one.
 *
 * ## The guards Doctrine's mapping layer cannot express
 *
 * The `CHECK` constraints below exist only in a migrated database, never in the schema either test
 * suite builds from entity metadata — the same split #550 documented. So the enforcing layer is the
 * application (the entity setters clamp at zero and the services refuse a non-positive quantity
 * outright), and these are the backstop.
 *
 * ## Timestamp
 *
 * Above Version20260823104000, which was the end of the chain, with a deliberate gap: #555
 * (procurement) is being built in parallel and will also add migrations. Two parallel stages picking
 * the same number is a merge conflict that only surfaces when they meet — it has already happened
 * once, between #539's stages 3 and 4.
 */
final class Version20260826090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#552: pick lists, transfer orders and bin-map coordinates — the working documents behind the movement layer.';
    }

    public function up(Schema $schema): void
    {
        // --- bin map coordinates, on #550's bin table -------------------------------------------
        // Nullable with no default, so SQLite adds each without a table rebuild. A bin only appears
        // on the map once it has BOTH coordinates; one of the two places it nowhere.
        $this->addSql('ALTER TABLE warehouse_location ADD COLUMN zone VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE warehouse_location ADD COLUMN aisle VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE warehouse_location ADD COLUMN map_x INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE warehouse_location ADD COLUMN map_y INTEGER DEFAULT NULL');

        // --- pick lists -------------------------------------------------------------------------
        // `staging_location_id` is where picked stock is put down, and it is the reason a confirmed
        // pick does not change the product's total: the move is bin-to-bin with the status still
        // `available`, so it recomputes to the identical number. See PickConfirmationService.
        $this->addSql('CREATE TABLE pick_list (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, number VARCHAR(32) NOT NULL, status VARCHAR(16) NOT NULL, assigned_to VARCHAR(160) DEFAULT NULL, notes CLOB DEFAULT NULL, created_at DATETIME NOT NULL, released_at DATETIME DEFAULT NULL, closed_at DATETIME DEFAULT NULL, warehouse_id INTEGER NOT NULL, staging_location_id INTEGER DEFAULT NULL, CONSTRAINT FK_pick_list_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouse (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_pick_list_staging FOREIGN KEY (staging_location_id) REFERENCES warehouse_location (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_pick_list_warehouse ON pick_list (warehouse_id)');
        $this->addSql('CREATE INDEX IDX_pick_list_staging ON pick_list (staging_location_id)');
        $this->addSql('CREATE INDEX idx_pick_list_status ON pick_list (status)');
        // A reused document number is indistinguishable from the original on a printout.
        $this->addSql('CREATE UNIQUE INDEX uniq_pick_list_number ON pick_list (number)');

        // `order_id`, `order_line_id` and `product_id` are ON DELETE SET NULL, not CASCADE.
        //
        // A pick task copies an order line, and an order line in this schema deliberately outlives
        // the product it names — `sales_order_line` carries no foreign key to `product_core` at all
        // for exactly that reason, and the dev database holds two thousand lines pointing at
        // products that no longer exist, every one a correct record of something sold. A task that
        // vanished out of a round somebody is holding a printout of would be worse than a task that
        // says what it was and refuses to confirm, which is what the sku/name/order_number snapshot
        // columns are for.
        $this->addSql('CREATE TABLE pick_task (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, order_number VARCHAR(64) NOT NULL, sku VARCHAR(80) DEFAULT NULL, name VARCHAR(255) NOT NULL, sort_key INTEGER DEFAULT 0 NOT NULL, quantity_requested INTEGER NOT NULL CHECK (quantity_requested > 0), quantity_picked INTEGER DEFAULT 0 NOT NULL CHECK (quantity_picked >= 0), quantity_missing INTEGER DEFAULT 0 NOT NULL CHECK (quantity_missing >= 0), pick_list_id INTEGER NOT NULL, order_id INTEGER DEFAULT NULL, order_line_id INTEGER DEFAULT NULL, product_id INTEGER DEFAULT NULL, suggested_location_id INTEGER DEFAULT NULL, CONSTRAINT FK_pick_task_list FOREIGN KEY (pick_list_id) REFERENCES pick_list (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_pick_task_order FOREIGN KEY (order_id) REFERENCES sales_order (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_pick_task_order_line FOREIGN KEY (order_line_id) REFERENCES sales_order_line (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_pick_task_product FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_pick_task_location FOREIGN KEY (suggested_location_id) REFERENCES warehouse_location (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        // The route: sort_key ascending across every order on the list, which is the whole reason
        // batch picking is faster than picking each order in turn.
        $this->addSql('CREATE INDEX idx_pick_task_list_route ON pick_task (pick_list_id, sort_key)');
        $this->addSql('CREATE INDEX idx_pick_task_order ON pick_task (order_id)');
        $this->addSql('CREATE INDEX IDX_pick_task_order_line ON pick_task (order_line_id)');
        $this->addSql('CREATE INDEX IDX_pick_task_product ON pick_task (product_id)');
        $this->addSql('CREATE INDEX IDX_pick_task_location ON pick_task (suggested_location_id)');

        // --- transfer orders --------------------------------------------------------------------
        $this->addSql('CREATE TABLE transfer_order (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, number VARCHAR(32) NOT NULL, status VARCHAR(16) NOT NULL, dispatched_at DATETIME DEFAULT NULL, received_at DATETIME DEFAULT NULL, notes CLOB DEFAULT NULL, created_at DATETIME NOT NULL, from_warehouse_id INTEGER NOT NULL, to_warehouse_id INTEGER NOT NULL, CONSTRAINT FK_transfer_order_from FOREIGN KEY (from_warehouse_id) REFERENCES warehouse (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_transfer_order_to FOREIGN KEY (to_warehouse_id) REFERENCES warehouse (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_transfer_order_from ON transfer_order (from_warehouse_id)');
        $this->addSql('CREATE INDEX IDX_transfer_order_to ON transfer_order (to_warehouse_id)');
        $this->addSql('CREATE INDEX idx_transfer_order_status ON transfer_order (status)');
        $this->addSql('CREATE UNIQUE INDEX uniq_transfer_order_number ON transfer_order (number)');

        // `quantity_dispatched` and `quantity_received` are separate columns because they genuinely
        // differ: the gap between them is stock lost in transit, and it must stay visible rather
        // than be reconciled away into a single number that quietly becomes whatever arrived.
        $this->addSql('CREATE TABLE transfer_order_line (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, serial VARCHAR(120) DEFAULT NULL, sku VARCHAR(80) DEFAULT NULL, name VARCHAR(255) NOT NULL, quantity_requested INTEGER NOT NULL CHECK (quantity_requested > 0), quantity_dispatched INTEGER DEFAULT 0 NOT NULL CHECK (quantity_dispatched >= 0), quantity_received INTEGER DEFAULT 0 NOT NULL CHECK (quantity_received >= 0), transfer_order_id INTEGER NOT NULL, product_id INTEGER NOT NULL, lot_id INTEGER DEFAULT NULL, CONSTRAINT FK_transfer_line_order FOREIGN KEY (transfer_order_id) REFERENCES transfer_order (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_transfer_line_product FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_transfer_line_lot FOREIGN KEY (lot_id) REFERENCES inventory_lot (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_transfer_line_order ON transfer_order_line (transfer_order_id)');
        $this->addSql('CREATE INDEX idx_transfer_line_product ON transfer_order_line (product_id)');
        $this->addSql('CREATE INDEX IDX_transfer_line_lot ON transfer_order_line (lot_id)');
    }

    public function down(Schema $schema): void
    {
        // The four tables go; the movements they caused do not, and never could — they live in
        // `inventory_movement_group` and `inventory_detail`, written by StockMovementService in the
        // same transaction as the numbers they changed. Dropping these loses the paperwork, not the
        // stock, which is the same shape as #550's own down().
        $this->addSql('DROP TABLE transfer_order_line');
        $this->addSql('DROP TABLE transfer_order');
        $this->addSql('DROP TABLE pick_task');
        $this->addSql('DROP TABLE pick_list');

        // The four map columns stay. SQLite's floor here is 3.26 and DROP COLUMN arrives in 3.35, so
        // the only way to remove them is a full rebuild of `warehouse_location` — and that table is
        // the parent of `inventory_detail.location_id` and `pick_task.suggested_location_id`, both
        // of which would have to survive a DROP TABLE that `PRAGMA foreign_keys = OFF` cannot
        // reliably disarm inside a transaction. Four unread nullable columns are not worth that
        // risk, and unlike #550's `inventory_mode` they are inert: nothing interprets a zone or a
        // coordinate, so leaving the values is not leaving state the application would disagree
        // with. They are also not blanked, because a down() is not a request to throw away the map
        // somebody drew.
    }
}
