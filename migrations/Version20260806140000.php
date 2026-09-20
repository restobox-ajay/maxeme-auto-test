<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Store a document's calendar date as a string, not a date.
 *
 * po_date holds a calendar date, and a calendar date has no instant — "this order is dated the 6th"
 * is true in every timezone at once. Declaring it DATE gave it one: the value became a midnight,
 * and every layer that saw a midnight was entitled to convert it. Storage is pinned to UTC, display
 * applies the configured timezone (DisplayTimezoneSubscriber), so a shop running a zone behind UTC
 * could raise an order that stored tomorrow's date and rendered yesterday's. See
 * AbstractSalesDocument::$poDate.
 *
 * VARCHAR(10) is what the mapping now declares, and the value it holds is unchanged: SQLite gives a
 * DATE column NUMERIC affinity, under which '2026-08-06' is not a number and is already stored as
 * text. So no data is rewritten here and nothing needs converting — sorting and range comparison
 * were lexicographic on 'Y-m-d' before this migration and still are after it, which is exactly why
 * that format is the one being kept.
 *
 * The tables are rebuilt because SQLite has no ALTER COLUMN. Only the type changes; the column
 * lists, constraints and indexes below are the ones Version20260803150000 created, reproduced
 * verbatim apart from the three po_date declarations, so a database that replays the chain and one
 * built from entity metadata cannot disagree.
 */
final class Version20260806140000 extends AbstractMigration
{
    /**
     * Ten other tables hold foreign keys into these three (order/quote lines, logs, addresses,
     * payments, cart items, and estimate.converted_order_id into sales_order). DROP TABLE would
     * cascade through every one of them, and PRAGMA foreign_keys is SILENTLY IGNORED inside a
     * transaction — which is how a migration in this repo's history reported complete success while
     * deleting the address snapshots the migration before it had just backfilled.
     *
     * So the rebuild runs outside a transaction, with the pragma bracketing it explicitly where it
     * will actually take effect.
     */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Store cart/estimate/sales_order po_date as VARCHAR(10) rather than DATE.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('PRAGMA foreign_keys = OFF');

        $this->rebuild(
            'cart',
            'po_number, user_name, po_date, subtotal, tax, total, source, special_instructions, fee_lines, coupon_codes, tax_lines, company_snapshot, shipping_method, fulfillment_region, created_at, id, session_id, hold_expires_at, updated_at, company_id',
            'CREATE TABLE cart (po_number VARCHAR(80) DEFAULT NULL, user_name VARCHAR(120) DEFAULT NULL, po_date VARCHAR(10) DEFAULT NULL, subtotal NUMERIC(12, 2) NOT NULL, tax NUMERIC(12, 2) NOT NULL, total NUMERIC(12, 2) NOT NULL, source VARCHAR(32) NOT NULL, special_instructions VARCHAR(255) DEFAULT NULL, fee_lines CLOB DEFAULT NULL, coupon_codes CLOB DEFAULT NULL, tax_lines CLOB DEFAULT NULL, company_snapshot CLOB DEFAULT NULL, shipping_method VARCHAR(120) DEFAULT NULL, fulfillment_region VARCHAR(160) DEFAULT NULL, created_at DATETIME NOT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, session_id VARCHAR(128) NOT NULL, hold_expires_at DATETIME DEFAULT NULL, updated_at DATETIME NOT NULL, company_id INTEGER DEFAULT NULL, CONSTRAINT FK_BA388B7979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)',
            [
                'CREATE UNIQUE INDEX UNIQ_BA388B7613FECDF ON cart (session_id)',
                'CREATE INDEX IDX_BA388B7979B1AD6 ON cart (company_id)',
            ],
        );

        $this->rebuild(
            'sales_order',
            'po_number, user_name, subtotal, tax, total, source, special_instructions, fee_lines, coupon_codes, tax_lines, company_snapshot, shipping_method, fulfillment_region, created_at, id, order_number, status, invoice_date, payment_status, payment_method, payment_term, po_date, company_id',
            'CREATE TABLE sales_order (po_number VARCHAR(80) DEFAULT NULL, user_name VARCHAR(120) DEFAULT NULL, subtotal NUMERIC(12, 2) NOT NULL, tax NUMERIC(12, 2) NOT NULL, total NUMERIC(12, 2) NOT NULL, source VARCHAR(32) NOT NULL, special_instructions VARCHAR(255) DEFAULT NULL, fee_lines CLOB DEFAULT NULL, coupon_codes CLOB DEFAULT NULL, tax_lines CLOB DEFAULT NULL, company_snapshot CLOB DEFAULT NULL, shipping_method VARCHAR(120) DEFAULT NULL, fulfillment_region VARCHAR(160) DEFAULT NULL, created_at DATETIME NOT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, order_number VARCHAR(32) NOT NULL, status VARCHAR(32) NOT NULL, invoice_date DATE DEFAULT NULL, payment_status VARCHAR(40) DEFAULT NULL, payment_method VARCHAR(120) DEFAULT NULL, payment_term VARCHAR(120) DEFAULT NULL, po_date VARCHAR(10) NOT NULL, company_id INTEGER NOT NULL, CONSTRAINT FK_36D222E979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) NOT DEFERRABLE INITIALLY IMMEDIATE)',
            [
                'CREATE UNIQUE INDEX UNIQ_36D222E551F0F81 ON sales_order (order_number)',
                'CREATE INDEX IDX_36D222E979B1AD6 ON sales_order (company_id)',
            ],
        );

        // After sales_order: estimate.converted_order_id points at it, and rebuilding the
        // referenced table first means this one is recreated against a table that already exists
        // in its final shape.
        $this->rebuild(
            'estimate',
            'po_number, user_name, source, special_instructions, fee_lines, coupon_codes, tax_lines, company_snapshot, shipping_method, fulfillment_region, created_at, id, document_number, status, subtotal, tax, total, po_date, company_id, converted_order_id',
            'CREATE TABLE estimate (po_number VARCHAR(80) DEFAULT NULL, user_name VARCHAR(120) DEFAULT NULL, source VARCHAR(32) NOT NULL, special_instructions VARCHAR(255) DEFAULT NULL, fee_lines CLOB DEFAULT NULL, coupon_codes CLOB DEFAULT NULL, tax_lines CLOB DEFAULT NULL, company_snapshot CLOB DEFAULT NULL, shipping_method VARCHAR(120) DEFAULT NULL, fulfillment_region VARCHAR(160) DEFAULT NULL, created_at DATETIME NOT NULL, id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, document_number VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL, subtotal NUMERIC(12, 2) DEFAULT NULL, tax NUMERIC(12, 2) DEFAULT NULL, total NUMERIC(12, 2) DEFAULT NULL, po_date VARCHAR(10) NOT NULL, company_id INTEGER NOT NULL, converted_order_id INTEGER DEFAULT NULL, CONSTRAINT FK_D2EA4607979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_D2EA4607AB17FB50 FOREIGN KEY (converted_order_id) REFERENCES sales_order (id) NOT DEFERRABLE INITIALLY IMMEDIATE)',
            [
                'CREATE UNIQUE INDEX UNIQ_D2EA460728F2AE32 ON estimate (document_number)',
                'CREATE INDEX IDX_D2EA4607979B1AD6 ON estimate (company_id)',
                'CREATE INDEX IDX_D2EA4607AB17FB50 ON estimate (converted_order_id)',
            ],
        );

        $this->addSql('PRAGMA foreign_keys = ON');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'po_date is a string column now. Going back means rebuilding all three tables again to '
            . 're-declare it DATE, for a change that alters no stored value — restore from a backup '
            . 'instead if you genuinely need the old declaration.'
        );
    }

    /**
     * The documented SQLite table-rebuild: copy out, drop, recreate, copy back.
     *
     * The column list is named explicitly in both directions rather than using SELECT *, so this
     * fails loudly against a table whose shape has drifted instead of silently copying whatever
     * happens to be there in whatever order it happens to be in.
     *
     * @param list<string> $indexes
     */
    private function rebuild(string $table, string $columns, string $createTable, array $indexes): void
    {
        $this->addSql(sprintf('CREATE TEMPORARY TABLE __temp__%s AS SELECT %s FROM %s', $table, $columns, $table));
        $this->addSql(sprintf('DROP TABLE %s', $table));
        $this->addSql($createTable);
        $this->addSql(sprintf('INSERT INTO %s (%s) SELECT %s FROM __temp__%s', $table, $columns, $columns, $table));
        $this->addSql(sprintf('DROP TABLE __temp__%s', $table));

        foreach ($indexes as $index) {
            $this->addSql($index);
        }
    }
}
