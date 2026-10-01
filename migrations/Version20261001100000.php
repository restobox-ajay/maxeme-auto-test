<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Service set-up: service categories (a tree with a calendar colour; services pick one), service
 * reminders and their email templates, labour rates and government fees. ADD-only; one starter
 * reminder template is added so the Template list is not empty.
 */
final class Version20261001100000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Service categories, service reminders + templates, labour, government fees';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('maxeme_service_category')) {
            $this->addSql("CREATE TABLE maxeme_service_category (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(160) NOT NULL, colour VARCHAR(7) DEFAULT NULL, status VARCHAR(16) DEFAULT 'Visible' NOT NULL, created_at DATETIME NOT NULL, parent_id INTEGER DEFAULT NULL, CONSTRAINT FK_72D3DAB9727ACA70 FOREIGN KEY (parent_id) REFERENCES maxeme_service_category (id) NOT DEFERRABLE INITIALLY IMMEDIATE)");
            $this->addSql('CREATE INDEX IDX_72D3DAB9727ACA70 ON maxeme_service_category (parent_id)');
        }
        if (!$this->columnExists('maxeme_service', 'category_id')) {
            $this->addSql('ALTER TABLE maxeme_service ADD COLUMN category_id INTEGER DEFAULT NULL REFERENCES maxeme_service_category (id)');
            $this->addSql('CREATE INDEX IDX_F826A7F312469DE2 ON maxeme_service (category_id)');
        }

        if (!$this->tableExists('maxeme_service_reminder_template')) {
            $this->addSql('CREATE TABLE maxeme_service_reminder_template (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, name VARCHAR(120) NOT NULL, subject VARCHAR(255) NOT NULL, body CLOB NOT NULL, last_updated DATETIME NOT NULL)');
            $this->addSql('INSERT INTO maxeme_service_reminder_template (name, subject, body, last_updated) VALUES (?, ?, ?, CURRENT_TIMESTAMP)', [
                'Friendly reminder',
                'Time for your {{service}}, {{client name}}',
                <<<'HTML'
                    <p>Hi {{client name}},</p>
                    <p>This is a friendly reminder from Maxeme Automotive: your {{car model}} is due for its {{service}}.</p>
                    <p>{{message}}</p>
                    <p>
                        Call us: <a href="tel:{{shop phone}}">{{shop phone}}</a><br>
                        Or book online: <a href="{{booking link}}">Book an appointment</a>
                    </p>
                    <p>Thank you,<br>Maxeme Automotive Service</p>
                    HTML,
            ]);
        }
        if (!$this->tableExists('maxeme_service_reminder')) {
            $this->addSql('CREATE TABLE maxeme_service_reminder (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, reminder_days INTEGER NOT NULL, message CLOB DEFAULT NULL, emails_sent INTEGER DEFAULT 0 NOT NULL, last_updated DATETIME NOT NULL, service_id INTEGER NOT NULL, template_id INTEGER DEFAULT NULL, CONSTRAINT FK_34A88C38ED5CA9E6 FOREIGN KEY (service_id) REFERENCES maxeme_service (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE, CONSTRAINT FK_34A88C385DA0FB8 FOREIGN KEY (template_id) REFERENCES maxeme_service_reminder_template (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE)');
            $this->addSql('CREATE INDEX IDX_34A88C385DA0FB8 ON maxeme_service_reminder (template_id)');
            $this->addSql('CREATE UNIQUE INDEX uniq_maxeme_service_reminder_service ON maxeme_service_reminder (service_id)');
        }

        if (!$this->tableExists('maxeme_labour')) {
            $this->addSql("CREATE TABLE maxeme_labour (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, code VARCHAR(40) NOT NULL, name VARCHAR(255) NOT NULL, price NUMERIC(10, 2) NOT NULL, tax_class VARCHAR(16) DEFAULT 'gst_pst' NOT NULL, active BOOLEAN DEFAULT 1 NOT NULL, last_updated DATETIME NOT NULL, unit VARCHAR(8) DEFAULT 'hour' NOT NULL, sublet BOOLEAN DEFAULT 0 NOT NULL)");
            $this->addSql('CREATE UNIQUE INDEX uniq_maxeme_labour_code ON maxeme_labour (code)');
        }
        if (!$this->tableExists('maxeme_govt_fee')) {
            $this->addSql("CREATE TABLE maxeme_govt_fee (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, code VARCHAR(40) NOT NULL, name VARCHAR(255) NOT NULL, price NUMERIC(10, 2) NOT NULL, tax_class VARCHAR(16) DEFAULT 'gst_pst' NOT NULL, active BOOLEAN DEFAULT 1 NOT NULL, last_updated DATETIME NOT NULL, description CLOB DEFAULT NULL)");
            $this->addSql('CREATE UNIQUE INDEX uniq_maxeme_govt_fee_code ON maxeme_govt_fee (code)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE maxeme_govt_fee');
        $this->addSql('DROP TABLE maxeme_labour');
        $this->addSql('DROP TABLE maxeme_service_reminder');
        $this->addSql('DROP TABLE maxeme_service_reminder_template');
        $this->addSql('DROP INDEX IF EXISTS IDX_F826A7F312469DE2');
        $this->addSql('ALTER TABLE maxeme_service DROP COLUMN category_id');
        $this->addSql('DROP TABLE maxeme_service_category');
    }
}
