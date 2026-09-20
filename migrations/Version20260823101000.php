<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The `backordered` bucket column (#548).
 *
 * Its own migration rather than joining the four config columns before it, because it is not
 * configuration: it is bucket arithmetic, cached from the order ledger by
 * InventoryReservationReconciler exactly as `sales_hold`, `pending` and `approved` already are.
 *
 * ## Zero, and deliberately not backfilled
 *
 * Nothing existing has anything to put here. `sales_order_line.backordered_quantity` — the ledger
 * this column caches — is created by the very next migration in this chain and is 0.00 on every
 * row when it arrives, so the only correct starting value is zero, and computing one would be
 * computing it from a column that does not exist yet.
 *
 * That is also the shape of the mistake #539 made: it shipped a status derived from a column a
 * later migration dropped, and the disagreement stayed invisible until the next write flipped it.
 * A derived value has to be derived from what is authoritative AFTER the whole chain has run, and
 * here what is authoritative after the whole chain has run says zero.
 */
final class Version20260823101000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#548: the backordered hold bucket on product_inventory.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_inventory ADD COLUMN backordered_quantity INTEGER DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Nothing to do: Version20260823100000's down() rebuilds product_inventory without any of
        // the #548 columns, this one included, and SQLite cannot drop a single column anyway.
        // Two rebuilds of the same table back to back would only be a second chance to get one
        // wrong.
        $this->addSql('SELECT 1');
    }
}
