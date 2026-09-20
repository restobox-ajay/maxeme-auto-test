<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Minimum shelf life at receiving (item 68): the per-product override, and the override RECORD.
 *
 * Two things, both additions:
 *
 *   procurement_product_rule.minimum_shelf_life_days   NEW COLUMN, nullable, no default
 *   procurement_short_dated_receipt                    NEW TABLE
 *
 * The global minimum itself needs no schema: it is an `app_setting` row written by
 * ProcurementBundle's own settings screen, exactly as the two match tolerances are, and it is
 * created lazily on first save like every other setting in this application.
 *
 * ## ADD only, and it means it
 *
 * `docs/QUEUE.md`'s ADD-only rule is in force. The overrule granted for item 67 covered that
 * migration's three column DROPS and nothing else, and the `#645` waiver covers a column widening.
 * Neither reaches here. This migration writes **not one byte** to any row that already exists: no
 * backfill, no repair UPDATE, no DEFAULT that would rewrite the table.
 *
 * ## Why the new column has no DEFAULT, which is the load-bearing decision
 *
 * `minimum_shelf_life_days` is `INTEGER DEFAULT NULL`, so every existing `procurement_product_rule`
 * row reads NULL after this runs. NULL is **"no override — follow the global"**, and the global
 * starts at 0, so an installation that migrates and changes nothing else behaves exactly as it did
 * yesterday: no delivery is called short, because nobody has asked for a minimum.
 *
 * `DEFAULT 0` would have been the obvious spelling and it is the wrong one. Zero in this column is
 * not "unset" — it is **"this product is exempt from the minimum entirely"**, a deliberate
 * exemption that must survive somebody changing the global figure later. Defaulting to it would
 * silently declare every product in the database permanently exempt, which is a decision nobody
 * made, written by a deploy. See {@see \ProcurementBundle\Entity\ProductReceivingRule} and
 * {@see \ProcurementBundle\Receiving\MinimumShelfLife}.
 *
 * ## SQLite 3.26
 *
 * `ALTER TABLE ... ADD COLUMN` with a constant (here, no) default is supported well below 3.26, and
 * `CREATE TABLE` needs nothing modern. No `DROP COLUMN` (3.35), no `RETURNING` (3.35), no generated
 * column (3.31), no `IIF()` (3.32). Nothing here rebuilds a table, so `App\Doctrine\SqliteTableRebuild`
 * is not involved and the `PRAGMA foreign_keys` hazard that made `isTransactional(): false`
 * necessary in `Version20260730150000` and `Version20260913120000` does not arise.
 *
 * ## The foreign key on the new table
 *
 * `receipt_line_id REFERENCES goods_receipt_line(id) ON DELETE CASCADE`, and it is a real key
 * because it points at a row this bundle owns and that currently exists — the same distinction
 * `goods_receipt_line.lot_id` draws against its deliberately unreferenced `product_id`. The
 * exception is a fact about that line and is meaningless without it. Receipts are never deleted; a
 * wrong one is VOIDED, which leaves the lines and these rows exactly where they are.
 *
 * `UNIQUE(receipt_line_id)` because one line carries at most one expiry date and can therefore be
 * short by at most one amount. Two rows about one line would be two answers to one question, and
 * the index is what makes that unrepresentable rather than merely unlikely.
 *
 * ## The stamp
 *
 * `Version20260915142000` is above every stamp claimed on any ref, tag, working tree or reflog at
 * the time of writing — the highest found anywhere was `Version20260914113000`. Deliberately off
 * the daily `090000` pattern, for the reason `Version20260912161500` gives: two agents reaching for
 * "tomorrow at 09:00" is how two branches collide on one stamp and silently drop each other's
 * tables, and neither suite would see it — `tests/_bootstrap.php` builds the schema from Doctrine
 * metadata and never runs the chain, so only `bin/ci-migration-replay` ever would.
 */
final class Version20260915142000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Item 68: per-product minimum shelf life override, and the short-dated receipt exception record.';
    }

    public function up(Schema $schema): void
    {
        // NULL means "no override, follow the global". Deliberately no DEFAULT — see the class
        // docblock: 0 in this column is an exemption somebody asked for, and defaulting to it would
        // declare every existing product exempt without anyone deciding to.
        $this->addSql('ALTER TABLE procurement_product_rule ADD COLUMN minimum_shelf_life_days INTEGER DEFAULT NULL');

        // Goods accepted with less shelf life left than the minimum allows, and why. Written once,
        // at the moment of the decision, and it SNAPSHOTS the figures rather than pointing at the
        // settings — the minimum in force will move, and this row has to go on saying what the
        // delivery was actually measured against on the day somebody accepted it.
        //
        // remaining_days is signed on purpose: a delivery that arrived already expired reads
        // negative, rather than clamping to zero and losing how bad it was.
        //
        // There is no shortfall_days column. It is minimum_days - remaining_days and both of those
        // are here, so a third column would be one fact stored twice — the shape behind #589, #590,
        // #591 and item 67 itself. ShortDatedReceipt::getShortfallDays() derives it.
        $this->addSql(<<<'SQL'
            CREATE TABLE procurement_short_dated_receipt (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                receipt_line_id INTEGER NOT NULL,
                expiry DATE NOT NULL,
                minimum_days INTEGER NOT NULL,
                remaining_days INTEGER NOT NULL,
                minimum_source VARCHAR(16) NOT NULL,
                reason VARCHAR(255) NOT NULL,
                overridden_by VARCHAR(180) DEFAULT NULL,
                overridden_at DATETIME NOT NULL,
                CONSTRAINT FK_short_dated_receipt_line FOREIGN KEY (receipt_line_id)
                    REFERENCES goods_receipt_line (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
            SQL);

        // One line, one expiry, one shortfall. The index is what makes a second row about the same
        // line impossible rather than merely unwritten.
        $this->addSql('CREATE UNIQUE INDEX uniq_short_dated_receipt_line ON procurement_short_dated_receipt (receipt_line_id)');

        // The worklist ordering: what was accepted, most recent first.
        $this->addSql('CREATE INDEX idx_short_dated_overridden_at ON procurement_short_dated_receipt (overridden_at)');
    }

    /**
     * Drops the new table and leaves the new column exactly where it is.
     *
     * Asymmetric on purpose, and the asymmetry is the ADD-only rule showing through. Dropping a
     * column under SQLite 3.26 means rebuilding `procurement_product_rule` — copy out, DROP TABLE,
     * recreate, copy back — which is precisely the operation that destroyed every address snapshot
     * in `Version20260730150000` when `PRAGMA foreign_keys = OFF` turned out to be silently ignored
     * inside a transaction. Running that hazard to reclaim one nullable column nothing reads when
     * this code is not deployed is a bad trade: a stray `minimum_shelf_life_days` column costs
     * nothing, and `ProductReceivingRule` simply stops declaring it.
     *
     * The table does go, because every row in it is a record this feature created and nothing else
     * references it.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_short_dated_overridden_at');
        $this->addSql('DROP INDEX IF EXISTS uniq_short_dated_receipt_line');
        $this->addSql('DROP TABLE IF EXISTS procurement_short_dated_receipt');
    }
}
