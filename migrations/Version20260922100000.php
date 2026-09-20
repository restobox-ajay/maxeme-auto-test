<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteTableRebuild;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #765/#766: `invoice_payment`/`vendor_bill_payment` never got the schema half of #708's pool/claim
 * split — the entities have mapped `company_id`/`vendor_id` since, but the tables never grew the
 * column, so every INSERT has failed with "no such table column" since #708 shipped. Both pool
 * tables also still carry their PRE-#708 `invoice_id`/`vendor_bill_id NOT NULL` column, which
 * nothing populates any more (a pool is no longer 1:1 with one document) and which would refuse
 * every insert on its own once the missing column above is fixed.
 *
 * `company_id`/`vendor_id` is backfilled from each existing row's own (about-to-be-dropped)
 * `invoice_id`/`vendor_bill_id` — the document a payment was recorded against before the split, and
 * therefore exactly the customer/vendor that money belongs to. Not a guess: every #708-era payment
 * row was written against a real document, and this is the one value derivable from it with nothing
 * assumed.
 *
 * Rebuilt rather than just ALTER TABLE ADD COLUMN, because production SQLite 3.26 has neither DROP
 * COLUMN nor ALTER COLUMN ... SET NOT NULL — see SqliteTableRebuild and CLAUDE.md.
 */
final class Version20260922100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#765/#766: add invoice_payment.company_id / vendor_bill_payment.vendor_id, drop their stale pre-#708 document column.';
    }

    /** Rebuilds two tables (production SQLite 3.26 has no DROP COLUMN); see SqliteTableRebuild. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        // Run eagerly, not queued: SqliteTableRebuild::statements() below reads the table's CURRENT
        // CREATE statement from sqlite_master to know what to carry forward, and addSql() only
        // queues SQL for the migration runner to execute after up() returns — so the column has to
        // really exist by the time statements() looks for it, not merely be scheduled to.
        $this->connection->executeStatement('ALTER TABLE invoice_payment ADD COLUMN company_id INTEGER DEFAULT NULL');
        $this->connection->executeStatement(<<<'SQL'
            UPDATE invoice_payment
            SET company_id = (SELECT company_id FROM invoice WHERE invoice.id = invoice_payment.invoice_id)
            SQL);
        $this->connection->executeStatement('ALTER TABLE vendor_bill_payment ADD COLUMN vendor_id INTEGER DEFAULT NULL');
        $this->connection->executeStatement(<<<'SQL'
            UPDATE vendor_bill_payment
            SET vendor_id = (SELECT vendor_id FROM vendor_bill WHERE vendor_bill.id = vendor_bill_payment.vendor_bill_id)
            SQL);

        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql('BEGIN');

        $invoicePayment = SqliteTableRebuild::statements($this->connection, 'invoice_payment', [
            'invoice_id' => null,
            'company_id' => 'company_id INTEGER NOT NULL, CONSTRAINT fk_invoice_payment_company FOREIGN KEY (company_id) REFERENCES company (id)',
        ]);
        foreach (self::withoutConstraint($invoicePayment, 'fk_invoice_payment_invoice') as $sql) {
            $this->addSql($sql);
        }
        $this->addSql('CREATE INDEX idx_invoice_payment_company ON invoice_payment (company_id)');

        $vendorBillPayment = SqliteTableRebuild::statements($this->connection, 'vendor_bill_payment', [
            'vendor_bill_id' => null,
            'vendor_id' => 'vendor_id INTEGER NOT NULL, CONSTRAINT fk_vendor_bill_payment_vendor FOREIGN KEY (vendor_id) REFERENCES vendor (id)',
        ]);
        foreach (self::withoutConstraint($vendorBillPayment, 'fk_vendor_bill_payment_bill') as $sql) {
            $this->addSql($sql);
        }
        $this->addSql('CREATE INDEX idx_vendor_bill_payment_vendor ON vendor_bill_payment (vendor_id)');

        $this->addSql('COMMIT');
        $this->addSql('PRAGMA foreign_keys = ON');
    }

    /**
     * SqliteTableRebuild::statements() carries every CONSTRAINT clause forward unconditionally —
     * columnName() deliberately returns null for one, so $columns (keyed by COLUMN name) has no way
     * to reach a clause named by its own CONSTRAINT name. Dropping invoice_id/vendor_bill_id still
     * has to also drop the named foreign key that pointed at it, or the CREATE TABLE the rebuild
     * produces references a column that no longer exists.
     *
     * @param list<string> $statements
     *
     * @return list<string>
     */
    private static function withoutConstraint(array $statements, string $constraintName): array
    {
        foreach ($statements as $i => $sql) {
            if (str_starts_with($sql, 'CREATE TABLE')) {
                $statements[$i] = (string) preg_replace(
                    '/,\s*CONSTRAINT\s+' . preg_quote($constraintName, '/') . '\s+FOREIGN\s+KEY[^,)]*\([^)]*\)\s*REFERENCES\s+[^,)]*\([^)]*\)[^,)]*/i',
                    '',
                    $sql,
                );
                break;
            }
        }

        return $statements;
    }

    public function down(Schema $schema): void
    {
        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql('BEGIN');

        $this->addSql('ALTER TABLE invoice_payment ADD COLUMN invoice_id INTEGER DEFAULT NULL');
        foreach (SqliteTableRebuild::statements($this->connection, 'invoice_payment', ['company_id' => null]) as $sql) {
            $this->addSql($sql);
        }

        $this->addSql('ALTER TABLE vendor_bill_payment ADD COLUMN vendor_bill_id INTEGER DEFAULT NULL');
        foreach (SqliteTableRebuild::statements($this->connection, 'vendor_bill_payment', ['vendor_id' => null]) as $sql) {
            $this->addSql($sql);
        }

        $this->addSql('COMMIT');
        $this->addSql('PRAGMA foreign_keys = ON');
    }
}
