<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Store the invoice date as a calendar date, not an instant — the same change Version20260806140000
 * made for po_date, for the same reason, on the one column it left behind.
 *
 * invoice_date was still DATE, so Doctrine hydrated it into a DateTimeImmutable at midnight and the
 * templates rendered it with Twig's date filter — which DisplayTimezoneSubscriber has already
 * pointed at the configured display zone. A value stored as 2026-05-09 therefore printed as
 * 2026-05-08 on the order detail page, the admin invoice and the customer invoice PDF for any shop
 * displaying a zone behind UTC. That was reproduced against the running page before this migration
 * was written, not inferred: stored '2026-05-09', rendered "Invoice Date: 2026-05-08" under
 * Pacific/Niue, with Order Date on the same page correctly showing the 9th because it had already
 * been converted to a string.
 *
 * As with po_date, no stored value is rewritten. SQLite gives a DATE column NUMERIC affinity, under
 * which '2026-05-09' is not a number and is already held as text; only the declared type changes,
 * and comparison and ordering are identical because 'Y-m-d' sorts lexicographically in calendar
 * order.
 *
 * The table is rebuilt because SQLite has no ALTER COLUMN. The column list and constraints below
 * are taken verbatim from `doctrine:schema:create --dump-sql`.
 *
 * It also REPAIRS a column Version20260806140000 destroyed. That migration rebuilt this same table
 * and its column list omitted `version` — the optimistic-lock counter #[ORM\Version] requires
 * (#417), added by Version20260805003000 — so every database that replays the chain loses it. Its
 * docblock claims its definitions came from `doctrine:schema:create --dump-sql`; they were actually
 * copied from the Version20260803150000 baseline, which predates the column. Nothing caught it:
 * bin/ci-migration-replay validates mappings with --skip-sync rather than comparing against the
 * database, and both test suites build their schema from entity metadata and never run the chain at
 * all. It surfaced only when THIS migration's rebuild tried to SELECT the column and found it gone.
 *
 * The consequence on a migrated database is not subtle — Doctrine names `version` in every INSERT
 * and UPDATE of a SalesOrder, so saving an order fails outright.
 *
 * Version20260806140000 is left alone. It has run; editing an applied migration makes two databases
 * that ran "the same" chain disagree. Repairing forward is the only safe direction.
 */
final class Version20260807010000 extends AbstractMigration
{
    /**
     * Six other tables hold foreign keys into sales_order (its lines, logs, payments, addresses, and
     * estimate.converted_order_id). DROP TABLE would cascade through all of them, and PRAGMA
     * foreign_keys is SILENTLY IGNORED inside a transaction — which is how a migration in this
     * repo's history reported complete success while deleting rows a previous migration had just
     * backfilled. So the rebuild runs outside a transaction, with the pragma bracketing it where it
     * will actually take effect.
     */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Store sales_order.invoice_date as VARCHAR(10) rather than DATE.';
    }

    public function up(Schema $schema): void
    {
        // What the new table has. `version` is always in it, because the mapping always has it.
        $columns = 'po_number, user_name, subtotal, tax, total, source, special_instructions, fee_lines, coupon_codes, tax_lines, company_snapshot, shipping_method, fulfillment_region, created_at, id, order_number, status, invoice_date, payment_status, payment_method, payment_term, version, po_date, company_id';

        // What the OLD table has, which is not the same thing on a database that ran
        // Version20260806140000 — see the class docblock. Carrying `version` across only when it is
        // actually there means this works on both shapes: an existing counter is preserved, and a
        // destroyed one comes back at the DEFAULT 1 that Version20260805003000 backfilled every row
        // to in the first place. Asked of the schema rather than assumed, so neither case needs a
        // separate migration.
        $hasVersion = $schema->hasTable('sales_order')
            && $schema->getTable('sales_order')->hasColumn('version');
        $carried = $hasVersion ? $columns : str_replace(', version', '', $columns);

        $this->addSql('PRAGMA foreign_keys = OFF');
        $this->addSql(sprintf('CREATE TEMPORARY TABLE __temp__sales_order AS SELECT %s FROM sales_order', $carried));
        $this->addSql('DROP TABLE sales_order');
        $this->addSql('CREATE TABLE sales_order (po_number VARCHAR(80) DEFAULT NULL, user_name VARCHAR(120) DEFAULT NULL, subtotal NUMERIC(12, 2) NOT NULL, tax NUMERIC(12, 2) NOT NULL, total NUMERIC(12, 2) NOT NULL, source VARCHAR(32) NOT NULL, special_instructions VARCHAR(255) DEFAULT NULL, fee_lines CLOB DEFAULT NULL, coupon_codes CLOB DEFAULT NULL, tax_lines CLOB DEFAULT NULL, company_snapshot CLOB DEFAULT NULL, shipping_method VARCHAR(120) DEFAULT NULL, fulfillment_region VARCHAR(160) DEFAULT NULL, created_at DATETIME NOT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, order_number VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, invoice_date VARCHAR(10) DEFAULT NULL, payment_status VARCHAR(40) DEFAULT NULL, payment_method VARCHAR(120) DEFAULT NULL, payment_term VARCHAR(120) DEFAULT NULL, version INTEGER DEFAULT 1 NOT NULL, po_date VARCHAR(10) NOT NULL, company_id INTEGER NOT NULL, CONSTRAINT FK_36D222E979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql(sprintf('INSERT INTO sales_order (%s) SELECT %s FROM __temp__sales_order', $carried, $carried));
        $this->addSql('DROP TABLE __temp__sales_order');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_36D222E551F0F81 ON sales_order (order_number)');
        $this->addSql('CREATE INDEX IDX_36D222E979B1AD6 ON sales_order (company_id)');
        $this->addSql('PRAGMA foreign_keys = ON');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'invoice_date is a string column now. Going back means rebuilding the table again to '
            . 're-declare it DATE, for a change that alters no stored value — restore from a backup '
            . 'instead if you genuinely need the old declaration.'
        );
    }
}
