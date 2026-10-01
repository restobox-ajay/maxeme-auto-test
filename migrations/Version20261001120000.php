<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Config › Settings: tax rates (GST 5, PST 7), tax classes (E / S / G), payment types and
 * technicians.
 *
 * - Labour and government fees move from the old tax_class code to the matching tax class
 *   (gst_pst → S, gst → G, exempt → E).
 * - Invoices move from the old payment_method code to a payment type. The shop's list is added
 *   active; the legacy Cash, Visa and Master are added inactive so the invoices paid that way keep
 *   them (legacy debit and cheque become the new Debit and Cheque).
 */
final class Version20261001120000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    /** Payment type => active, in list order. */
    private const PAYMENT_TYPES = [
        'Cash 1 (NT)' => true,
        'Cash 2 (T)' => true,
        'E-transfer' => true,
        'Credit Card' => true,
        'Cheque' => true,
        'Debit' => true,
        'IOT' => true,
        'Cash' => false,
        'Visa' => false,
        'Master' => false,
    ];

    /** Old invoice payment_method => payment type. */
    private const LEGACY_PAYMENT_METHODS = [
        'cash' => 'Cash',
        'visa' => 'Visa',
        'master' => 'Master',
        'debit' => 'Debit',
        'cheque' => 'Cheque',
    ];

    /** Tax class code => [name, its taxes, the old tax_class value]. */
    private const TAX_CLASSES = [
        'E' => ['Exempt', [], 'exempt'],
        'S' => ['GST+PST', ['GST', 'PST'], 'gst_pst'],
        'G' => ['GST only', ['GST'], 'gst'],
    ];

    public function getDescription(): string
    {
        return 'Settings: tax rates, tax classes, payment types, technicians';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('maxeme_tax_rate')) {
            $this->addSql('CREATE TABLE maxeme_tax_rate (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, code VARCHAR(8) NOT NULL, name VARCHAR(60) NOT NULL, rate INTEGER DEFAULT 0 NOT NULL, last_updated DATETIME NOT NULL)');
            $this->addSql('CREATE UNIQUE INDEX uniq_maxeme_tax_rate_code ON maxeme_tax_rate (code)');
            foreach (['GST' => 5, 'PST' => 7] as $code => $rate) {
                $this->addSql('INSERT INTO maxeme_tax_rate (code, name, rate, last_updated) VALUES (?, ?, ?, CURRENT_TIMESTAMP)', [$code, $code, $rate]);
            }
        }

        if (!$this->tableExists('maxeme_tax_class')) {
            $this->addSql('CREATE TABLE maxeme_tax_class (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, code VARCHAR(8) NOT NULL, name VARCHAR(120) NOT NULL, last_updated DATETIME NOT NULL)');
            $this->addSql('CREATE UNIQUE INDEX uniq_maxeme_tax_class_code ON maxeme_tax_class (code)');
            $this->addSql('CREATE TABLE maxeme_tax_class_rate (tax_class_id INTEGER NOT NULL, tax_rate_id INTEGER NOT NULL, PRIMARY KEY (tax_class_id, tax_rate_id), CONSTRAINT FK_2B2EFFE1A94AAAE FOREIGN KEY (tax_class_id) REFERENCES maxeme_tax_class (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_2B2EFFE1FDD13F95 FOREIGN KEY (tax_rate_id) REFERENCES maxeme_tax_rate (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE INDEX IDX_2B2EFFE1A94AAAE ON maxeme_tax_class_rate (tax_class_id)');
            $this->addSql('CREATE INDEX IDX_2B2EFFE1FDD13F95 ON maxeme_tax_class_rate (tax_rate_id)');
            foreach (self::TAX_CLASSES as $code => [$name, $rates]) {
                $this->addSql('INSERT INTO maxeme_tax_class (code, name, last_updated) VALUES (?, ?, CURRENT_TIMESTAMP)', [$code, $name]);
                foreach ($rates as $rate) {
                    $this->addSql('INSERT INTO maxeme_tax_class_rate (tax_class_id, tax_rate_id) SELECT c.id, r.id FROM maxeme_tax_class c, maxeme_tax_rate r WHERE c.code = ? AND r.code = ?', [$code, $rate]);
                }
            }
        }

        foreach (['maxeme_labour' => 'IDX_834A4EDFA94AAAE', 'maxeme_govt_fee' => 'IDX_A5CDEB24A94AAAE'] as $table => $index) {
            if ($this->columnExists($table, 'tax_class_id')) {
                continue;
            }
            $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN tax_class_id INTEGER DEFAULT NULL REFERENCES maxeme_tax_class (id)', $table));
            $this->addSql(sprintf('CREATE INDEX %s ON %s (tax_class_id)', $index, $table));
            foreach (self::TAX_CLASSES as $code => [, , $old]) {
                $this->addSql(sprintf('UPDATE %s SET tax_class_id = (SELECT id FROM maxeme_tax_class WHERE code = ?) WHERE tax_class = ?', $table), [$code, $old]);
            }
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN tax_class', $table));
        }

        if (!$this->tableExists('maxeme_payment_type')) {
            $this->addSql('CREATE TABLE maxeme_payment_type (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(60) NOT NULL, position INTEGER DEFAULT 0 NOT NULL, active BOOLEAN DEFAULT 1 NOT NULL, last_updated DATETIME NOT NULL)');
            $this->addSql('CREATE UNIQUE INDEX uniq_maxeme_payment_type_name ON maxeme_payment_type (name)');
            $position = 0;
            foreach (self::PAYMENT_TYPES as $name => $active) {
                $this->addSql('INSERT INTO maxeme_payment_type (name, position, active, last_updated) VALUES (?, ?, ?, CURRENT_TIMESTAMP)', [$name, ++$position, (int) $active]);
            }
        }

        if (!$this->columnExists('maxeme_invoice', 'payment_type_id')) {
            $this->addSql('ALTER TABLE maxeme_invoice ADD COLUMN payment_type_id INTEGER DEFAULT NULL REFERENCES maxeme_payment_type (id)');
            $this->addSql('CREATE INDEX IDX_89DE2A65DC058279 ON maxeme_invoice (payment_type_id)');
            foreach (self::LEGACY_PAYMENT_METHODS as $method => $name) {
                $this->addSql('UPDATE maxeme_invoice SET payment_type_id = (SELECT id FROM maxeme_payment_type WHERE name = ?) WHERE payment_method = ?', [$name, $method]);
            }
            $this->addSql('ALTER TABLE maxeme_invoice DROP COLUMN payment_method');
        }

        if (!$this->tableExists('maxeme_technician')) {
            $this->addSql('CREATE TABLE maxeme_technician (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(120) NOT NULL, active BOOLEAN DEFAULT 1 NOT NULL, last_updated DATETIME NOT NULL)');
            $this->addSql('CREATE UNIQUE INDEX uniq_maxeme_technician_name ON maxeme_technician (name)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE maxeme_technician');

        $this->addSql('ALTER TABLE maxeme_invoice ADD COLUMN payment_method VARCHAR(10) DEFAULT NULL');
        foreach (self::LEGACY_PAYMENT_METHODS as $method => $name) {
            $this->addSql('UPDATE maxeme_invoice SET payment_method = ? WHERE payment_type_id = (SELECT id FROM maxeme_payment_type WHERE name = ?)', [$method, $name]);
        }
        $this->addSql('DROP INDEX IF EXISTS IDX_89DE2A65DC058279');
        $this->addSql('ALTER TABLE maxeme_invoice DROP COLUMN payment_type_id');
        $this->addSql('DROP TABLE maxeme_payment_type');

        foreach (['maxeme_labour' => 'IDX_834A4EDFA94AAAE', 'maxeme_govt_fee' => 'IDX_A5CDEB24A94AAAE'] as $table => $index) {
            $this->addSql(sprintf("ALTER TABLE %s ADD COLUMN tax_class VARCHAR(16) DEFAULT 'gst_pst' NOT NULL", $table));
            foreach (self::TAX_CLASSES as $code => [, , $old]) {
                $this->addSql(sprintf('UPDATE %s SET tax_class = ? WHERE tax_class_id = (SELECT id FROM maxeme_tax_class WHERE code = ?)', $table), [$old, $code]);
            }
            $this->addSql(sprintf('DROP INDEX IF EXISTS %s', $index));
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN tax_class_id', $table));
        }

        $this->addSql('DROP TABLE maxeme_tax_class_rate');
        $this->addSql('DROP TABLE maxeme_tax_class');
        $this->addSql('DROP TABLE maxeme_tax_rate');
    }
}
