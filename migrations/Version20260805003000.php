<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add sales_order.version — the optimistic-lock counter #[ORM\Version] adds to SalesOrder (#417).
 *
 * A dedicated integer, not a reuse of any timestamp column: sales_order has none of its own
 * (created_at lives on the shared AbstractSalesDocument mapping and isn't touched by every write),
 * and Doctrine's #[ORM\Version] only accepts int/bigint/smallint or a datetime column it fully
 * owns — see SalesOrderVersionMappingTest for the empirical check that this mapping doesn't hit
 * one of Doctrine's other documented restrictions (composite key, inheritance hierarchy, a second
 * version field).
 *
 * NOT NULL DEFAULT 1 backfills every existing row to the same starting point a brand new order
 * gets — there is no prior history to reconstruct a real edit count from, and 1 is what
 * ClassMetadata::setVersionMapping() assumes an unspecified integer version column starts at.
 *
 * ALTER TABLE ... ADD COLUMN with a literal default is fine on SQLite (see Version20260803170000).
 */
final class Version20260805003000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sales_order.version for optimistic-lock checking on the order edit page (#417).';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('sales_order')) {
            return;
        }

        if (!$schema->getTable('sales_order')->hasColumn('version')) {
            $this->addSql('ALTER TABLE sales_order ADD COLUMN version INTEGER DEFAULT 1 NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        // Dropping a column needs SQLite 3.35+ and a table rebuild below that (see
        // Version20260803190000's down() for the same call) — a lot of machinery to undo a
        // counter that does no harm sitting unread. Resetting it restores every row to the same
        // starting point a fresh install has, which is the closest a rollback can get.
        $this->addSql('UPDATE sales_order SET version = 1');
    }
}
