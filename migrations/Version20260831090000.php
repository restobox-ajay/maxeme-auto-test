<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `inventory_bucket_change_log.group_id` — which physical operation caused a bucket change (#582).
 *
 * Bucket changes are now recorded by App\EventSubscriber\InventoryBucketChangeLogger rather than by
 * call sites remembering to log, and the operation that was open at the time supplies what the
 * Doctrine changeset cannot. When that operation moved stock it carries an
 * `inventory_movement_group`, and this is where the link lands:
 *
 *   group_id NOT NULL  a physical operation caused this — receipt, putaway, transfer, pick,
 *                      adjustment
 *   group_id NULL      no physical operation — a cart hold, a document reconcile, an import
 *                      rebaseline
 *
 * NULL is meaningful rather than missing. The sell-side reconcilers never open an operation with a
 * group, because nothing physically moved, so their rows are null by construction and not by
 * omission. `action` and `triggered_by` stay exactly as they are: partly redundant once a group is
 * set, and the only thing a NULL row has.
 *
 * ## This migration adds a column and does nothing else
 *
 * No backfill, deliberately, and not for want of a plausible one — some of the 9,907 rows already
 * in this table could be matched to a movement group by product, warehouse and timestamp. That
 * matching would be a guess, and a guess written into an audit trail is worse than a gap in one:
 * afterwards nothing distinguishes the rows this migration attributed from the rows the application
 * attributed, and the column stops meaning "the operation that caused this". Existing rows keep
 * NULL, which reads correctly under the rule above for every one of them — none was written by an
 * operation that had a group, because no operation had one until now.
 *
 * ## A real foreign key, on a column the mapping calls an integer
 *
 * `inventory_movement_group` belongs to modules/InventoryDepthBundle, which is deletable, so
 * App\Entity\InventoryBucketChangeLog maps this as a plain nullable integer — a core entity holding
 * an association to a class that may not exist would turn deleting that module into a metadata load
 * failure. The TABLE, though, is created by this same shared migration chain and is therefore always
 * present whether or not the module's code is, so the database can and should enforce the reference.
 *
 * ON DELETE SET NULL rather than CASCADE: nothing deletes movement groups today, but if something
 * ever does, losing the link is a smaller loss than losing the audit row. An audit trail that
 * deletes itself is not one.
 *
 * SQLite accepts a REFERENCES clause on ALTER TABLE ADD COLUMN only when the new column defaults to
 * NULL, which this does — the same restriction that makes the no-backfill decision above free.
 */
final class Version20260831090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#582: add inventory_bucket_change_log.group_id, the movement group behind a bucket change.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inventory_bucket_change_log ADD COLUMN group_id INTEGER DEFAULT NULL REFERENCES inventory_movement_group (id) ON DELETE SET NULL');

        // Named to match the entity's #[ORM\Index], so a SchemaTool-built test database and a
        // migrated one agree on more than the column list.
        $this->addSql('CREATE INDEX idx_bucket_change_log_group ON inventory_bucket_change_log (group_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_bucket_change_log_group');

        // The column itself stays. SQLite gained DROP COLUMN in 3.35 and this project's floor is
        // 3.26, so removing it means create-copy-drop-rename of the whole table — and that is a
        // rebuild of an audit table, under a rollback, to delete a nullable column that rolled-back
        // code does not read. Same call Version20260830100000 made for transfer_out_quantity, for
        // the same reason.
    }
}
