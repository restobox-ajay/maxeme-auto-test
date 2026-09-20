<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add inventory_detail.expiry, so a batch's last usable day can be recorded on stock with no lot to
 * carry it (#795).
 *
 * Lot-tracked stock already has this: `inventory_lot.expiry`. A serialised or untracked product has
 * no lot at all, so today its expiry has nowhere to live — `ReceivingService::resolveLot()` returns
 * null for a line with no batch code and the date typed for it is simply dropped. This column is
 * that missing place. It is read as `COALESCE(l.expiry, d.expiry)` everywhere stock is read, and it
 * is only ever WRITTEN on a row with no lot — a lot row keeps its own expiry solely on the lot, so
 * `d.expiry` and `l.expiry` are never both real for the same row and the COALESCE never has to
 * choose between two disagreeing answers.
 *
 * ## ADD-only, and why this is safe on a populated table
 *
 * `inventory_detail` holds production rows on prod (#539 already put orders there) and dev alike.
 * `ALTER TABLE ... ADD COLUMN` with no default is a metadata-only operation on SQLite — every
 * existing row reads NULL, which is the correct answer for all of them: nothing before this could
 * have written a lot-less expiry, so there is nothing to backfill. See the standing rule against
 * unrequested backfills.
 *
 * ## The index, rebuilt without rebuilding the table
 *
 * `uniq_inventory_detail` upholds row identity — see `Version20260821160000` — and expiry joins that
 * identity for the rows that can carry it: two lot-less rows at the same bin with different expiry
 * dates are two different batches and must be two different rows, exactly as two different lot rows
 * already are. A lot row's own `d.expiry` is always NULL (see above), so folding it into the index
 * changes nothing for the rows that already had a lot to key on.
 *
 * SQLite has supported `DROP INDEX` / `CREATE INDEX` since long before 3.26, so unlike the DROP
 * COLUMN case in `Version20260827100000` this needs no `SqliteTableRebuild` — no DROP TABLE, so none
 * of the ON DELETE CASCADE hazards that operation exists to avoid. Only the index is touched.
 *
 * ## Why the guards read PRAGMA rather than the injected Schema
 *
 * See `App\Doctrine\SqliteMigrationIntrospection`: touching `$schema` introspects the whole database
 * and, since `Version20260821160000`, throws a TypeError on `uniq_inventory_detail`'s own COALESCE
 * terms — the index this migration is rebuilding is the exact trigger, so touching `$schema` here
 * would be certain to crash rather than merely risky.
 */
final class Version20260922110000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Add inventory_detail.expiry, for a batch date on stock with no lot to carry it (#795)';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('inventory_detail')) {
            return;
        }

        if (!$this->columnExists('inventory_detail', 'expiry')) {
            $this->addSql('ALTER TABLE inventory_detail ADD COLUMN expiry DATE DEFAULT NULL');
        }

        if ($this->indexExists('uniq_inventory_detail')) {
            $this->addSql('DROP INDEX uniq_inventory_detail');
        }

        $this->addSql("CREATE UNIQUE INDEX uniq_inventory_detail ON inventory_detail (product_id, warehouse_id, COALESCE(location_id, 0), COALESCE(lot_id, 0), COALESCE(serial, ''), status, COALESCE(expiry, ''))");
    }

    /**
     * Reverting the index is safe and cheap, the same DROP/CREATE in reverse. The column is left in
     * place rather than dropped: dropping a column needs SQLite 3.35+ and the table-rebuild machinery
     * this migration deliberately avoided, to undo a column that does no harm sitting NULL and unread
     * once the index no longer keys on it. Version20260920110000's down() makes the same call.
     */
    public function down(Schema $schema): void
    {
        if (!$this->tableExists('inventory_detail')) {
            return;
        }

        if ($this->indexExists('uniq_inventory_detail')) {
            $this->addSql('DROP INDEX uniq_inventory_detail');
        }

        $this->addSql("CREATE UNIQUE INDEX uniq_inventory_detail ON inventory_detail (product_id, warehouse_id, COALESCE(location_id, 0), COALESCE(lot_id, 0), COALESCE(serial, ''), status)");
    }
}
