<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backorder configuration, per product and warehouse (#548).
 *
 * Four columns rather than four migrations, because they are meaningless apart: a cap with nothing
 * allowed to use it, or a release mode for a queue that can never have anything in it, is not a
 * state worth landing on its own.
 *
 * Every one of them defaults to off, which is the invariant the whole feature rests on. Backorder
 * is opt-in per SKU per warehouse, so after this runs every existing row is configured exactly as
 * it behaved before the columns existed, and BackorderSplitResolver resolves zero backordered units
 * for all of them.
 *
 * `allow_backorder` is separate from `max_backorder_quantity` on purpose: merging them ("NULL cap
 * means off") would make an admin destroy the number they configured every time they wanted to
 * suspend backordering for a week.
 */
final class Version20260823100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#548: per-product, per-warehouse backorder configuration on product_inventory.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_inventory ADD COLUMN allow_backorder BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE product_inventory ADD COLUMN max_backorder_quantity INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE product_inventory ADD COLUMN backorder_cap_shrinks_on_restock BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE product_inventory ADD COLUMN auto_release_on_restock BOOLEAN DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // SQLite's floor here is 3.26 and DROP COLUMN arrived in 3.35, so undoing this is a table
        // rebuild — create-copy-drop-rename, the same shape Version20260821090000 uses. Nothing
        // references product_inventory, so dropping it cascades to nothing.
        $this->addSql('CREATE TABLE product_inventory_pre548 (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, quantity INTEGER NOT NULL, reserved_quantity INTEGER NOT NULL, cart_hold_quantity INTEGER NOT NULL, sales_hold_quantity INTEGER NOT NULL, pending_quantity INTEGER NOT NULL, approved_quantity INTEGER NOT NULL, manual_adjustment INTEGER NOT NULL, updated_at DATETIME NOT NULL, product_id INTEGER NOT NULL, warehouse_id INTEGER DEFAULT NULL, CONSTRAINT FK_DF8DFCBB4584665A FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_DF8DFCBB5080ECDE FOREIGN KEY (warehouse_id) REFERENCES warehouse (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('INSERT INTO product_inventory_pre548 (id, quantity, reserved_quantity, cart_hold_quantity, sales_hold_quantity, pending_quantity, approved_quantity, manual_adjustment, updated_at, product_id, warehouse_id) SELECT id, quantity, reserved_quantity, cart_hold_quantity, sales_hold_quantity, pending_quantity, approved_quantity, manual_adjustment, updated_at, product_id, warehouse_id FROM product_inventory WHERE product_id IN (SELECT id FROM product_core)');
        $this->addSql('DROP TABLE product_inventory');
        $this->addSql('ALTER TABLE product_inventory_pre548 RENAME TO product_inventory');
        $this->addSql('CREATE INDEX IDX_DF8DFCBB4584665A ON product_inventory (product_id)');
        $this->addSql('CREATE INDEX IDX_DF8DFCBB5080ECDE ON product_inventory (warehouse_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_product_inventory_product_location ON product_inventory (product_id, warehouse_id)');
    }
}
