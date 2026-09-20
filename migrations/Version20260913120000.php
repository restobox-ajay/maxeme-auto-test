<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteTableRebuild;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Item 67: `procurement_product_rule` stops holding its own copy of the tracking policy.
 *
 * ```
 * procurement_product_rule.lot_required      DROPPED   -> tracking_policy: mode = lot    && track_in
 * procurement_product_rule.serial_required   DROPPED   -> tracking_policy: mode = serial && track_in
 * procurement_product_rule.expiry_required   DROPPED   -> tracking_policy: mode = lot    && requires_expiry
 * procurement_product_rule.location_required KEPT      a bin is not a thing a unit carries
 * ```
 *
 * ## What the duplication cost, which is why the columns go rather than being kept in step
 *
 * `ReceivingService` has always carried four guards refusing a delivery without the batch code, the
 * expiry, the serial or the bin its product needs. Every one is correct and every one was dead.
 * They ask `ProductReceivingRule`; `ProductReceivingRuleRepository::ruleFor()` returns an EMPTY rule
 * for a product with no row here; and **nothing in the application ever created a row** — the only
 * writer is the Procurement Settings screen, which nobody had reason to visit, because the product
 * form already had a Tracking Policy field that said the product was serialised.
 *
 * So a warehouse could put every product on a Serial policy, read the word "serial" on the product
 * page, the stock screens and the tracking worklist, and book 550 units in with every identity box
 * blank. That is what the back-office walkthrough did, and it is why the gap was invisible: the
 * screen that enforced was reading a table the screen that declared never wrote to.
 *
 * Keeping the columns in step with the policy would be the same defect with a synchroniser bolted
 * on — two places holding one fact, which is the shape behind #589, #590 and #591. The entity
 * derives them now, so a disagreement is not a bug to fix but a state that cannot be written down.
 *
 * ## Risk, stated rather than assumed
 *
 * `procurement_product_rule` arrived at Version20260826120000 and procurement has never been
 * deployed anywhere but dev — production is the sales-only app — so this table holds no production
 * rows. It holds **zero rows in every database on this machine** (`var/data_test.db`,
 * `var/replay.db`), which is the same fact about the writer, seen from the other side. No deployed
 * table is touched.
 *
 * ## The ADD-only rule
 *
 * `docs/QUEUE.md` says migrations add columns and tables only. That rule is **overruled by the
 * owner for this change specifically** and for nothing else in it: the three columns go. Nothing
 * here writes a value into an existing row, so the separate "never write to existing data" rule is
 * not bent at all — the rebuild copies rows through unchanged and the dropped columns take their
 * own values with them, which is expected of test and demo data.
 *
 * ## SQLite 3.26
 *
 * Production SQLite is 3.26, so `DROP COLUMN` (3.35) is unavailable and the columns go by the
 * classic rebuild through `App\Doctrine\SqliteTableRebuild` — copy out, drop, recreate from the
 * table's own CREATE minus the columns, copy back, recreate the indexes — between
 * `PRAGMA foreign_keys = OFF` and `ON`, in a non-transactional migration because the pragma is
 * silently ignored inside a transaction and the DROP TABLE would otherwise cascade. Precedent:
 * `Version20260820160000`, `Version20260911170000`.
 *
 * The cascade risk here is real rather than theoretical: `procurement_product_rule.product_id` is
 * `REFERENCES product_core (id) ON DELETE CASCADE`, so the pragma failing would take rule rows out
 * with a table it is not even dropping — and `product_core` is a DEPLOYED table with many children.
 * `bin/ci-migration-replay` is what proves the pragma took, because a green suite proves nothing
 * about migrations: both suites build their schema from Doctrine metadata and never run this file.
 *
 * ## Reversibility
 *
 * `down()` restores the three columns, empty and defaulting to 0 — the value they held for every
 * row that has ever existed. It does NOT reconstruct them from the tracking policies, because
 * deriving values into columns during a migration is writing data, and a column that reads the
 * policy correctly on the day of the rollback is the same duplicate drifting again the day after.
 */
final class Version20260913120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Item 67: drops procurement_product_rule.lot_required / serial_required / expiry_required — derived from tracking_policy.';
    }

    /** Rebuilds procurement_product_rule (production SQLite 3.26 has no DROP COLUMN). */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql('BEGIN');
        foreach (SqliteTableRebuild::statements($this->connection, 'procurement_product_rule', [
            'lot_required' => null,
            'expiry_required' => null,
            'serial_required' => null,
        ]) as $sql) {
            $this->addSql($sql);
        }
        $this->addSql('COMMIT');
        $this->addSql('PRAGMA foreign_keys = ON');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE procurement_product_rule ADD COLUMN lot_required BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE procurement_product_rule ADD COLUMN expiry_required BOOLEAN DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE procurement_product_rule ADD COLUMN serial_required BOOLEAN DEFAULT 0 NOT NULL');
    }
}
