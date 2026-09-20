<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `shipment` / `shipment_line` — the physical record of goods leaving the building
 * (`docs/plans/2026-09-14-shipment-dispatch.md`).
 *
 * `shipment.company_id`, not an invoice — a shipment's lines can span more than one invoice for one
 * customer (combined shipments). `shipment_line.invoice_line_id` is what ties each line back to a
 * real invoice; it is `SET NULL` on delete rather than a hard FK failure, so losing that attribution
 * later never deletes the record that goods actually left.
 *
 * `product_id` on `shipment_line` carries no `REFERENCES` constraint, matching `goods_receipt_line`
 * (Version20260821160000-adjacent migrations) — a shipment line has to outlive the product's
 * deletion.
 */
final class Version20260920100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shipment/ShipmentLine: the physical record of goods leaving the building, against one or more invoices for one customer.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE shipment ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, '
            . 'company_id INTEGER NOT NULL, '
            . 'shipment_number VARCHAR(60) NOT NULL, '
            . 'client_operation_id VARCHAR(190) DEFAULT NULL, '
            . 'shipped_at DATETIME NOT NULL, '
            . 'shipped_by VARCHAR(190) DEFAULT NULL, '
            . 'notes CLOB DEFAULT NULL, '
            . 'voided_at DATETIME DEFAULT NULL, '
            . 'voided_by VARCHAR(190) DEFAULT NULL, '
            . 'void_reason CLOB DEFAULT NULL, '
            . 'CONSTRAINT fk_shipment_company FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE'
            . ')'
        );
        $this->addSql('CREATE UNIQUE INDEX uniq_shipment_number ON shipment (shipment_number)');
        $this->addSql('CREATE UNIQUE INDEX uniq_shipment_client_operation_id ON shipment (client_operation_id)');
        $this->addSql('CREATE INDEX idx_shipment_company ON shipment (company_id)');

        $this->addSql(
            'CREATE TABLE shipment_line ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, '
            . 'shipment_id INTEGER NOT NULL, '
            . 'invoice_line_id INTEGER DEFAULT NULL, '
            . 'product_id INTEGER DEFAULT NULL, '
            . 'name VARCHAR(255) NOT NULL, '
            . 'sku VARCHAR(120) DEFAULT NULL, '
            . 'quantity NUMERIC(14, 4) NOT NULL, '
            . 'lot_id INTEGER DEFAULT NULL REFERENCES inventory_lot (id) ON DELETE SET NULL, '
            . 'serial VARCHAR(120) DEFAULT NULL, '
            . 'movement_applied BOOLEAN NOT NULL, '
            . 'CONSTRAINT fk_shipment_line_shipment FOREIGN KEY (shipment_id) REFERENCES shipment (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, '
            . 'CONSTRAINT fk_shipment_line_invoice_line FOREIGN KEY (invoice_line_id) REFERENCES invoice_line (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE'
            . ')'
        );
        $this->addSql('CREATE INDEX idx_shipment_line_shipment ON shipment_line (shipment_id)');
        $this->addSql('CREATE INDEX idx_shipment_line_invoice_line ON shipment_line (invoice_line_id)');
        $this->addSql('CREATE INDEX idx_shipment_line_product ON shipment_line (product_id)');
        $this->addSql('CREATE INDEX idx_shipment_line_lot ON shipment_line (lot_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE shipment_line');
        $this->addSql('DROP TABLE shipment');
    }
}
