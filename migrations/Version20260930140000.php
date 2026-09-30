<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Client Profile: four phone numbers, an address book and notes.
 *
 *   - home / work / cell number become phone 1 / 2 / 3 (phone 4 starts empty);
 *   - the one free-text address becomes the first entry of maxeme_client_address;
 *   - the one free-text note becomes the first maxeme_client_note, with no author (nobody
 *     recorded one) and the client's last-updated time as its timestamp.
 *
 * Nothing is lost, so the old columns are dropped; down() puts them back from the new tables
 * (the first address, the newest note).
 */
final class Version20260930140000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Client phone 1-4, maxeme_client_address and maxeme_client_note';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('maxeme_client_address')) {
            $this->addSql('CREATE TABLE maxeme_client_address (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, label VARCHAR(80) DEFAULT NULL, first_name VARCHAR(120) DEFAULT NULL, last_name VARCHAR(120) DEFAULT NULL, company_name VARCHAR(255) DEFAULT NULL, email_primary VARCHAR(255) DEFAULT NULL, email_secondary VARCHAR(255) DEFAULT NULL, phone VARCHAR(40) DEFAULT NULL, fax VARCHAR(40) DEFAULT NULL, address_line1 VARCHAR(255) DEFAULT NULL, address_line2 VARCHAR(255) DEFAULT NULL, city VARCHAR(120) DEFAULT NULL, province VARCHAR(120) DEFAULT NULL, country VARCHAR(100) DEFAULT NULL, postal_code VARCHAR(20) DEFAULT NULL, delivery_instructions CLOB DEFAULT NULL, position INTEGER DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, client_id INTEGER NOT NULL, CONSTRAINT FK_C45B2CB719EB6921 FOREIGN KEY (client_id) REFERENCES maxeme_client (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE INDEX IDX_C45B2CB719EB6921 ON maxeme_client_address (client_id)');
            $this->addSql('CREATE INDEX idx_maxeme_client_address_client ON maxeme_client_address (client_id, position)');
        }
        if (!$this->tableExists('maxeme_client_note')) {
            $this->addSql('CREATE TABLE maxeme_client_note (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, user_name VARCHAR(255) DEFAULT NULL, text CLOB NOT NULL, created_at DATETIME NOT NULL, client_id INTEGER NOT NULL, CONSTRAINT FK_4946585D19EB6921 FOREIGN KEY (client_id) REFERENCES maxeme_client (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE INDEX idx_maxeme_client_note_client ON maxeme_client_note (client_id)');
        }

        foreach ([1, 2, 3, 4] as $n) {
            if (!$this->columnExists('maxeme_client', 'phone_' . $n)) {
                $this->addSql(sprintf('ALTER TABLE maxeme_client ADD COLUMN phone_%d VARCHAR(255) DEFAULT NULL', $n));
            }
        }

        if ($this->columnExists('maxeme_client', 'home_number')) {
            $this->addSql('UPDATE maxeme_client SET phone_1 = home_number, phone_2 = work_number, phone_3 = cell_number');
            $this->addSql("INSERT INTO maxeme_client_address (client_id, address_line1, position, created_at)
                SELECT id, TRIM(address), 0, last_updated FROM maxeme_client WHERE TRIM(COALESCE(address, '')) <> ''");
            $this->addSql("INSERT INTO maxeme_client_note (client_id, user_name, text, created_at)
                SELECT id, NULL, TRIM(note), last_updated FROM maxeme_client WHERE TRIM(COALESCE(note, '')) <> ''");
            foreach (['home_number', 'work_number', 'cell_number', 'address', 'note'] as $column) {
                $this->addSql(sprintf('ALTER TABLE maxeme_client DROP COLUMN %s', $column));
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['home_number', 'work_number', 'cell_number', 'address'] as $column) {
            $this->addSql(sprintf('ALTER TABLE maxeme_client ADD COLUMN %s VARCHAR(255) DEFAULT NULL', $column));
        }
        $this->addSql('ALTER TABLE maxeme_client ADD COLUMN note CLOB DEFAULT NULL');
        $this->addSql('UPDATE maxeme_client SET home_number = phone_1, work_number = phone_2, cell_number = phone_3');
        $this->addSql('UPDATE maxeme_client SET address = (SELECT a.address_line1 FROM maxeme_client_address a WHERE a.client_id = maxeme_client.id ORDER BY a.position, a.id LIMIT 1)');
        $this->addSql('UPDATE maxeme_client SET note = (SELECT n.text FROM maxeme_client_note n WHERE n.client_id = maxeme_client.id ORDER BY n.created_at DESC, n.id DESC LIMIT 1)');
        foreach ([1, 2, 3, 4] as $n) {
            $this->addSql(sprintf('ALTER TABLE maxeme_client DROP COLUMN phone_%d', $n));
        }
        $this->addSql('DROP TABLE maxeme_client_note');
        $this->addSql('DROP TABLE maxeme_client_address');
    }
}
