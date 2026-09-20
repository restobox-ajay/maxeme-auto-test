<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The credit note (#586): five new tables and nothing else.
 *
 * `credit_memo_type` has been a configured admin table since #539 — id, name, status, created_at,
 * zero rows — and nothing was ever classified by it. These are the tables for the thing classified.
 *
 * ## NO BACKFILL. NOT OF ANYTHING.
 *
 * In particular NOT of `credit_memo_type`. It stays empty until somebody configures it, which is the
 * whole reason `credit_memo.credit_memo_type_id` is nullable — a migration that seeded "Damaged
 * goods" and "Pricing correction" would be this app deciding a customer's accounting vocabulary for
 * them, and the row an instance later actually wants would then be the second one with the same
 * name.
 *
 * Nothing else is rewritten either. No existing invoice, order, reservation or inventory row is read
 * or written here. Every behavioural change #586 makes to existing data is a DERIVATION —
 * InvoiceLine::getCreditedUnits() nets credited units out of what an invoice holds, and with no
 * credit notes in existence it answers zero for every line, so a database that runs this migration
 * and stops there computes exactly the numbers it computed before.
 *
 * ## Why credit_memo_type_id carries no foreign key
 *
 * Because `credit_memo_type` is one of the three raw-SQL config tables (with `payment_term` and
 * `shipping_zone`) that have no Doctrine entity — they are driven entirely by Admin\ConfigController's
 * CONFIG_TABLES registry, which is why RawSqlTablesSurviveTheChainTest exists to stop a future
 * migration dropping them again on the grounds that "no entity maps them".
 *
 * Both test suites build their schema from entity metadata, so those three tables are absent there.
 * A foreign key declared here and not in the metadata would mean the constraint exists in production
 * and in no test that could ever exercise it — the exact divergence that produced the "no such
 * table" defect this codebase already has a regression test for. So the column is a plain integer on
 * both sides, and the admin screen resolves the label with the same raw query the config screen uses.
 *
 * Every other foreign key below points at a table that DOES have an entity, and matches the
 * metadata-built schema constraint for constraint.
 */
final class Version20260831120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#586: credit notes — credit_memo, its lines, addresses, applications and refunds. No backfill.';
    }

    public function up(Schema $schema): void
    {
        // The header. Inherits AbstractSalesDocument's columns (addresses live in their own table,
        // fee/tax lines and the company snapshot are CLOBs here), plus its own five:
        // document_number, status, credit_memo_type_id, reason and restock.
        //
        // invoice_id is PROVENANCE — "raised from INV-123" — and is nullable because a standalone
        // credit note is a first-class case: goodwill credits, pricing corrections, and credits
        // raised before anyone decides which invoice they land against. Where the balance actually
        // LANDS is credit_memo_application, below, because that relationship is many-to-many with an
        // amount.
        //
        // ON DELETE SET NULL rather than CASCADE: losing the attribution must never delete the record
        // of a credit that was actually given.
        $this->addSql(<<<'SQL'
            CREATE TABLE credit_memo (
                po_number VARCHAR(80) DEFAULT NULL,
                user_name VARCHAR(120) DEFAULT NULL,
                subtotal NUMERIC(12, 2) NOT NULL,
                tax NUMERIC(12, 2) NOT NULL,
                total NUMERIC(12, 2) NOT NULL,
                source VARCHAR(32) NOT NULL,
                special_instructions VARCHAR(255) DEFAULT NULL,
                fee_lines CLOB DEFAULT NULL,
                coupon_codes CLOB DEFAULT NULL,
                tax_lines CLOB DEFAULT NULL,
                company_snapshot CLOB DEFAULT NULL,
                shipping_method VARCHAR(120) DEFAULT NULL,
                fulfillment_region VARCHAR(160) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                document_number VARCHAR(32) NOT NULL,
                status VARCHAR(20) NOT NULL,
                credit_memo_type_id INTEGER DEFAULT NULL,
                reason CLOB DEFAULT NULL,
                restock BOOLEAN DEFAULT 0 NOT NULL,
                document_date VARCHAR(10) NOT NULL,
                company_id INTEGER NOT NULL,
                invoice_id INTEGER DEFAULT NULL,
                CONSTRAINT FK_AC882F17979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_AC882F172989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AC882F1728F2AE32 ON credit_memo (document_number)');
        $this->addSql('CREATE INDEX IDX_AC882F17979B1AD6 ON credit_memo (company_id)');
        $this->addSql('CREATE INDEX IDX_AC882F172989F1FD ON credit_memo (invoice_id)');

        // The product rows. Quantities are POSITIVE — a credit note is not a negative invoice, and
        // every quantity aggregate in this app assumes positive quantities.
        //
        // invoice_line_id is what makes the inventory net-out possible: InvoiceLine::getCreditedUnits()
        // sums these rows and InvoiceReservationSubject subtracts the total from what that invoice
        // line holds, so `invoice_inventory_reservation.quantity` drops through the reconciler that
        // already exists rather than through a third reservation table.
        $this->addSql(<<<'SQL'
            CREATE TABLE credit_memo_line (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                name VARCHAR(255) NOT NULL,
                sku VARCHAR(80) DEFAULT NULL,
                location VARCHAR(120) DEFAULT NULL,
                quantity NUMERIC(12, 2) NOT NULL,
                unit VARCHAR(80) DEFAULT NULL,
                tax_code VARCHAR(80) DEFAULT NULL,
                price NUMERIC(12, 2) NOT NULL,
                subtotal NUMERIC(12, 2) NOT NULL,
                sort_order INTEGER DEFAULT 0 NOT NULL,
                credit_memo_id INTEGER NOT NULL,
                invoice_line_id INTEGER DEFAULT NULL,
                product_id INTEGER DEFAULT NULL,
                CONSTRAINT FK_4B3489CA8E574316 FOREIGN KEY (credit_memo_id) REFERENCES credit_memo (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_4B3489CABFA24391 FOREIGN KEY (invoice_line_id) REFERENCES invoice_line (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_4B3489CA4584665A FOREIGN KEY (product_id) REFERENCES product_core (id) NOT DEFERRABLE INITIALLY IMMEDIATE
            )
            SQL);
        $this->addSql('CREATE INDEX IDX_4B3489CA8E574316 ON credit_memo_line (credit_memo_id)');
        $this->addSql('CREATE INDEX IDX_4B3489CABFA24391 ON credit_memo_line (invoice_line_id)');
        $this->addSql('CREATE INDEX IDX_4B3489CA4584665A ON credit_memo_line (product_id)');

        // The note's own frozen addresses, for the reason every document freezes them: the customer
        // may move between the sale and the return, and the note is a record of the original
        // transaction being partly undone.
        $this->addSql(<<<'SQL'
            CREATE TABLE credit_memo_address (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                type VARCHAR(16) NOT NULL,
                first_name VARCHAR(120) DEFAULT NULL,
                last_name VARCHAR(120) DEFAULT NULL,
                company_name VARCHAR(255) DEFAULT NULL,
                email_primary VARCHAR(255) DEFAULT NULL,
                email_secondary VARCHAR(255) DEFAULT NULL,
                phone VARCHAR(40) DEFAULT NULL,
                fax VARCHAR(40) DEFAULT NULL,
                address_line1 VARCHAR(255) DEFAULT NULL,
                address_line2 VARCHAR(255) DEFAULT NULL,
                city VARCHAR(120) DEFAULT NULL,
                province VARCHAR(6) DEFAULT NULL,
                country VARCHAR(2) DEFAULT NULL,
                postal_code VARCHAR(20) DEFAULT NULL,
                delivery_instructions CLOB DEFAULT NULL,
                source_address_id INTEGER DEFAULT NULL,
                credit_memo_id INTEGER NOT NULL,
                CONSTRAINT FK_71B91B998903DE51 FOREIGN KEY (source_address_id) REFERENCES company_address (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_71B91B998E574316 FOREIGN KEY (credit_memo_id) REFERENCES credit_memo (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
            SQL);
        $this->addSql('CREATE INDEX IDX_71B91B998903DE51 ON credit_memo_address (source_address_id)');
        $this->addSql('CREATE INDEX IDX_71B91B998E574316 ON credit_memo_address (credit_memo_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_credit_memo_address_type ON credit_memo_address (credit_memo_id, type)');

        // Where the balance lands. THE RELATIONSHIP IS MANY-TO-MANY WITH AN AMOUNT — one note pays
        // down three invoices, one invoice takes credit from two notes — which is why this is a row
        // with a figure and a date on it and not a foreign key. `applied_at` is not derivable from
        // the note's own date: a note raised in March and spent in May has two dates, and this is the
        // one that answers "what was the invoice owed on the 30th".
        //
        // invoice_id is NOT NULL and carries no ON DELETE clause. An allocation with no invoice is
        // not an allocation, and an invoice is never deleted in this app — a withdrawn one is
        // Cancelled and keeps its number forever.
        $this->addSql(<<<'SQL'
            CREATE TABLE credit_memo_application (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                amount NUMERIC(12, 2) NOT NULL,
                applied_at DATE NOT NULL,
                credit_memo_id INTEGER NOT NULL,
                invoice_id INTEGER NOT NULL,
                CONSTRAINT FK_604028508E574316 FOREIGN KEY (credit_memo_id) REFERENCES credit_memo (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_604028502989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id) NOT DEFERRABLE INITIALLY IMMEDIATE
            )
            SQL);
        $this->addSql('CREATE INDEX IDX_604028508E574316 ON credit_memo_application (credit_memo_id)');
        $this->addSql('CREATE INDEX idx_credit_memo_application_invoice ON credit_memo_application (invoice_id)');

        // Money paid back. `invoice_payment` column for column, MINUS stripe_payment_intent_id —
        // this has nothing to do with a gateway. An admin logging a refund they have already made is
        // the whole feature, and carrying a column "for symmetry" is precisely how product_inventory
        // ended up with incoming_quantity, reserved_quantity and manual_adjustment, three columns
        // nothing has ever written.
        $this->addSql(<<<'SQL'
            CREATE TABLE credit_memo_refund (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                refunded_at DATE NOT NULL,
                method VARCHAR(64) NOT NULL,
                amount NUMERIC(12, 2) NOT NULL,
                comment VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                credit_memo_id INTEGER NOT NULL,
                user_id INTEGER DEFAULT NULL,
                CONSTRAINT FK_70B7BB968E574316 FOREIGN KEY (credit_memo_id) REFERENCES credit_memo (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_70B7BB96A76ED395 FOREIGN KEY (user_id) REFERENCES admin_user (id) NOT DEFERRABLE INITIALLY IMMEDIATE
            )
            SQL);
        $this->addSql('CREATE INDEX IDX_70B7BB968E574316 ON credit_memo_refund (credit_memo_id)');
        $this->addSql('CREATE INDEX IDX_70B7BB96A76ED395 ON credit_memo_refund (user_id)');
    }

    public function down(Schema $schema): void
    {
        // Children first: SQLite enforces the foreign keys above when they are on.
        $this->addSql('DROP TABLE IF EXISTS credit_memo_refund');
        $this->addSql('DROP TABLE IF EXISTS credit_memo_application');
        $this->addSql('DROP TABLE IF EXISTS credit_memo_address');
        $this->addSql('DROP TABLE IF EXISTS credit_memo_line');
        $this->addSql('DROP TABLE IF EXISTS credit_memo');
    }
}
