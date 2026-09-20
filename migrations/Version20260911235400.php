<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteTableRebuild;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #659 step 4: `product_packaging_unit` and the fourteen `packaging_unit_id` columns are dropped.
 *
 * ## Dropped outright, not orphaned
 *
 * The owner's ruling on #659, recorded in `docs/feature_list.json`: **nothing is deployed and no
 * real rows exist anywhere**, so the never-write-to-existing-data caution does not apply to this
 * app's own tables. Every row in `product_packaging_unit` and every non-NULL `packaging_unit_id` is
 * test or demo data and is expected to go with them.
 *
 * So there is no backfill from rungs to terms, no nullable column left behind "in case", and no
 * compatibility path for documents that were never written. A per-product rung and a global term are
 * not the same object — `Case = 12` on product A said nothing about product B, and `BOX-12` says
 * twelve for the whole instance — so a mapping between them would have had to invent per-product
 * history the new model cannot express. Leaving fourteen orphan columns instead would leave the
 * defect #659 opens with exactly where it was: two places that can each answer "what unit is this
 * line in", with nothing saying which is right.
 *
 * That ruling is a fact about TODAY and expires the moment the first customer is live. What protects
 * the data that does not exist yet is the freeze in step 1 — a unit cannot be restated once anything
 * points at it — and it is already in force.
 *
 * ## SQLite 3.26 has no DROP COLUMN
 *
 * Production runs 3.26 and `ALTER TABLE ... DROP COLUMN` arrived in 3.35, so each of the fourteen
 * tables is rebuilt: copy to a temp table, drop, recreate from its own CREATE statement minus the
 * column, copy back, recreate its indexes. {@see SqliteTableRebuild} produces those statements; the
 * indexes naming `packaging_unit_id` are dropped with it and the rest are recreated verbatim.
 *
 * `isTransactional(): false` is load-bearing and not stylistic. `PRAGMA foreign_keys = OFF` is
 * SILENTLY IGNORED inside a transaction, and with it ignored `DROP TABLE sales_order_line` cascades
 * into every child that references it. That is not hypothetical here: it is how
 * `Version20260730150000` destroyed every address snapshot the migration before it had just written,
 * while reporting complete success and exiting 0. `bin/ci-migration-replay` exists because of it and
 * is the only thing that proves this migration, since neither test suite runs the chain.
 *
 * ## Order
 *
 * Columns first, then the table. Nothing declares a FOREIGN KEY on `packaging_unit_id` — the phase-3
 * migration says why — so the order is about readability rather than constraint satisfaction, and
 * the pragma is off throughout either way.
 *
 * Numbered above everything claimed on any ref today; step 2 is `Version20260911235000` and step 3
 * `Version20260911235200`.
 */
final class Version20260911235400 extends AbstractMigration
{
    /**
     * The fourteen tables, listed rather than swept — the same list, in the same order, as
     * `Version20260911090000` used to ADD the column.
     *
     * A migration must say what it did to a database that no longer matches the mappings: replaying
     * this chain in three years has to remove the same fourteen columns whatever the entities look
     * like by then, and by then no entity will mention packaging at all.
     *
     * @var list<string>
     */
    private const LINE_TABLES = [
        'sales_order_line',
        'invoice_line',
        'estimate_line',
        'credit_memo_line',
        'sales_return_line',
        'cart_item',
        'purchase_order_line',
        'goods_receipt_line',
        'vendor_bill_line',
        'rfq_line',
        'debit_memo_line',
        'vendor_return_line',
        'transfer_order_line',
        'pick_task',
    ];

    public function getDescription(): string
    {
        return '#659 step 4: drop product_packaging_unit and the 14 packaging_unit_id columns. No per-product ratios anywhere.';
    }

    /** Rebuilds fourteen tables; see the class docblock for why this may not be transactional. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql('BEGIN');

        foreach (self::LINE_TABLES as $table) {
            // A table whose bundle was never installed simply has no row here to rebuild, and one
            // that never got the column (a database migrated before phase 3 was reverted) has
            // nothing to drop. Both are skipped rather than aborted: bin/ci-bundles-off runs the
            // suite with the optional bundles off.
            if (!$this->tableExists($table) || !$this->columnExists($table, 'packaging_unit_id')) {
                continue;
            }

            foreach (SqliteTableRebuild::statements($this->connection, $table, ['packaging_unit_id' => null]) as $sql) {
                $this->addSql($sql);
            }
        }

        $this->addSql('DROP INDEX IF EXISTS uniq_product_packaging_unit_name');
        $this->addSql('DROP INDEX IF EXISTS IDX_product_packaging_unit_product');
        $this->addSql('DROP INDEX IF EXISTS IDX_product_packaging_unit_parent');
        $this->addSql('DROP TABLE IF EXISTS product_packaging_unit');

        $this->addSql('COMMIT');
        $this->addSql('PRAGMA foreign_keys = ON');
    }

    /**
     * Recreates the table and the fourteen columns, EMPTY.
     *
     * Honest about what it can and cannot give back: the shape returns, the rows do not. Nothing
     * here ever held a row that mattered — that is the ruling this migration rests on — and a
     * `down()` that pretended otherwise, by trying to reconstruct rungs from terms, would invent
     * per-product history out of instance-wide ratios. Reversing the SCHEMA is what a down migration
     * owes; reversing a deliberate retirement of data that did not exist is not something it can do.
     */
    public function down(Schema $schema): void
    {
        $this->addSql("CREATE TABLE product_packaging_unit (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            product_id INTEGER NOT NULL,
            name VARCHAR(80) NOT NULL,
            factor NUMERIC(18, 6) DEFAULT '1.000000' NOT NULL,
            parent_id INTEGER DEFAULT NULL,
            factor_to_base NUMERIC(18, 6) DEFAULT '1.000000' NOT NULL,
            CONSTRAINT FK_product_packaging_unit_product FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE,
            CONSTRAINT FK_product_packaging_unit_parent FOREIGN KEY (parent_id) REFERENCES product_packaging_unit (id)
        )");
        $this->addSql('CREATE UNIQUE INDEX uniq_product_packaging_unit_name ON product_packaging_unit (product_id, name)');
        $this->addSql('CREATE INDEX IDX_product_packaging_unit_product ON product_packaging_unit (product_id)');
        $this->addSql('CREATE INDEX IDX_product_packaging_unit_parent ON product_packaging_unit (parent_id)');

        foreach (self::LINE_TABLES as $table) {
            if (!$this->tableExists($table) || $this->columnExists($table, 'packaging_unit_id')) {
                continue;
            }

            $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN packaging_unit_id INTEGER DEFAULT NULL', $table));
        }
    }

    private function tableExists(string $table): bool
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table],
        ) > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        foreach ($this->connection->fetchAllAssociative(sprintf('PRAGMA table_info(%s)', $table)) as $row) {
            if (($row['name'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }
}
