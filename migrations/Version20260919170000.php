<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The buy-side mirror of `sales_order_address`/`estimate_address`: a purchase document's own
 * frozen vendor address, structured field by field rather than one string blob — full parity with
 * the sell side's address-book feature (vendor address book editable and selectable, same shape,
 * `source_address_id` pointing at `vendor_address` instead of `company_address`).
 *
 * `purchase_order_address` carries two types per order (order_to / ship_from, the buy-side mirror
 * of billing/shipping); `vendor_bill_address` carries one (remit_to) — a bill has no shipping
 * concept of its own, its receiving location is the warehouse it already names.
 *
 * The existing `purchase_order.vendor_address` / `vendor_bill.remit_to_address` string columns are
 * untouched: they stay the frozen-for-print snapshot every existing screen and PDF already reads,
 * now populated FROM this structured data at issue/save time instead of read live off the vendor.
 */
final class Version20260919170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add purchase_order_address and vendor_bill_address: structured, editable/selectable vendor addresses (buy-side address-book parity).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE purchase_order_address (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, type VARCHAR(16) NOT NULL, first_name VARCHAR(120) DEFAULT NULL, last_name VARCHAR(120) DEFAULT NULL, company_name VARCHAR(255) DEFAULT NULL, email_primary VARCHAR(255) DEFAULT NULL, email_secondary VARCHAR(255) DEFAULT NULL, phone VARCHAR(40) DEFAULT NULL, fax VARCHAR(40) DEFAULT NULL, address_line1 VARCHAR(255) DEFAULT NULL, address_line2 VARCHAR(255) DEFAULT NULL, city VARCHAR(120) DEFAULT NULL, province VARCHAR(6) DEFAULT NULL, country VARCHAR(2) DEFAULT NULL, postal_code VARCHAR(20) DEFAULT NULL, delivery_instructions CLOB DEFAULT NULL, source_address_id INTEGER DEFAULT NULL, purchase_order_id INTEGER NOT NULL, CONSTRAINT FK_POA_SOURCE FOREIGN KEY (source_address_id) REFERENCES vendor_address (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_POA_ORDER FOREIGN KEY (purchase_order_id) REFERENCES purchase_order (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_POA_SOURCE ON purchase_order_address (source_address_id)');
        $this->addSql('CREATE INDEX IDX_POA_ORDER ON purchase_order_address (purchase_order_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_purchase_order_address_type ON purchase_order_address (purchase_order_id, type)');

        $this->addSql('CREATE TABLE vendor_bill_address (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, type VARCHAR(16) NOT NULL, first_name VARCHAR(120) DEFAULT NULL, last_name VARCHAR(120) DEFAULT NULL, company_name VARCHAR(255) DEFAULT NULL, email_primary VARCHAR(255) DEFAULT NULL, email_secondary VARCHAR(255) DEFAULT NULL, phone VARCHAR(40) DEFAULT NULL, fax VARCHAR(40) DEFAULT NULL, address_line1 VARCHAR(255) DEFAULT NULL, address_line2 VARCHAR(255) DEFAULT NULL, city VARCHAR(120) DEFAULT NULL, province VARCHAR(6) DEFAULT NULL, country VARCHAR(2) DEFAULT NULL, postal_code VARCHAR(20) DEFAULT NULL, delivery_instructions CLOB DEFAULT NULL, source_address_id INTEGER DEFAULT NULL, vendor_bill_id INTEGER NOT NULL, CONSTRAINT FK_VBA_SOURCE FOREIGN KEY (source_address_id) REFERENCES vendor_address (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_VBA_BILL FOREIGN KEY (vendor_bill_id) REFERENCES vendor_bill (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_VBA_SOURCE ON vendor_bill_address (source_address_id)');
        $this->addSql('CREATE INDEX IDX_VBA_BILL ON vendor_bill_address (vendor_bill_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_vendor_bill_address_type ON vendor_bill_address (vendor_bill_id, type)');

        // DebitMemo extends the same AbstractPurchaseDocument this migration's contract now requires
        // a real address implementation from — see DebitMemoAddress's own docblock.
        $this->addSql('CREATE TABLE debit_memo_address (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, type VARCHAR(16) NOT NULL, first_name VARCHAR(120) DEFAULT NULL, last_name VARCHAR(120) DEFAULT NULL, company_name VARCHAR(255) DEFAULT NULL, email_primary VARCHAR(255) DEFAULT NULL, email_secondary VARCHAR(255) DEFAULT NULL, phone VARCHAR(40) DEFAULT NULL, fax VARCHAR(40) DEFAULT NULL, address_line1 VARCHAR(255) DEFAULT NULL, address_line2 VARCHAR(255) DEFAULT NULL, city VARCHAR(120) DEFAULT NULL, province VARCHAR(6) DEFAULT NULL, country VARCHAR(2) DEFAULT NULL, postal_code VARCHAR(20) DEFAULT NULL, delivery_instructions CLOB DEFAULT NULL, source_address_id INTEGER DEFAULT NULL, debit_memo_id INTEGER NOT NULL, CONSTRAINT FK_DMA_SOURCE FOREIGN KEY (source_address_id) REFERENCES vendor_address (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_DMA_MEMO FOREIGN KEY (debit_memo_id) REFERENCES debit_memo (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_DMA_SOURCE ON debit_memo_address (source_address_id)');
        $this->addSql('CREATE INDEX IDX_DMA_MEMO ON debit_memo_address (debit_memo_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_debit_memo_address_type ON debit_memo_address (debit_memo_id, type)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE purchase_order_address');
        $this->addSql('DROP TABLE vendor_bill_address');
        $this->addSql('DROP TABLE debit_memo_address');
    }
}
