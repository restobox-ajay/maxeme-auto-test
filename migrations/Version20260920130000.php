<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `inventory_lot.status` — Available / On Hold / Recalled (#725, the sharpest,
 * most food-safety-relevant finding in the wholesale-inventory-core parity audit).
 *
 * Defaults every existing lot to `available`, the only sensible reading: nothing before this
 * migration ever wrote a status, so every lot in the database today is exactly as sellable as it
 * always has been. Nothing is retroactively put on hold or recalled by running this — that is a
 * human decision, made per lot from the new Recall / Lot Trace screen once this column exists to
 * hold it.
 */
final class Version20260920130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "#725: inventory_lot.status (available/on_hold/recalled), defaulting every existing lot to available.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE inventory_lot ADD COLUMN status VARCHAR(16) DEFAULT 'available' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inventory_lot DROP COLUMN status');
    }
}
