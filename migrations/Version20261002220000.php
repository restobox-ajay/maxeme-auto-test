<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Repair orders hold stock: the reservation ledger (maxeme_repair_order_inventory_reservation), and
 * maxeme_repair_order.holds_stock, off for every repair order that exists now (they were converted
 * from legacy invoices, whose parts already left the stock count) and on for new ones.
 */
final class Version20261002220000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Repair orders: stock holds';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('maxeme_repair_order', 'holds_stock')) {
            $this->addSql('ALTER TABLE maxeme_repair_order ADD COLUMN holds_stock BOOLEAN DEFAULT 0 NOT NULL');
        }
        if (!$this->tableExists('maxeme_repair_order_inventory_reservation')) {
            $this->addSql('CREATE TABLE maxeme_repair_order_inventory_reservation (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                repair_order_id INTEGER NOT NULL,
                product_id INTEGER NOT NULL,
                warehouse_id INTEGER NOT NULL,
                bucket VARCHAR(20) NOT NULL,
                quantity NUMERIC(14, 4) NOT NULL,
                synced_quantity NUMERIC(14, 4) NOT NULL,
                lot_id INTEGER DEFAULT NULL,
                serial VARCHAR(120) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                CONSTRAINT FK_MX_RO_RES_RO FOREIGN KEY (repair_order_id) REFERENCES maxeme_repair_order (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_MX_RO_RES_PRODUCT FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_MX_RO_RES_WAREHOUSE FOREIGN KEY (warehouse_id) REFERENCES warehouse (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )');
            $this->addSql('CREATE UNIQUE INDEX uniq_mx_ro_reservation ON maxeme_repair_order_inventory_reservation (repair_order_id, product_id, warehouse_id)');
            $this->addSql('CREATE INDEX IDX_MX_RO_RES_PRODUCT ON maxeme_repair_order_inventory_reservation (product_id)');
            $this->addSql('CREATE INDEX IDX_MX_RO_RES_WAREHOUSE ON maxeme_repair_order_inventory_reservation (warehouse_id)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE maxeme_repair_order_inventory_reservation');
        $this->addSql('ALTER TABLE maxeme_repair_order DROP COLUMN holds_stock');
    }
}
