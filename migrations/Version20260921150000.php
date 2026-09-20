<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds `woo_product_id`/`woo_variation_id` to `woocommerce_product_mapping` (#739).
 *
 * The mapping row already carried `woo_sku` — Woo's SKU when sent, or a synthetic key derived from
 * `product_id`/`variation_id` when it wasn't (see WooCommerceProductResolver) — which is enough to
 * resolve an INBOUND order line to an internal product. It is not enough for the OUTBOUND direction
 * (the Push Queue/Bulk Resync work still to come): pushing a stock update to Woo's REST API calls
 * `PUT /products/{id}` for a simple product or `PUT /products/{parent}/variations/{variation}` for
 * a variation, and neither endpoint can be built from a synthetic key alone. These two columns are
 * Woo's own real numeric identifiers, captured at resolution time and needed for that push.
 *
 * `woo_product_id` defaults to 0 for existing rows written before this migration (there is no real
 * production traffic yet — this whole connector is still mid-build) rather than being backfilled;
 * a 0 simply reads as "not yet known" to anything that pushes stock, the same way an absent value
 * would.
 */
final class Version20260921150000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Add woo_product_id/woo_variation_id to woocommerce_product_mapping (outbound stock push target).';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('woocommerce_product_mapping')) {
            return;
        }

        if (!$this->columnExists('woocommerce_product_mapping', 'woo_product_id')) {
            $this->addSql('ALTER TABLE woocommerce_product_mapping ADD COLUMN woo_product_id INTEGER DEFAULT 0 NOT NULL');
        }
        if (!$this->columnExists('woocommerce_product_mapping', 'woo_variation_id')) {
            $this->addSql('ALTER TABLE woocommerce_product_mapping ADD COLUMN woo_variation_id INTEGER DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        // No-op: SQLite's ALTER TABLE DROP COLUMN support is version-dependent and this migration's
        // own convention elsewhere (see the CREATE-TABLE-only migrations alongside it) is to accept
        // an added column as a one-way door rather than rebuild the table to remove it.
        $this->addSql('SELECT 1');
    }
}
