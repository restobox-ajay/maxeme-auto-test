<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use App\Doctrine\SqliteTableRebuild;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Invoices issued from a repair order: custom fees and discounts (maxeme_invoice_charge), a
 * charge-through line under its service (maxeme_invoice_service.parent_id) with a quantity that
 * may be 1.5 hours (quantity becomes NUMERIC), and a flag for the invoices issued that way.
 *
 * SQLite cannot change a column's type in place, so maxeme_invoice_service is rebuilt
 * (SqliteTableRebuild), outside a transaction with foreign keys off, so the rebuild's DROP TABLE
 * does not cascade into the parts that point at its rows. Every quantity keeps its value.
 */
final class Version20261002160000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Invoices from repair orders: charges, charge-through lines, decimal quantities';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql('BEGIN');

        if (!$this->tableExists('maxeme_invoice_charge')) {
            $this->addSql('CREATE TABLE maxeme_invoice_charge (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                invoice_id INTEGER NOT NULL,
                kind VARCHAR(10) NOT NULL,
                label VARCHAR(120) NOT NULL,
                amount NUMERIC(10, 2) NOT NULL,
                position INTEGER DEFAULT 0 NOT NULL,
                CONSTRAINT FK_MX_INVOICE_CHARGE_INVOICE FOREIGN KEY (invoice_id) REFERENCES maxeme_invoice (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )');
            $this->addSql('CREATE INDEX IDX_MX_INVOICE_CHARGE_INVOICE ON maxeme_invoice_charge (invoice_id)');
        }

        if (!$this->columnExists('maxeme_invoice', 'issued_from_repair_order')) {
            $this->addSql('ALTER TABLE maxeme_invoice ADD COLUMN issued_from_repair_order BOOLEAN DEFAULT 0 NOT NULL');
        }

        if (!$this->columnExists('maxeme_invoice_service', 'parent_id')) {
            foreach (SqliteTableRebuild::statements($this->connection, 'maxeme_invoice_service', ['quantity' => 'quantity NUMERIC(10, 2) DEFAULT 1 NOT NULL']) as $sql) {
                $this->addSql($sql);
            }
            $this->addSql('ALTER TABLE maxeme_invoice_service ADD COLUMN parent_id INTEGER DEFAULT NULL REFERENCES maxeme_invoice_service (id) ON DELETE CASCADE');
            $this->addSql('CREATE INDEX IDX_MX_INVOICE_SERVICE_PARENT ON maxeme_invoice_service (parent_id)');
        }

        $this->addSql('COMMIT');
        $this->addSql('PRAGMA foreign_keys = ON');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_MX_INVOICE_SERVICE_PARENT');
        $this->addSql('ALTER TABLE maxeme_invoice_service DROP COLUMN parent_id');
        $this->addSql('ALTER TABLE maxeme_invoice DROP COLUMN issued_from_repair_order');
        $this->addSql('DROP TABLE maxeme_invoice_charge');
    }
}
