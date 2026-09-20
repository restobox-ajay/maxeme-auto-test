<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `woocommerce_order_import.credit_memo_id` (#739 follow-up): a WooCommerce order that gets fully
 * refunded after it was already imported and invoiced now raises a real credit note against that
 * invoice, and this column is both the pointer to it and the idempotency guard against crediting the
 * same refund twice on a redelivered `order.updated` webhook — see
 * WooCommerceOrderImportService::handleUpdateToImportedOrder().
 *
 * Nullable and ON DELETE SET NULL, same reasoning as the three FKs the table already carries: losing
 * the credit note must never take the import row down with it.
 */
final class Version20260921220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add woocommerce_order_import.credit_memo_id (WooCommerce refund-to-credit-note link).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE woocommerce_order_import ADD COLUMN credit_memo_id INTEGER DEFAULT NULL REFERENCES credit_memo (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_woo_order_import_credit_memo ON woocommerce_order_import (credit_memo_id)');
    }

    /**
     * SQLite's ALTER TABLE cannot drop a column without a full table rebuild, and nothing in this
     * app's own migration history has ever done one for a down() — see the other hand-written
     * migrations in this directory that add nullable columns.
     */
    public function down(Schema $schema): void
    {
    }
}
