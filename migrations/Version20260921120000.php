<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `woocommerce_order_import` (#739) — one row per WooCommerce order the connector has ever seen,
 * for two jobs at once: the webhook receiver's idempotency check (a redelivered webhook must not
 * mint a second SalesOrder/Invoice for the same Woo order id) and the backing table for the
 * WooCommerce "All orders" admin screen.
 *
 * `company_id`/`sales_order_id`/`invoice_id` are all nullable and ON DELETE SET NULL: a row whose
 * status is Error carries none of them (nothing was created), and even an Imported row must survive
 * its order or invoice being deleted elsewhere in the app — the import row is a record of what
 * happened, not a hard dependency of those documents continuing to exist.
 *
 * One CREATE TABLE, which touches nothing that exists — no ALTER TABLE, no rebuild.
 */
final class Version20260921120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add woocommerce_order_import (WooCommerce connector order-ingestion idempotency + orders screen).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE woocommerce_order_import ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, '
            . 'connection_id INTEGER NOT NULL, '
            . 'woo_order_id INTEGER NOT NULL, '
            . 'woo_order_number VARCHAR(40) NOT NULL, '
            . 'company_id INTEGER DEFAULT NULL, '
            . 'sales_order_id INTEGER DEFAULT NULL, '
            . 'invoice_id INTEGER DEFAULT NULL, '
            . 'status VARCHAR(20) NOT NULL, '
            . 'error_message CLOB DEFAULT NULL, '
            . 'imported_at DATETIME NOT NULL, '
            . 'CONSTRAINT FK_woo_order_import_connection FOREIGN KEY (connection_id) REFERENCES woocommerce_connection (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, '
            . 'CONSTRAINT FK_woo_order_import_company FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, '
            . 'CONSTRAINT FK_woo_order_import_sales_order FOREIGN KEY (sales_order_id) REFERENCES sales_order (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, '
            . 'CONSTRAINT FK_woo_order_import_invoice FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE'
            . ')'
        );
        $this->addSql('CREATE UNIQUE INDEX uniq_woo_order_import_connection_order ON woocommerce_order_import (connection_id, woo_order_id)');
        $this->addSql('CREATE INDEX idx_woo_order_import_company ON woocommerce_order_import (company_id)');
        $this->addSql('CREATE INDEX idx_woo_order_import_sales_order ON woocommerce_order_import (sales_order_id)');
        $this->addSql('CREATE INDEX idx_woo_order_import_invoice ON woocommerce_order_import (invoice_id)');
        $this->addSql('CREATE INDEX idx_woo_order_import_status ON woocommerce_order_import (status)');
    }

    /**
     * Reversible outright: this migration creates one table and nothing else, so dropping it loses
     * only this feature's own data.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE woocommerce_order_import');
    }
}
