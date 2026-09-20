<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #642: `purchase_receipt` becomes `goods_receipt`. Two tables and one column renamed, nothing else.
 *
 * ```
 * purchase_receipt                        ->  goods_receipt
 * purchase_receipt_line                   ->  goods_receipt_line
 * goods_receipt_line.purchase_receipt_id  ->  goods_receipt_line.goods_receipt_id
 * ```
 *
 * ## Why
 *
 * "Receipt" on its own means proof of payment, and this side of the app has `vendor_bill` and
 * payments sitting beside it. Dynamics ships **Product receipt** and SAP ships **Goods receipt**;
 * both qualify the word on purpose to avoid exactly that collision. The document says what arrived,
 * the bill says what is owed for it, and the two are no longer a homonym apart.
 *
 * ## NOT ONE VALUE IS WRITTEN
 *
 * There is no UPDATE in this file and no INSERT. `ALTER TABLE … RENAME TO` and
 * `ALTER TABLE … RENAME COLUMN` change the name a table or column is addressed by and nothing about
 * what is stored in it — SQLite rewrites the schema text in `sqlite_master` and leaves every page of
 * data exactly where it is. Row count, every cell and the rowids are identical before and after; the
 * branch reports `COUNT(*)` and a positional content checksum on both sides of it, hashed by column
 * ORDER rather than by column NAME, because a rename changes the names and a name-keyed dump would
 * differ for a reason that is not data loss.
 *
 * ## Why a rename is allowed here when the chain is otherwise ADD-only
 *
 * Same two facts that authorised the `vendor_address` rename one migration ago
 * (Version20260908090000), and the owner authorised this one on both:
 *
 *   1. Production is frozen at sales-only and has no procurement side at all, so `purchase_receipt`
 *      has never held a production row. The table exists on dev instances, where it holds five
 *      receipts and seventeen lines.
 *   2. Add-copy-drop across two releases buys nothing when there is no live instance to keep
 *      readable in between, and on this table it would cost a rebuild — see below for why that
 *      matters more here than it did for `vendor_address`.
 *
 * ## Why RENAME TO and not a rebuild
 *
 * Version20260730150000 records what a rebuild costs under SQLite: `PRAGMA foreign_keys = OFF` is
 * silently ignored inside a transaction, so a DROP TABLE cascades to children and deletes them while
 * reporting success. `goods_receipt_line` is `ON DELETE CASCADE` off this table, so a rebuild of the
 * parent is precisely the shape that bug takes — seventeen lines would go and the migration would
 * exit 0. `RENAME TO` is not one of the statements SQLite emulates by rebuilding through a temp
 * copy, so it takes none of that path.
 *
 * `RENAME TO` also updates the references to the old name that live in OTHER objects' schema text —
 * `goods_receipt_line`'s `REFERENCES purchase_receipt (id)` clause and the index definitions — as
 * long as `legacy_alter_table` is off, which it is by default from SQLite 3.25. That is why this
 * file does not rebuild the child to repoint its foreign key: the engine repoints it.
 *
 * The one thing SQLite will not carry across is an index NAME, which is only a label and holds no
 * data. `IDX_purchase_receipt_void_group` (added by #613 for `void_movement_group_id`) is therefore
 * dropped and recreated under the new name. Dropping an index cannot lose a row.
 *
 * ## What deliberately did NOT move
 *
 * Two strings that look like this table's name are storage KEYS, not table names, and keeping them
 * is the only way to avoid a write to existing data:
 *
 *   - `document_number_counter.kind = 'purchase_receipt'` — the receipt number counter. Every
 *     instance that has booked a receipt has a row under that key.
 *   - `app_setting.setting_key = 'purchase_receipt_number_prefix'` — the configurable `RC-` prefix.
 *
 * `PurchaseDocumentNumberGenerator` says the same at both constants. The route
 * `/admin/bundles/procurement/receiving` and its route names did not move either: they name the
 * activity rather than the document, and the URL is a public surface with bookmarks behind it.
 */
final class Version20260908120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#642: rename purchase_receipt/purchase_receipt_line to goods_receipt/goods_receipt_line and the FK column purchase_receipt_id to goods_receipt_id. No data is written.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS IDX_purchase_receipt_void_group');

        $this->addSql('ALTER TABLE purchase_receipt RENAME TO goods_receipt');
        $this->addSql('ALTER TABLE purchase_receipt_line RENAME TO goods_receipt_line');
        $this->addSql('ALTER TABLE goods_receipt_line RENAME COLUMN purchase_receipt_id TO goods_receipt_id');

        $this->addSql('CREATE INDEX IDX_goods_receipt_void_group ON goods_receipt (void_movement_group_id)');
    }

    /**
     * Fully reversible, and losslessly so — a rename back is a rename, not a restore.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS IDX_goods_receipt_void_group');

        $this->addSql('ALTER TABLE goods_receipt_line RENAME COLUMN goods_receipt_id TO purchase_receipt_id');
        $this->addSql('ALTER TABLE goods_receipt_line RENAME TO purchase_receipt_line');
        $this->addSql('ALTER TABLE goods_receipt RENAME TO purchase_receipt');

        $this->addSql('CREATE INDEX IDX_purchase_receipt_void_group ON purchase_receipt (void_movement_group_id)');
    }
}
