<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `invoice_payment_application` and `vendor_bill_payment_application` (#708): both entities have
 * existed and been in active use since #708/queue item 34, but neither ever got a CREATE TABLE
 * migration — invisible in both test suites, which build their schema from entity metadata rather
 * than the migration chain, until a real database hit "no such table" on either one.
 *
 * Same many-to-many-with-an-amount shape as `credit_memo_application`, one pair per side: a
 * payment's slice landing on one document, so one payment can settle several documents and one
 * document can be settled by several payments.
 */
final class Version20260922090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#708: add invoice_payment_application and vendor_bill_payment_application — both had entities but no migration.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE invoice_payment_application (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                amount NUMERIC(12, 2) NOT NULL,
                applied_at DATE NOT NULL,
                invoice_payment_id INTEGER NOT NULL,
                invoice_id INTEGER NOT NULL,
                CONSTRAINT FK_invoice_payment_application_payment FOREIGN KEY (invoice_payment_id) REFERENCES invoice_payment (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_invoice_payment_application_invoice FOREIGN KEY (invoice_id) REFERENCES invoice (id) NOT DEFERRABLE INITIALLY IMMEDIATE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_invoice_payment_application_payment ON invoice_payment_application (invoice_payment_id)');
        $this->addSql('CREATE INDEX idx_invoice_payment_application_invoice ON invoice_payment_application (invoice_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE vendor_bill_payment_application (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                amount NUMERIC(12, 2) NOT NULL,
                applied_at DATE NOT NULL,
                vendor_bill_payment_id INTEGER NOT NULL,
                vendor_bill_id INTEGER NOT NULL,
                CONSTRAINT FK_vendor_bill_payment_application_payment FOREIGN KEY (vendor_bill_payment_id) REFERENCES vendor_bill_payment (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_vendor_bill_payment_application_bill FOREIGN KEY (vendor_bill_id) REFERENCES vendor_bill (id) NOT DEFERRABLE INITIALLY IMMEDIATE
            )
            SQL);
        $this->addSql('CREATE INDEX idx_bill_payment_application_bill ON vendor_bill_payment_application (vendor_bill_id)');
        $this->addSql('CREATE INDEX idx_vendor_bill_payment_application_payment ON vendor_bill_payment_application (vendor_bill_payment_id)');
    }

    /** Children of a payment and a document, neither of which this migration touches — dropping both loses only their own rows. */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS invoice_payment_application');
        $this->addSql('DROP TABLE IF EXISTS vendor_bill_payment_application');
    }
}
