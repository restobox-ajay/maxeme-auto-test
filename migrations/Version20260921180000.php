<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `woocommerce_push_queue_item` (#739) — the event-driven Push Queue: one row per (connection,
 * product) pair waiting to have its Available-to-sell quantity pushed to WooCommerce. Distinct from
 * Bulk Resync's own scheduled full-catalogue sweep (still to come) — see
 * WooCommerceInventoryChangeSubscriber, which upserts rows here the moment a stock bucket changes,
 * and `app:woocommerce:push-queue:drain`, which pushes them on a short cron interval rather than
 * inline with whatever request dirtied them.
 *
 * One CREATE TABLE, which touches nothing that exists — no ALTER TABLE, no rebuild.
 */
final class Version20260921180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add woocommerce_push_queue_item (WooCommerce connector outbound stock push queue).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE woocommerce_push_queue_item ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, '
            . 'connection_id INTEGER NOT NULL, '
            . 'product_id INTEGER NOT NULL, '
            . 'status VARCHAR(20) NOT NULL, '
            . 'attempts INTEGER DEFAULT 0 NOT NULL, '
            . 'error_message CLOB DEFAULT NULL, '
            . 'requested_at DATETIME NOT NULL, '
            . 'pushed_at DATETIME DEFAULT NULL, '
            . 'CONSTRAINT FK_woo_push_queue_connection FOREIGN KEY (connection_id) REFERENCES woocommerce_connection (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, '
            . 'CONSTRAINT FK_woo_push_queue_product FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE'
            . ')'
        );
        $this->addSql('CREATE UNIQUE INDEX uniq_woo_push_queue_connection_product ON woocommerce_push_queue_item (connection_id, product_id)');
        $this->addSql('CREATE INDEX idx_woo_push_queue_status ON woocommerce_push_queue_item (status)');
    }

    /**
     * Reversible outright: this migration creates one table and nothing else, so dropping it loses
     * only this feature's own data.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE woocommerce_push_queue_item');
    }
}
