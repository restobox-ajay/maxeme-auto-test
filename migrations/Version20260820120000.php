<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 1 of #539: Invoice becomes its own document, and every existing order gets exactly one.
 *
 * A sales order used to be its own invoice — it carried payment, the payment-driven statuses and
 * the inventory holds, and printed itself as an invoice. That is true for a webstore and false for
 * a distribution business, where one accepted order is shipped and billed across several invoices.
 *
 * This migration only creates the tables and the 1:1 shadow. Nothing moves OFF sales_order here:
 * payment and inventory stay authoritative on the order until stages 3 and 4, and the columns
 * copied onto invoice are copies, deliberately, so the two agree from the outset rather than one
 * being half-migrated. Every later stage can then assume the invariant "every order has at least
 * one invoice" is already true everywhere.
 *
 * ## Why the backfill writes data at all
 *
 * Confirmed with the client before writing: no production instance holds any order data — every
 * instance is a dev one. That is what makes re-anchoring cheap enough to do outright rather than
 * behind a compatibility layer, and it has a shelf life. If real order data exists anywhere by the
 * time this runs, the backfill's status mapping in particular should be re-confirmed first.
 *
 * ## Numbering
 *
 * Invoices are numbered <prefix><n> from 1, in order id order, honouring invoice_number_prefix if
 * an admin has already set one and 'INV-' otherwise (matching InvoiceNumberGenerator and
 * ConfigController::documentPrefixes()). No document_number_counter row is written: the allocator
 * seeds a (kind, prefix) counter from MAX(...) on first use, so it picks up from whatever this
 * leaves behind — including for a prefix that is changed later.
 *
 * The number is computed in the INSERT itself, with a window function over the order ids. An
 * earlier version wrote every row with an empty document_number and filled them in with a follow-up
 * UPDATE from the invoice's own id; that dies on the second row against the unique index above,
 * since '' is not unique. Doing it in one statement also drops the assumption that the new table's
 * ids come out as exactly 1..N.
 *
 * ## Status mapping
 *
 * The order's fulfilment status becomes the invoice's, one for one, since these cases were always
 * really the invoice's. Anything else — the quote-era strings, and the stray 'Approved'/'APPROVED'
 * rows that predate the current enum — becomes Draft, which is the only case that asserts nothing
 * about goods or money. sales_order.status is left exactly as it is; rewriting it is stage 2's job.
 */
final class Version20260820120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#539 stage 1: invoice tables, and one invoice per existing sales order.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE invoice (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                company_id INTEGER NOT NULL,
                sales_order_id INTEGER DEFAULT NULL,
                document_number VARCHAR(32) NOT NULL,
                status VARCHAR(20) NOT NULL,
                invoice_date VARCHAR(10) DEFAULT NULL,
                due_date VARCHAR(10) DEFAULT NULL,
                payment_status VARCHAR(40) DEFAULT NULL,
                payment_method VARCHAR(120) DEFAULT NULL,
                payment_term VARCHAR(120) DEFAULT NULL,
                version INTEGER DEFAULT 1 NOT NULL,
                po_number VARCHAR(80) DEFAULT NULL,
                user_name VARCHAR(120) DEFAULT NULL,
                document_date VARCHAR(10) NOT NULL,
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
                CONSTRAINT fk_invoice_company FOREIGN KEY (company_id) REFERENCES company (id),
                CONSTRAINT fk_invoice_sales_order FOREIGN KEY (sales_order_id) REFERENCES sales_order (id)
            )
        SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_invoice_document_number ON invoice (document_number)');
        // The order page lists its invoices, and stage 2 derives the order's status from them, so
        // this is read once per order view rather than occasionally.
        $this->addSql('CREATE INDEX idx_invoice_sales_order ON invoice (sales_order_id)');
        // Every join column gets an index, which is what Doctrine generates for a ManyToOne and
        // what sales_order/sales_order_line/sales_order_address each already have. Left off, the
        // invoice tables would be the only document tables in the schema without them.
        $this->addSql('CREATE INDEX idx_invoice_company ON invoice (company_id)');

        // The product FK carries no ON DELETE, matching sales_order_line: a product that has been
        // billed cannot be deleted out from under the record of billing it.
        $this->addSql(<<<'SQL'
            CREATE TABLE invoice_line (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                invoice_id INTEGER NOT NULL,
                sales_order_line_id INTEGER DEFAULT NULL,
                -- Deliberately NO foreign key to product_core, matching sales_order_line.
                -- A document line snapshots a product; it must outlive that product's deletion,
                -- and product imports delete rows routinely. The dev database has 2000 order lines
                -- pointing at products that no longer exist, and every one of them is a correct
                -- record of something that was sold. A constraint here makes the invoice stricter
                -- than the order it copies, so the copy cannot hold what the original holds.
                product_id INTEGER DEFAULT NULL,
                name VARCHAR(255) NOT NULL,
                location VARCHAR(120) DEFAULT NULL,
                sku VARCHAR(80) DEFAULT NULL,
                quantity NUMERIC(12, 2) NOT NULL,
                weight VARCHAR(80) DEFAULT NULL,
                unit VARCHAR(80) DEFAULT NULL,
                tax_code VARCHAR(80) DEFAULT NULL,
                cost NUMERIC(12, 2) NOT NULL,
                price NUMERIC(12, 2) NOT NULL,
                subtotal NUMERIC(12, 2) NOT NULL,
                batch VARCHAR(120) DEFAULT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                CONSTRAINT fk_invoice_line_invoice FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE CASCADE,
                CONSTRAINT fk_invoice_line_order_line FOREIGN KEY (sales_order_line_id) REFERENCES sales_order_line (id) ON DELETE SET NULL
            )
        SQL);

        $this->addSql('CREATE INDEX idx_invoice_line_invoice ON invoice_line (invoice_id)');
        // Uninvoiced quantity is derived per order line, so this is the direction stage 2 reads in.
        $this->addSql('CREATE INDEX idx_invoice_line_order_line ON invoice_line (sales_order_line_id)');
        $this->addSql('CREATE INDEX idx_invoice_line_product ON invoice_line (product_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE invoice_address (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                invoice_id INTEGER NOT NULL,
                source_address_id INTEGER DEFAULT NULL,
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
                CONSTRAINT fk_invoice_address_parent FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE CASCADE,
                CONSTRAINT fk_invoice_address_source FOREIGN KEY (source_address_id) REFERENCES company_address (id) ON DELETE SET NULL
            )
        SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_invoice_address_type ON invoice_address (invoice_id, type)');
        $this->addSql('CREATE INDEX idx_invoice_address_source ON invoice_address (source_address_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE invoice_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                invoice_id INTEGER NOT NULL,
                user_name VARCHAR(255) DEFAULT NULL,
                comment CLOB NOT NULL,
                type VARCHAR(64) NOT NULL,
                customer_notified BOOLEAN NOT NULL,
                created_at DATETIME NOT NULL,
                CONSTRAINT fk_invoice_log_invoice FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE CASCADE
            )
        SQL);

        $this->addSql('CREATE INDEX idx_invoice_log_invoice ON invoice_log (invoice_id)');

        $this->backfillOneInvoicePerOrder();
    }

    /**
     * One invoice per existing order, carrying its lines and addresses.
     *
     * Order logs are deliberately NOT copied: they are the order's history, and duplicating them
     * onto a document that did not exist when they were written would put events on a timeline
     * that never happened there.
     */
    private function backfillOneInvoicePerOrder(): void
    {
        $prefix = $this->connection->fetchOne(
            "SELECT setting_value FROM app_setting WHERE setting_key = 'invoice_number_prefix'"
        );
        $prefix = is_string($prefix) ? strtoupper(trim($prefix)) : '';
        if ($prefix === '' || !preg_match('/^[A-Z0-9-]{1,10}$/', $prefix)) {
            $prefix = 'INV-';
        }

        // ROW_NUMBER() over the order ids numbers the invoices 1..N in one pass, so numbering
        // follows order id order without a correlated subquery per row across 2000+ orders and
        // without a second statement that would have to leave a non-unique placeholder behind.
        $this->addSql(<<<'SQL'
            INSERT INTO invoice (
                company_id, sales_order_id, document_number, status,
                invoice_date, due_date, payment_status, payment_method, payment_term, version,
                po_number, user_name, document_date, subtotal, tax, total, source,
                special_instructions, fee_lines, coupon_codes, tax_lines, company_snapshot,
                shipping_method, fulfillment_region, created_at
            )
            SELECT
                o.company_id, o.id, ? || ROW_NUMBER() OVER (ORDER BY o.id),
                CASE o.status
                    WHEN 'Draft' THEN 'Draft'
                    WHEN 'Pending' THEN 'Pending'
                    WHEN 'On Hold' THEN 'On Hold'
                    WHEN 'Processing' THEN 'Processing'
                    WHEN 'Completed' THEN 'Completed'
                    WHEN 'Cancelled' THEN 'Cancelled'
                    ELSE 'Draft'
                END,
                COALESCE(o.invoice_date, o.document_date), NULL,
                o.payment_status, o.payment_method, o.payment_term, 1,
                o.po_number, o.user_name, o.document_date, o.subtotal, o.tax, o.total, o.source,
                o.special_instructions, o.fee_lines, o.coupon_codes, o.tax_lines, o.company_snapshot,
                o.shipping_method, o.fulfillment_region, o.created_at
            FROM sales_order o
            ORDER BY o.id
        SQL, [$prefix]);

        $this->addSql(<<<'SQL'
            INSERT INTO invoice_line (
                invoice_id, sales_order_line_id, product_id, name, location, sku, quantity,
                weight, unit, tax_code, cost, price, subtotal, batch, sort_order
            )
            SELECT
                i.id, l.id, l.product_id, l.name, l.location, l.sku, l.quantity,
                l.weight, l.unit, l.tax_code, l.cost, l.price, l.subtotal, l.batch, l.sort_order
            FROM sales_order_line l
            INNER JOIN invoice i ON i.sales_order_id = l.order_id
            ORDER BY l.order_id, l.sort_order, l.id
        SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO invoice_address (
                invoice_id, source_address_id, type, first_name, last_name, company_name,
                email_primary, email_secondary, phone, fax, address_line1, address_line2,
                city, province, country, postal_code, delivery_instructions
            )
            SELECT
                i.id, a.source_address_id, a.type, a.first_name, a.last_name, a.company_name,
                a.email_primary, a.email_secondary, a.phone, a.fax, a.address_line1, a.address_line2,
                a.city, a.province, a.country, a.postal_code, a.delivery_instructions
            FROM sales_order_address a
            INNER JOIN invoice i ON i.sales_order_id = a.order_id
        SQL);
    }

    public function down(Schema $schema): void
    {
        // The counter row, if the allocator has since created one, goes with them — leaving it
        // would have a re-run of up() continue from a sequence whose documents no longer exist.
        $this->addSql("DELETE FROM document_number_counter WHERE kind = 'invoice'");
        $this->addSql('DROP TABLE IF EXISTS invoice_log');
        $this->addSql('DROP TABLE IF EXISTS invoice_address');
        $this->addSql('DROP TABLE IF EXISTS invoice_line');
        $this->addSql('DROP TABLE IF EXISTS invoice');
    }
}
