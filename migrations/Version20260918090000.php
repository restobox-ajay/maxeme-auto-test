<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add `document_lock` — one row per sell-side document somebody has deliberately frozen.
 *
 * Backs {@see \App\Entity\DocumentLock} and {@see \App\Service\Document\DocumentLockService}. No row
 * means not locked, so this migration leaves every existing document exactly as it was: unlocked,
 * which is what they all are.
 *
 * ## ADD-only, in the strongest available form
 *
 * This is a `CREATE TABLE` and nothing else. It adds no column to `estimate`, `sales_order` or
 * `invoice` — the three tables `docs/QUEUE.md` marks as deployed and holding production rows — and
 * touches no existing table at all, so there is no `ALTER`, no rebuild, no `DROP TABLE` and
 * therefore none of the `ON DELETE CASCADE` hazard that made `Version20260730150000` silently
 * delete the address snapshots it had just backfilled. A table that did not exist a moment ago has
 * no rows a migration could endanger.
 *
 * That shape was chosen on the design of the feature rather than to make this file easy — see
 * `DocumentLock`'s docblock for why a lock is held beside the documents rather than on them — but
 * it is worth naming as the migration consequence: three boolean columns on three deployed tables
 * would have been three `ALTER`s on production data to record a fact none of those rows has.
 *
 * ## SQLite 3.26
 *
 * `CREATE TABLE` and `CREATE UNIQUE INDEX` need nothing modern. No `DROP COLUMN` or `RETURNING`
 * (3.35), no generated column (3.31), no `IIF()` (3.32). `Version20260915142000` makes the same
 * check for the same reason.
 *
 * ## No foreign key, deliberately
 *
 * `document_id` points at one of three tables depending on `document_type`, and a single column
 * cannot reference three. The cost of that is a lock row outliving a deleted document — a state
 * this feature makes unreachable, since a locked document is exactly one that cannot be deleted
 * (`DocumentLockFlushGuard` refuses the deletion). `document_lock.document_type` holds the
 * document's own table name so a row read in SQL says what it points at without a lookup.
 *
 * ## The unique index is enforcement, not decoration
 *
 * Two admins pressing Lock at the same instant would otherwise write two rows for one document, and
 * an unlock would delete one and leave the document locked by a row nobody knew existed.
 * `uniq_document_lock` makes the second insert fail instead. Named rather than declared inline so it
 * says what it is in the error.
 *
 * ## Why the guards read PRAGMA rather than the injected Schema
 *
 * Touching `$schema` introspects the whole database, and since `Version20260821160000` that throws a
 * TypeError out of DBAL naming `Index::_addColumn()` and an inventory index — see
 * {@see SqliteMigrationIntrospection}, which is the whole explanation and the trait used here.
 * `$schema` stays in the signature because `AbstractMigration` declares it, and is never read.
 */
final class Version20260918090000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Add document_lock: one row per sell-side document frozen against editing.';
    }

    public function up(Schema $schema): void
    {
        if ($this->tableExists('document_lock')) {
            return;
        }

        $this->addSql(<<<'SQL'
            CREATE TABLE document_lock (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                document_type VARCHAR(32) NOT NULL,
                document_id INTEGER NOT NULL,
                locked_at DATETIME NOT NULL,
                locked_by VARCHAR(120) NOT NULL,
                reason VARCHAR(255) DEFAULT NULL
            )
            SQL);

        if (!$this->indexExists('uniq_document_lock')) {
            $this->addSql('CREATE UNIQUE INDEX uniq_document_lock ON document_lock (document_type, document_id)');
        }
    }

    /**
     * Drops the table, which is the honest reversal: it did not exist before this migration and
     * nothing else in the schema references it. Every document goes back to being unlocked, which
     * is the state the application had before the feature.
     */
    public function down(Schema $schema): void
    {
        if ($this->tableExists('document_lock')) {
            $this->addSql('DROP TABLE document_lock');
        }
    }
}
