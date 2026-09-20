<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `woocommerce_connection` and `woocommerce_product_mapping` (#739/#741) — the WooCommerce
 * connector's own tables, added by `modules/WooCommerceBundle`.
 *
 * `woocommerce_connection`: one row per Woo store this app talks to. `tier_price_list_map` is a
 * Doctrine `json` column, which on this project's SQLite platform renders as CLOB — same as every
 * other `json`-typed column already in this schema (`admin_column_preference.columns`,
 * `admin_user.roles`, `sales_order.fee_lines`, etc.).
 *
 * `woocommerce_product_mapping`: remembers how one connection's Woo SKU resolves to an internal
 * product, so a repeat order for the same SKU resolves automatically instead of minting a second
 * private product. Unique on (connection_id, woo_sku) — one mapping per SKU per store.
 *
 * Two CREATE TABLEs, which touch nothing that exists — no ALTER TABLE, no rebuild.
 */
final class Version20260921090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add woocommerce_connection and woocommerce_product_mapping (WooCommerce connector).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE woocommerce_connection ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, '
            . 'name VARCHAR(120) NOT NULL, '
            . 'slug VARCHAR(60) NOT NULL, '
            . 'store_url VARCHAR(255) NOT NULL, '
            . 'consumer_key VARCHAR(255) NOT NULL, '
            . 'consumer_secret VARCHAR(255) NOT NULL, '
            . 'webhook_secret VARCHAR(255) NOT NULL, '
            . 'is_active BOOLEAN DEFAULT 1 NOT NULL, '
            . 'default_warehouse_id INTEGER DEFAULT NULL, '
            . 'availability_buffer INTEGER DEFAULT 0 NOT NULL, '
            . 'tier_price_list_map CLOB NOT NULL, '
            . 'default_price_list_id INTEGER DEFAULT NULL, '
            . 'created_at DATETIME NOT NULL, '
            . 'last_order_at DATETIME DEFAULT NULL, '
            . 'CONSTRAINT FK_woocommerce_connection_warehouse FOREIGN KEY (default_warehouse_id) REFERENCES warehouse (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, '
            . 'CONSTRAINT FK_woocommerce_connection_price_list FOREIGN KEY (default_price_list_id) REFERENCES price_list (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE'
            . ')'
        );
        $this->addSql('CREATE UNIQUE INDEX uniq_woocommerce_connection_slug ON woocommerce_connection (slug)');
        $this->addSql('CREATE INDEX idx_woocommerce_connection_warehouse ON woocommerce_connection (default_warehouse_id)');
        $this->addSql('CREATE INDEX idx_woocommerce_connection_price_list ON woocommerce_connection (default_price_list_id)');

        $this->addSql(
            'CREATE TABLE woocommerce_product_mapping ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, '
            . 'connection_id INTEGER NOT NULL, '
            . 'woo_sku VARCHAR(120) NOT NULL, '
            . 'product_id INTEGER NOT NULL, '
            . 'flagged BOOLEAN DEFAULT 0 NOT NULL, '
            . 'created_at DATETIME NOT NULL, '
            . 'CONSTRAINT FK_woo_product_mapping_connection FOREIGN KEY (connection_id) REFERENCES woocommerce_connection (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, '
            . 'CONSTRAINT FK_woo_product_mapping_product FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE'
            . ')'
        );
        $this->addSql('CREATE UNIQUE INDEX uniq_woo_product_mapping_connection_sku ON woocommerce_product_mapping (connection_id, woo_sku)');
        $this->addSql('CREATE INDEX idx_woo_product_mapping_product ON woocommerce_product_mapping (product_id)');
    }

    /**
     * Reversible outright: this migration creates two tables and nothing else, so dropping them
     * (child before parent) loses only this feature's own data.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE woocommerce_product_mapping');
        $this->addSql('DROP TABLE woocommerce_connection');
    }
}
