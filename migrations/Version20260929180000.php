<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Maxeme Auto, phase 5: invoices, their service and part lines, and reminders
 * (App\Maxeme\Entity\Invoice, InvoiceServiceLine, InvoicePartLine, Reminder). New tables only;
 * `app:maxeme:import-legacy --only=invoices,invoice-services,invoice-parts,reminders` fills them.
 *
 * maxeme_inventory_history.invoice_id keeps no foreign key: adding one to an existing SQLite table
 * needs a table rebuild, and imported history can name invoices from before this app.
 */
final class Version20260929180000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    private const TABLES = [
        'maxeme_invoice' => [
            'CREATE TABLE maxeme_invoice (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, invoice_key VARCHAR(40) NOT NULL, client_first_name VARCHAR(255) DEFAULT NULL, client_last_name VARCHAR(255) DEFAULT NULL, client_preferred_name VARCHAR(255) DEFAULT NULL, client_address VARCHAR(255) DEFAULT NULL, client_home_number VARCHAR(255) DEFAULT NULL, client_cell_number VARCHAR(255) DEFAULT NULL, client_note VARCHAR(255) DEFAULT NULL, vehicle_year VARCHAR(255) DEFAULT NULL, vehicle_manufacturer VARCHAR(255) DEFAULT NULL, vehicle_model VARCHAR(255) DEFAULT NULL, vehicle_license VARCHAR(255) DEFAULT NULL, vehicle_vin VARCHAR(255) DEFAULT NULL, vehicle_mileage VARCHAR(255) DEFAULT NULL, note VARCHAR(255) DEFAULT NULL, recommendations CLOB DEFAULT NULL, status VARCHAR(10) NOT NULL, payment_method VARCHAR(10) DEFAULT NULL, payment_amount NUMERIC(10, 2) DEFAULT NULL, discount_amount NUMERIC(10, 2) DEFAULT 0 NOT NULL, gst_rate INTEGER DEFAULT 0 NOT NULL, pst_rate INTEGER DEFAULT 0 NOT NULL, subtotal NUMERIC(10, 2) DEFAULT 0 NOT NULL, sales_tax NUMERIC(10, 2) DEFAULT 0 NOT NULL, total_price NUMERIC(10, 2) DEFAULT 0 NOT NULL, created_on DATETIME NOT NULL, last_modified DATETIME DEFAULT NULL, appointment_id INTEGER DEFAULT NULL, client_id INTEGER DEFAULT NULL, vehicle_id INTEGER DEFAULT NULL, CONSTRAINT FK_89DE2A65E5B533F9 FOREIGN KEY (appointment_id) REFERENCES maxeme_appointment (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_89DE2A6519EB6921 FOREIGN KEY (client_id) REFERENCES maxeme_client (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_89DE2A65545317D1 FOREIGN KEY (vehicle_id) REFERENCES maxeme_vehicle (id) NOT DEFERRABLE INITIALLY IMMEDIATE)',
            'CREATE UNIQUE INDEX UNIQ_89DE2A65E5B533F9 ON maxeme_invoice (appointment_id)',
            'CREATE INDEX IDX_89DE2A6519EB6921 ON maxeme_invoice (client_id)',
            'CREATE INDEX IDX_89DE2A65545317D1 ON maxeme_invoice (vehicle_id)',
            'CREATE INDEX idx_maxeme_invoice_last_modified ON maxeme_invoice (last_modified)',
            'CREATE UNIQUE INDEX uniq_maxeme_invoice_key ON maxeme_invoice (invoice_key)',
        ],
        'maxeme_invoice_service' => [
            'CREATE TABLE maxeme_invoice_service (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, created_on DATETIME NOT NULL, name VARCHAR(255) DEFAULT NULL, quantity INTEGER DEFAULT 1 NOT NULL, sale_price NUMERIC(10, 2) DEFAULT NULL, invoice_id INTEGER NOT NULL, service_id INTEGER DEFAULT NULL, CONSTRAINT FK_F2D11C1E2989F1FD FOREIGN KEY (invoice_id) REFERENCES maxeme_invoice (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_F2D11C1EED5CA9E6 FOREIGN KEY (service_id) REFERENCES maxeme_service (id) NOT DEFERRABLE INITIALLY IMMEDIATE)',
            'CREATE INDEX IDX_F2D11C1E2989F1FD ON maxeme_invoice_service (invoice_id)',
            'CREATE INDEX IDX_F2D11C1EED5CA9E6 ON maxeme_invoice_service (service_id)',
        ],
        'maxeme_invoice_part' => [
            'CREATE TABLE maxeme_invoice_part (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, created_on DATETIME NOT NULL, name VARCHAR(255) DEFAULT NULL, quantity INTEGER DEFAULT 1 NOT NULL, unit_price NUMERIC(10, 2) DEFAULT NULL, sale_price NUMERIC(10, 2) DEFAULT NULL, invoice_id INTEGER NOT NULL, part_id INTEGER DEFAULT NULL, service_line_id INTEGER DEFAULT NULL, CONSTRAINT FK_E7218C822989F1FD FOREIGN KEY (invoice_id) REFERENCES maxeme_invoice (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_E7218C824CE34BEC FOREIGN KEY (part_id) REFERENCES maxeme_part (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_E7218C8282FF27DE FOREIGN KEY (service_line_id) REFERENCES maxeme_invoice_service (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)',
            'CREATE INDEX IDX_E7218C822989F1FD ON maxeme_invoice_part (invoice_id)',
            'CREATE INDEX IDX_E7218C824CE34BEC ON maxeme_invoice_part (part_id)',
            'CREATE INDEX IDX_E7218C8282FF27DE ON maxeme_invoice_part (service_line_id)',
        ],
        'maxeme_reminder' => [
            'CREATE TABLE maxeme_reminder (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, status VARCHAR(20) NOT NULL, note CLOB DEFAULT NULL, created_on DATETIME NOT NULL, last_modified DATETIME NOT NULL, reminder_date DATETIME NOT NULL, client_id INTEGER NOT NULL, vehicle_id INTEGER NOT NULL, invoice_id INTEGER DEFAULT NULL, CONSTRAINT FK_C47E42319EB6921 FOREIGN KEY (client_id) REFERENCES maxeme_client (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_C47E423545317D1 FOREIGN KEY (vehicle_id) REFERENCES maxeme_vehicle (id) NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_C47E4232989F1FD FOREIGN KEY (invoice_id) REFERENCES maxeme_invoice (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)',
            'CREATE INDEX IDX_C47E42319EB6921 ON maxeme_reminder (client_id)',
            'CREATE INDEX IDX_C47E423545317D1 ON maxeme_reminder (vehicle_id)',
            'CREATE INDEX IDX_C47E4232989F1FD ON maxeme_reminder (invoice_id)',
        ],
    ];

    public function getDescription(): string
    {
        return 'Maxeme: add invoices, invoice lines and reminders';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table => $statements) {
            if (!$this->tableExists($table)) {
                array_map($this->addSql(...), $statements);
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach (array_reverse(array_keys(self::TABLES)) as $table) {
            $this->addSql(sprintf('DROP TABLE IF EXISTS %s', $table));
        }
    }
}
