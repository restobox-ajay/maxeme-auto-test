<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Repair orders: the main record of a visit, with its services (and their lines), custom fees and
 * discounts, and notes. Appointments get their repair order and a promised time; invoices their
 * repair order. Existing invoices and appointments are converted by app:maxeme:convert-repair-orders.
 */
final class Version20261002140000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Repair orders, their services, lines, charges and notes';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('maxeme_repair_order')) {
            $this->addSql('CREATE TABLE maxeme_repair_order (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                client_id INTEGER DEFAULT NULL,
                vehicle_id INTEGER DEFAULT NULL,
                advisor_id INTEGER DEFAULT NULL,
                master_technician_id INTEGER DEFAULT NULL,
                status VARCHAR(24) NOT NULL,
                mileage VARCHAR(20) DEFAULT NULL,
                tag_key VARCHAR(60) DEFAULT NULL,
                name VARCHAR(255) DEFAULT NULL,
                concern CLOB DEFAULT NULL,
                gst_rate INTEGER DEFAULT 0 NOT NULL,
                pst_rate INTEGER DEFAULT 0 NOT NULL,
                subtotal NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL,
                charge_total NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL,
                gst NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL,
                pst NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL,
                total NUMERIC(10, 2) DEFAULT \'0.00\' NOT NULL,
                created_on DATETIME NOT NULL,
                last_updated DATETIME NOT NULL,
                CONSTRAINT FK_MX_RO_CLIENT FOREIGN KEY (client_id) REFERENCES maxeme_client (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_MX_RO_VEHICLE FOREIGN KEY (vehicle_id) REFERENCES maxeme_vehicle (id) NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_MX_RO_ADVISOR FOREIGN KEY (advisor_id) REFERENCES admin_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_MX_RO_TECHNICIAN FOREIGN KEY (master_technician_id) REFERENCES maxeme_technician (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            )');
            $this->addSql('CREATE INDEX IDX_MX_RO_CLIENT ON maxeme_repair_order (client_id)');
            $this->addSql('CREATE INDEX IDX_MX_RO_VEHICLE ON maxeme_repair_order (vehicle_id)');
            $this->addSql('CREATE INDEX IDX_MX_RO_ADVISOR ON maxeme_repair_order (advisor_id)');
            $this->addSql('CREATE INDEX IDX_MX_RO_TECHNICIAN ON maxeme_repair_order (master_technician_id)');
            $this->addSql('CREATE INDEX idx_maxeme_repair_order_status ON maxeme_repair_order (status)');
        }

        if (!$this->tableExists('maxeme_repair_order_job')) {
            $this->addSql('CREATE TABLE maxeme_repair_order_job (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                repair_order_id INTEGER NOT NULL,
                service_id INTEGER DEFAULT NULL,
                name VARCHAR(255) NOT NULL,
                price NUMERIC(10, 2) NOT NULL,
                position INTEGER DEFAULT 0 NOT NULL,
                CONSTRAINT FK_MX_RO_JOB_RO FOREIGN KEY (repair_order_id) REFERENCES maxeme_repair_order (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_MX_RO_JOB_SERVICE FOREIGN KEY (service_id) REFERENCES maxeme_service (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            )');
            $this->addSql('CREATE INDEX IDX_MX_RO_JOB_RO ON maxeme_repair_order_job (repair_order_id)');
            $this->addSql('CREATE INDEX IDX_MX_RO_JOB_SERVICE ON maxeme_repair_order_job (service_id)');
        }

        if (!$this->tableExists('maxeme_repair_order_job_line')) {
            $this->addSql('CREATE TABLE maxeme_repair_order_job_line (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                job_id INTEGER NOT NULL,
                repair_order_id INTEGER NOT NULL,
                labour_id INTEGER DEFAULT NULL,
                product_id INTEGER DEFAULT NULL,
                govt_fee_id INTEGER DEFAULT NULL,
                type VARCHAR(16) NOT NULL,
                quantity NUMERIC(10, 2) NOT NULL,
                unit_price NUMERIC(10, 2) NOT NULL,
                charge_through BOOLEAN DEFAULT 0 NOT NULL,
                position INTEGER DEFAULT 0 NOT NULL,
                item_name VARCHAR(255) DEFAULT NULL,
                CONSTRAINT FK_MX_RO_LINE_JOB FOREIGN KEY (job_id) REFERENCES maxeme_repair_order_job (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_MX_RO_LINE_RO FOREIGN KEY (repair_order_id) REFERENCES maxeme_repair_order (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_MX_RO_LINE_LABOUR FOREIGN KEY (labour_id) REFERENCES maxeme_labour (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_MX_RO_LINE_PRODUCT FOREIGN KEY (product_id) REFERENCES product_core (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE,
                CONSTRAINT FK_MX_RO_LINE_GOVT_FEE FOREIGN KEY (govt_fee_id) REFERENCES maxeme_govt_fee (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            )');
            $this->addSql('CREATE INDEX IDX_MX_RO_LINE_JOB ON maxeme_repair_order_job_line (job_id)');
            $this->addSql('CREATE INDEX IDX_MX_RO_LINE_RO ON maxeme_repair_order_job_line (repair_order_id)');
            $this->addSql('CREATE INDEX IDX_MX_RO_LINE_LABOUR ON maxeme_repair_order_job_line (labour_id)');
            $this->addSql('CREATE INDEX IDX_MX_RO_LINE_PRODUCT ON maxeme_repair_order_job_line (product_id)');
            $this->addSql('CREATE INDEX IDX_MX_RO_LINE_GOVT_FEE ON maxeme_repair_order_job_line (govt_fee_id)');
        }

        if (!$this->tableExists('maxeme_repair_order_charge')) {
            $this->addSql('CREATE TABLE maxeme_repair_order_charge (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                repair_order_id INTEGER NOT NULL,
                kind VARCHAR(10) NOT NULL,
                label VARCHAR(120) NOT NULL,
                amount NUMERIC(10, 2) NOT NULL,
                position INTEGER DEFAULT 0 NOT NULL,
                CONSTRAINT FK_MX_RO_CHARGE_RO FOREIGN KEY (repair_order_id) REFERENCES maxeme_repair_order (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )');
            $this->addSql('CREATE INDEX IDX_MX_RO_CHARGE_RO ON maxeme_repair_order_charge (repair_order_id)');
        }

        if (!$this->tableExists('maxeme_repair_order_note')) {
            $this->addSql('CREATE TABLE maxeme_repair_order_note (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                repair_order_id INTEGER NOT NULL,
                user_name VARCHAR(255) DEFAULT NULL,
                text CLOB NOT NULL,
                created_at DATETIME NOT NULL,
                CONSTRAINT FK_MX_RO_NOTE_RO FOREIGN KEY (repair_order_id) REFERENCES maxeme_repair_order (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )');
            $this->addSql('CREATE INDEX idx_maxeme_repair_order_note_ro ON maxeme_repair_order_note (repair_order_id)');
        }

        if (!$this->columnExists('maxeme_appointment', 'repair_order_id')) {
            $this->addSql('ALTER TABLE maxeme_appointment ADD COLUMN repair_order_id INTEGER DEFAULT NULL REFERENCES maxeme_repair_order (id) ON DELETE SET NULL');
            $this->addSql('CREATE INDEX IDX_MX_APPOINTMENT_RO ON maxeme_appointment (repair_order_id)');
        }
        if (!$this->columnExists('maxeme_appointment', 'promised_at')) {
            $this->addSql('ALTER TABLE maxeme_appointment ADD COLUMN promised_at DATETIME DEFAULT NULL');
        }
        if (!$this->columnExists('maxeme_invoice', 'repair_order_id')) {
            $this->addSql('ALTER TABLE maxeme_invoice ADD COLUMN repair_order_id INTEGER DEFAULT NULL REFERENCES maxeme_repair_order (id) ON DELETE SET NULL');
            $this->addSql('CREATE INDEX IDX_MX_INVOICE_RO ON maxeme_invoice (repair_order_id)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_MX_INVOICE_RO');
        $this->addSql('ALTER TABLE maxeme_invoice DROP COLUMN repair_order_id');
        $this->addSql('DROP INDEX IDX_MX_APPOINTMENT_RO');
        $this->addSql('ALTER TABLE maxeme_appointment DROP COLUMN repair_order_id');
        $this->addSql('ALTER TABLE maxeme_appointment DROP COLUMN promised_at');
        $this->addSql('DROP TABLE maxeme_repair_order_note');
        $this->addSql('DROP TABLE maxeme_repair_order_charge');
        $this->addSql('DROP TABLE maxeme_repair_order_job_line');
        $this->addSql('DROP TABLE maxeme_repair_order_job');
        $this->addSql('DROP TABLE maxeme_repair_order');
    }
}
