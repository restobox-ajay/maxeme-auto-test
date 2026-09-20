<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Repairs an instance that ran an earlier draft of Version20260830120000 (#581).
 *
 * That draft called the column `withheld_quantity` and gave it a wider meaning — everything not on
 * the shelf, `sold` and `staged` included. The name and the meaning both changed before the work
 * landed: the bucket is `write_off_quantity` and covers only stock that is gone or unsellable for
 * good, because `sold` and `staged` units are held by the invoice that billed them and a bucket
 * would subtract them twice.
 *
 * An instance that ran the draft is stuck. Its `doctrine_migration_versions` records
 * Version20260830120000 as executed, so editing that file cannot help — it will never run again —
 * and the entity now maps a column that does not exist there. This is the migration that can run.
 *
 * Idempotent by inspection rather than by version number, because which of the three states an
 * instance is in depends on when it last migrated:
 *
 *   `write_off_quantity` exists            → 120000 ran in its final form; nothing to do
 *   only `withheld_quantity` exists        → the draft ran; add the real column and compute it
 *   neither exists                         → 120000 has not run yet and is about to; nothing to do
 *
 * RENAMED FOR THE NAME, RECOMPUTED FOR THE VALUES. These are two separate problems and an earlier
 * draft of THIS migration conflated them — it added `write_off_quantity` alongside the draft column
 * and left `withheld_quantity` in place, which meant a repaired instance carried two columns for
 * one bucket: the live one holding 12 and a dead one holding 1462, differing by exactly the `sold`
 * units. A schema that states the same thing twice and disagrees with itself is worse than the
 * problem being fixed.
 *
 * So: `ALTER TABLE ... RENAME COLUMN` gives the column the name the entity maps, and the UPDATE
 * that follows replaces every value it carried over. The rename is safe on its own terms — SQLite
 * keeps the type and constraints, and `withheld_quantity` was declared `INTEGER DEFAULT 0 NOT NULL`,
 * exactly what Version20260830120000 would have added.
 *
 * The recompute is not optional and cannot be skipped just because the column now has the right
 * name. The draft's numbers are wrong under the definition that shipped: they include the `sold`
 * and `staged` units this bucket deliberately excludes, because the invoice that billed them
 * already holds them and a bucket would subtract them twice. Every value is derived from the detail
 * rows instead — the same expression InventoryDetailRepository uses, and the only definition that
 * cannot drift.
 *
 * This is a rename, not a drop. Nothing is destroyed that was not already wrong, and the column
 * this leaves behind is the one the application has been asking for since #581 landed.
 */
final class Version20260830130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#581: recover instances that ran the draft `withheld_quantity` column.';
    }

    public function up(Schema $schema): void
    {
        $columns = array_column(
            $this->connection->fetchAllAssociative('PRAGMA table_info(product_inventory)'),
            'name',
        );

        $this->skipIf(
            in_array('write_off_quantity', $columns, true) || !in_array('withheld_quantity', $columns, true),
            'product_inventory is already in the shipped shape; nothing to recover.',
        );

        // The draft column becomes the real one. Its declaration is already
        // `INTEGER DEFAULT 0 NOT NULL`, so the rename alone leaves the column in the shape
        // Version20260830120000 creates on a fresh install — no second ALTER needed to match them.
        $this->addSql('ALTER TABLE product_inventory RENAME COLUMN withheld_quantity TO write_off_quantity');

        // Every value it carried over is then replaced. Identical to Version20260830120000's
        // backfill, stated again rather than shared, because a migration has to keep working when
        // the code it was written beside has moved on.
        $this->addSql(<<<'SQL'
            UPDATE product_inventory
            SET write_off_quantity = COALESCE((
                SELECT SUM(d.quantity)
                FROM inventory_detail d
                WHERE d.product_id = product_inventory.product_id
                  AND d.warehouse_id = product_inventory.warehouse_id
                  AND d.status IN ('damaged', 'expired', 'scrapped', 'lost')
            ), 0),
            quarantine_quantity = COALESCE((
                SELECT SUM(d.quantity)
                FROM inventory_detail d
                WHERE d.product_id = product_inventory.product_id
                  AND d.warehouse_id = product_inventory.warehouse_id
                  AND d.status IN ('quarantine', 'returned')
            ), 0)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Deliberately empty. Renaming back would restore the name but not the draft's numbers,
        // which is a worse state than either end of this migration: a column called
        // `withheld_quantity` holding write-off values. The draft it recovers from was never
        // released, so there is no version anyone can legitimately be going back to.
    }
}
