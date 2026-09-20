<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The positive half of the bucket ledger (#564).
 *
 * `product_inventory.quantity` is imported from the client's own inventory system and this app does
 * not own it. Every bucket that existed before this migration is negative — stock leaving — because
 * until receiving shipped there was no notion of goods arriving. Receiving had nowhere to put them
 * and raised `quantity`, which the next import silently overwrites.
 *
 * Three columns, all defaulting to 0, so an instance that never enables receiving is unchanged:
 *
 *  - `received_quantity`  arrived and on the shelf, not yet in their file. Sellable.
 *  - `incoming_quantity`  on a purchase order, not yet arrived. NOT sellable — a forecast.
 *  - `quarantine_quantity` column only. Nothing reads or writes it; sign and clearing are an open
 *    question. Shipped now because adding it alongside the other two costs nothing, where a later
 *    migration for one column costs a deployment.
 *
 * ADD COLUMN only — no table rebuild, so this is safe on the 3.26 SQLite floor (DROP COLUMN needs
 * 3.35). Reversible for the same reason it is cheap: down() cannot DROP COLUMN there, so it zeroes
 * the values instead and says so rather than pretending to undo the schema.
 */
final class Version20260827090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add received/incoming/quarantine buckets to product_inventory (#564)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_inventory ADD COLUMN received_quantity INTEGER DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE product_inventory ADD COLUMN incoming_quantity INTEGER DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE product_inventory ADD COLUMN quarantine_quantity INTEGER DEFAULT 0 NOT NULL');
    }

    /**
     * Zeroes rather than drops. SQLite before 3.35 has no DROP COLUMN and the floor here is 3.26, so
     * the honest reverse of "three columns nothing else reads" is to empty them and leave the shape.
     * A table rebuild to remove three additive columns would be far more dangerous than the columns.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE product_inventory SET received_quantity = 0, incoming_quantity = 0, quarantine_quantity = 0');
    }
}
