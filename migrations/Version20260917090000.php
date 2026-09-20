<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add estimate.version — the optimistic-lock counter #[ORM\Version] adds to Estimate.
 *
 * The counterpart of Version20260805003000, which did this for sales_order under #417.
 * `invoice.version` arrived the same way, inside the CREATE TABLE that built it
 * (Version20260820120000), because Invoice was declared with the column from the start. Estimate
 * was the outlier of the three sell-side documents: a full line-item edit screen with no version
 * column behind it, so the second of two concurrent quote saves silently won.
 *
 * ## ADD-only, and why this is safe on a populated table
 *
 * `estimate` is in the deployed sales app and holds production rows, so this migration writes over
 * nothing. `ALTER TABLE ... ADD COLUMN` with a literal default is a metadata-only operation on
 * SQLite — it does not rewrite existing rows, and it goes nowhere near the table rebuild that
 * DROP COLUMN needs below SQLite 3.35 (docs/QUEUE.md: production is 3.26). No rebuild means no
 * DROP TABLE, which means none of the ON DELETE CASCADE hazards that made Version20260730150000
 * silently delete every row it had just backfilled. `estimate_line`, `estimate_log` and
 * `estimate_address` all reference this table, and none of them is touched.
 *
 * NOT NULL DEFAULT 1 rather than nullable: every existing quote lands on the same starting point a
 * brand-new one gets, which is what ClassMetadata::setVersionMapping() assumes an unspecified
 * integer version column starts at. A NULL there would be a version Doctrine cannot compare, so the
 * default is what keeps existing rows USABLE rather than merely present. There is no prior history
 * to reconstruct a real edit count from and none is invented — the first save after this migration
 * takes each row to 2, which is correct: the column counts writes observed since it existed.
 *
 * ## Why the guards read PRAGMA rather than the injected Schema
 *
 * Version20260805003000 guards the same way through `$schema->getTable(...)->hasColumn(...)`, and
 * that is where this migration started too. It cannot be done here. Touching the injected `Schema`
 * makes DBAL introspect the WHOLE database, and since Version20260821160000 this schema contains
 * `uniq_inventory_detail`, an expression index:
 *
 *     CREATE UNIQUE INDEX uniq_inventory_detail ON inventory_detail
 *         (product_id, warehouse_id, COALESCE(location_id, 0), COALESCE(lot_id, 0), COALESCE(serial, ''), status)
 *
 * SQLite reports an expression's column name as NULL (`PRAGMA index_info` gives `cid = -2`), and
 * DBAL's `Index::_addColumn()` is typed `string`, so introspection dies with "Argument #1 ($column)
 * must be of type string, null given" — a TypeError naming Index.php, about an inventory index, on
 * a migration that only wants to know whether one column exists on `estimate`.
 *
 * This is latent rather than new: EVERY migration added after Version20260821160000 avoids
 * `$schema` (verified across the chain), so nothing had asked the question yet and
 * `bin/ci-migration-replay` stayed green. Version20260911090000 already carries the PRAGMA-based
 * `tableExists()`/`columnExists()` pair for its own reasons, and this reuses that shape. Nothing is
 * done about the index itself here: it is correct SQLite, it is what makes the inventory uniqueness
 * rule enforceable at all, and rewriting an inventory constraint to suit a quote column would spend
 * the wrong change on the wrong table.
 */
final class Version20260917090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add estimate.version for optimistic-lock checking on the quote edit page.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('estimate') || $this->columnExists('estimate', 'version')) {
            return;
        }

        $this->addSql('ALTER TABLE estimate ADD COLUMN version INTEGER DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Dropping a column needs SQLite 3.35+ and a table rebuild below that — a lot of machinery,
        // and a DROP TABLE against a table with cascading children, to undo a counter that does no
        // harm sitting unread. Resetting it puts every row back on the starting point a fresh
        // install has, which is the closest a rollback can get here. Version20260805003000's down()
        // makes the same call for sales_order.
        if ($this->tableExists('estimate') && $this->columnExists('estimate', 'version')) {
            $this->addSql('UPDATE estimate SET version = 1');
        }
    }

    private function tableExists(string $table): bool
    {
        return (bool) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table],
        );
    }

    private function columnExists(string $table, string $column): bool
    {
        foreach ($this->connection->fetchAllAssociative(sprintf('PRAGMA table_info(%s)', $table)) as $row) {
            if ($row['name'] === $column) {
                return true;
            }
        }

        return false;
    }
}
