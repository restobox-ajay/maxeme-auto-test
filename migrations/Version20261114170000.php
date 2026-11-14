<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mockup 345c037: an invoice takes one or more payments, kept in their own table; the invoice is Paid
 * once they cover its total. Each paid invoice's single payment (payment_type_id / payment_amount on
 * the invoice) becomes its first row, dated with the invoice date.
 */
final class Version20261114170000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Invoice payments (several per invoice), backfilled from the paid invoices';
    }

    public function up(Schema $schema): void
    {
        if ($this->tableExists('maxeme_invoice_payment')) {
            return;
        }
        $this->addSql(<<<'SQL'
            CREATE TABLE maxeme_invoice_payment (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                invoice_id INTEGER NOT NULL,
                payment_type_id INTEGER DEFAULT NULL,
                amount NUMERIC(10, 2) NOT NULL,
                paid_at DATETIME NOT NULL,
                note VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                CONSTRAINT FK_MX_INV_PAYMENT_INVOICE FOREIGN KEY (invoice_id) REFERENCES maxeme_invoice (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_MX_INV_PAYMENT_TYPE FOREIGN KEY (payment_type_id) REFERENCES maxeme_payment_type (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            )
            SQL);
        $this->addSql('CREATE INDEX IDX_MX_INV_PAYMENT_INVOICE ON maxeme_invoice_payment (invoice_id)');
        $this->addSql('CREATE INDEX IDX_MX_INV_PAYMENT_TYPE ON maxeme_invoice_payment (payment_type_id)');
        $this->addSql(<<<'SQL'
            INSERT INTO maxeme_invoice_payment (invoice_id, payment_type_id, amount, paid_at, note, created_at)
            SELECT i.id, i.payment_type_id, i.payment_amount, COALESCE(i.last_modified, i.created_on), NULL, CURRENT_TIMESTAMP
            FROM maxeme_invoice i
            WHERE i.status = 'paid' AND i.payment_amount IS NOT NULL AND i.payment_amount > 0
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE maxeme_invoice_payment');
    }
}
