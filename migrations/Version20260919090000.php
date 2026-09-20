<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `custom_field_value_invoice` and `custom_field_value_estimate` — the two sell-side documents
 * beside the order get custom fields of their own.
 *
 * ## What this is
 *
 * Admin-defined custom fields existed for Product, Customer and Order. An invoice and a quote had
 * none, so a field an admin could hang off an order could not be hung off the quote it came from or
 * the invoice it became. `App\Entity\CustomFieldDefinition` gains `OBJECT_TYPE_INVOICE` and
 * `OBJECT_TYPE_ESTIMATE`, and each needs the value table its object type resolves to through
 * `App\Repository\CustomFieldValueRepository::MAP`.
 *
 * Both tables are `custom_field_value_order` in every respect but the column name and the table it
 * points at — see `Version20260805040000`, which split the old shared `custom_field_value` into one
 * table per object type and states why: without a real FK there is no `ON DELETE CASCADE`, and a
 * deleted document leaves orphan value rows behind that nothing ever collects.
 *
 * ## ADD only
 *
 * Two `CREATE TABLE`s and their indexes. No `UPDATE`, no `DELETE`, no backfill, and not one
 * statement against an existing table. Invoices and estimates are snapshots — the figures on a
 * document that has already gone to a customer are not a migration's to touch — and there is
 * nothing to backfill anyway: no definition of either object type can exist before this migration,
 * because the object type strings did not exist before the commit that carries it.
 *
 * ## Why the guards read sqlite_master rather than the injected Schema
 *
 * Touching `$schema` introspects the WHOLE database, which since `Version20260821160000` dies on
 * `uniq_inventory_detail` — SQLite reports an expression index's column name as NULL and DBAL's
 * `Index::_addColumn()` is typed `string`. {@see SqliteMigrationIntrospection} is the explanation
 * and the fix. `$schema` stays in the signature because `AbstractMigration` declares it, and is
 * never read. `bin/ci-migration-replay` refuses any migration after that point that touches it.
 *
 * ## SQLite 3.26
 *
 * Nothing here needs anything newer: plain `CREATE TABLE`, plain `CREATE INDEX`, `DROP TABLE` on
 * the way down. No `DROP COLUMN` (3.35), no generated column (3.31).
 */
final class Version20260919090000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Add custom_field_value_invoice and custom_field_value_estimate, one value table per new object type, '
            . 'each FK-constrained to its document with ON DELETE CASCADE. ADD only; touches no existing table.';
    }

    public function up(Schema $schema): void
    {
        // A database that has not reached the documents yet is a no-op rather than a failure, and so
        // is a re-run — the same guard shape every other migration in this chain uses. The
        // definition table is checked too: the FK below references it, and SQLite will happily
        // create a table whose FK names something absent, then fail on the first insert.
        if (!$this->tableExists('custom_field_definition')) {
            return;
        }

        if ($this->tableExists('invoice') && !$this->tableExists('custom_field_value_invoice')) {
            $this->addSql('CREATE TABLE custom_field_value_invoice (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, value CLOB DEFAULT NULL, definition_id INTEGER NOT NULL, invoice_id INTEGER NOT NULL, CONSTRAINT FK_CFV_INVOICE_DEFINITION FOREIGN KEY (definition_id) REFERENCES custom_field_definition (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_CFV_INVOICE_INVOICE FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE INDEX IDX_CFV_INVOICE_DEFINITION ON custom_field_value_invoice (definition_id)');
            $this->addSql('CREATE INDEX IDX_CFV_INVOICE_INVOICE ON custom_field_value_invoice (invoice_id)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_CUSTOM_FIELD_VALUE_INVOICE ON custom_field_value_invoice (definition_id, invoice_id)');
        }

        if ($this->tableExists('estimate') && !$this->tableExists('custom_field_value_estimate')) {
            $this->addSql('CREATE TABLE custom_field_value_estimate (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, value CLOB DEFAULT NULL, definition_id INTEGER NOT NULL, estimate_id INTEGER NOT NULL, CONSTRAINT FK_CFV_ESTIMATE_DEFINITION FOREIGN KEY (definition_id) REFERENCES custom_field_definition (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_CFV_ESTIMATE_ESTIMATE FOREIGN KEY (estimate_id) REFERENCES estimate (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE INDEX IDX_CFV_ESTIMATE_DEFINITION ON custom_field_value_estimate (definition_id)');
            $this->addSql('CREATE INDEX IDX_CFV_ESTIMATE_ESTIMATE ON custom_field_value_estimate (estimate_id)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_CUSTOM_FIELD_VALUE_ESTIMATE ON custom_field_value_estimate (definition_id, estimate_id)');
        }
    }

    /**
     * Drops both tables, which is the honest reversal: before this migration neither existed and
     * neither could hold a row, since no definition of either object type could be created. Nothing
     * on invoice, estimate or custom_field_definition is touched either way.
     */
    public function down(Schema $schema): void
    {
        if ($this->tableExists('custom_field_value_invoice')) {
            $this->addSql('DROP TABLE custom_field_value_invoice');
        }

        if ($this->tableExists('custom_field_value_estimate')) {
            $this->addSql('DROP TABLE custom_field_value_estimate');
        }
    }
}
