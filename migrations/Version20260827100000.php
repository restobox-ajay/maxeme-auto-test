<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops `CHECK (quantity >= 0)` from inventory_detail so a count can go short (#565).
 *
 * The design's central case requires a state the shipped constraint forbade. When an import
 * declares a total lower than the detail rows already hold — bin A = 40, bin B = 7, file says 40 —
 * the difference lands on the sentinel row and is allowed to be **negative**:
 *
 *     A = 40, B = 7, sentinel = −7
 *
 * The total is right immediately, the existing rows are untouched, and the discrepancy is a visible
 * number that clears itself the moment someone finds the missing seven. Clamping it at zero would
 * silently delete exactly the fact the design exists to surface, and refusing the import would fail
 * on precisely the day someone discovers stock is missing — which is when they most need the number
 * corrected. Shrinking is a write-off wearing an import's clothes, not an import failure.
 *
 * **What still stops an ordinary pick going negative.** The CHECK was never the only guard, and it
 * is not the one that matters. `StockMovementService::decrement()` issues a conditional
 * `UPDATE ... WHERE quantity >= :n` and refuses on zero affected rows; that is untouched, and it is
 * what turns an over-pick into "someone got there first" rather than a constraint exception in a
 * picker's face. The single named door down is `MovementRequest::shortfall()`. Everything else
 * still cannot go below zero.
 *
 * **The rebuild order is inverted on purpose.** `inventory_movement` cascades from
 * `inventory_detail` on BOTH `from_detail_id` and `to_detail_id`, and `PRAGMA foreign_keys = OFF`
 * is silently ignored inside a transaction — which is where migrations run. Dropping the old table
 * first would cascade the entire ledger away. So the new table is created and filled, the movement
 * rows are re-pointed at it, and only then does the old table go.
 */
final class Version20260827100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow a negative sentinel row on inventory_detail (#565)';
    }

    public function up(Schema $schema): void
    {
        // Same shape, minus the CHECK. Column order matches Version20260821160000 so the INSERT
        // below can name them explicitly rather than relying on positional SELECT *.
        $this->addSql('CREATE TABLE inventory_detail_rebuilt (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            serial VARCHAR(120) DEFAULT NULL,
            status VARCHAR(16) NOT NULL,
            quantity INTEGER DEFAULT 0 NOT NULL,
            updated_at DATETIME NOT NULL,
            product_id INTEGER NOT NULL,
            warehouse_id INTEGER NOT NULL,
            location_id INTEGER DEFAULT NULL,
            lot_id INTEGER DEFAULT NULL,
            CONSTRAINT FK_2EDAE3384584665A FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
            CONSTRAINT FK_2EDAE3385080ECDE FOREIGN KEY (warehouse_id) REFERENCES warehouse (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
            CONSTRAINT FK_2EDAE33864D218E FOREIGN KEY (location_id) REFERENCES warehouse_location (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE,
            CONSTRAINT FK_2EDAE338A8CBA5F7 FOREIGN KEY (lot_id) REFERENCES inventory_lot (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
        )');

        $this->addSql('INSERT INTO inventory_detail_rebuilt (id, serial, status, quantity, updated_at, product_id, warehouse_id, location_id, lot_id)
            SELECT id, serial, status, quantity, updated_at, product_id, warehouse_id, location_id, lot_id FROM inventory_detail');

        // Re-point the ledger BEFORE the old table is dropped. Ids are carried over unchanged, so
        // this is a rename of the referenced table rather than a remap of any value.
        $this->addSql('CREATE TABLE inventory_movement_rebuilt (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            quantity INTEGER NOT NULL CHECK (quantity > 0),
            group_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            from_detail_id INTEGER DEFAULT NULL,
            to_detail_id INTEGER DEFAULT NULL,
            CONSTRAINT FK_40972F66FE54D947 FOREIGN KEY (group_id) REFERENCES inventory_movement_group (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
            CONSTRAINT FK_40972F664584665A FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
            CONSTRAINT FK_40972F66C84C9601 FOREIGN KEY (from_detail_id) REFERENCES inventory_detail_rebuilt (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
            CONSTRAINT FK_40972F66E973D4CA FOREIGN KEY (to_detail_id) REFERENCES inventory_detail_rebuilt (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('INSERT INTO inventory_movement_rebuilt (id, quantity, group_id, product_id, from_detail_id, to_detail_id)
            SELECT id, quantity, group_id, product_id, from_detail_id, to_detail_id FROM inventory_movement');

        $this->addSql('DROP TABLE inventory_movement');
        $this->addSql('DROP TABLE inventory_detail');
        $this->addSql('ALTER TABLE inventory_detail_rebuilt RENAME TO inventory_detail');
        $this->addSql('ALTER TABLE inventory_movement_rebuilt RENAME TO inventory_movement');

        // Every index from Version20260821160000, recreated verbatim. uniq_live_serial keeps its
        // `quantity > 0` predicate, which is what makes the negative sentinel row exempt from it —
        // a short count carries the sentinel serial and cannot collide with a live one.
        $this->addSql('CREATE INDEX IDX_2EDAE3384584665A ON inventory_detail (product_id)');
        $this->addSql('CREATE INDEX IDX_2EDAE3385080ECDE ON inventory_detail (warehouse_id)');
        $this->addSql('CREATE INDEX IDX_2EDAE338A8CBA5F7 ON inventory_detail (lot_id)');
        $this->addSql('CREATE INDEX idx_detail_lookup ON inventory_detail (product_id, warehouse_id, status)');
        $this->addSql('CREATE INDEX idx_detail_location ON inventory_detail (location_id)');
        $this->addSql('CREATE INDEX idx_detail_serial ON inventory_detail (serial)');
        $this->addSql("CREATE UNIQUE INDEX uniq_inventory_detail ON inventory_detail (product_id, warehouse_id, COALESCE(location_id, 0), COALESCE(lot_id, 0), COALESCE(serial, ''), status)");
        $this->addSql('CREATE UNIQUE INDEX uniq_live_serial ON inventory_detail (product_id, serial) WHERE serial IS NOT NULL AND quantity > 0');
        $this->addSql("CREATE INDEX idx_detail_available ON inventory_detail (product_id, warehouse_id) WHERE status = 'available' AND quantity > 0");

        $this->addSql('CREATE INDEX IDX_40972F66FE54D947 ON inventory_movement (group_id)');
        $this->addSql('CREATE INDEX IDX_40972F664584665A ON inventory_movement (product_id)');
        $this->addSql('CREATE INDEX idx_movement_from ON inventory_movement (from_detail_id)');
        $this->addSql('CREATE INDEX idx_movement_to ON inventory_movement (to_detail_id)');
    }

    /**
     * Restoring the CHECK would fail on any database that has since recorded a short count, which
     * is the entire point of removing it. So down() is deliberately a no-op with an explanation
     * rather than a rebuild that works only until the feature is used.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('SELECT 1');
    }
}
