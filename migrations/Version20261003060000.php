<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The service reminder queue (maxeme_service_reminder_queue): one row per reminder email a completed
 * repair order queued, skipped, sent, booked or resolved.
 */
final class Version20261003060000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Service reminder queue';
    }

    public function up(Schema $schema): void
    {
        if ($this->tableExists('maxeme_service_reminder_queue')) {
            return;
        }
        $this->addSql('CREATE TABLE maxeme_service_reminder_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
            repair_order_id INTEGER DEFAULT NULL,
            client_id INTEGER NOT NULL,
            vehicle_id INTEGER NOT NULL,
            rule_id INTEGER DEFAULT NULL,
            service_id INTEGER DEFAULT NULL,
            status VARCHAR(20) NOT NULL,
            service_name VARCHAR(255) NOT NULL,
            reminder_days INTEGER NOT NULL,
            queued_at DATETIME NOT NULL,
            due_at DATETIME NOT NULL,
            sent_at DATETIME DEFAULT NULL,
            email VARCHAR(180) DEFAULT NULL,
            error CLOB DEFAULT NULL,
            last_updated DATETIME NOT NULL,
            CONSTRAINT FK_MX_REMINDER_QUEUE_RO FOREIGN KEY (repair_order_id) REFERENCES maxeme_repair_order (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE,
            CONSTRAINT FK_MX_REMINDER_QUEUE_CLIENT FOREIGN KEY (client_id) REFERENCES maxeme_client (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
            CONSTRAINT FK_MX_REMINDER_QUEUE_VEHICLE FOREIGN KEY (vehicle_id) REFERENCES maxeme_vehicle (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE,
            CONSTRAINT FK_MX_REMINDER_QUEUE_RULE FOREIGN KEY (rule_id) REFERENCES maxeme_service_reminder (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE,
            CONSTRAINT FK_MX_REMINDER_QUEUE_SERVICE FOREIGN KEY (service_id) REFERENCES maxeme_service (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
        )');
        $this->addSql('CREATE INDEX idx_mx_reminder_queue_status_due ON maxeme_service_reminder_queue (status, due_at)');
        $this->addSql('CREATE INDEX idx_mx_reminder_queue_client_vehicle ON maxeme_service_reminder_queue (client_id, vehicle_id)');
        $this->addSql('CREATE INDEX IDX_MX_REMINDER_QUEUE_RO ON maxeme_service_reminder_queue (repair_order_id)');
        $this->addSql('CREATE INDEX IDX_MX_REMINDER_QUEUE_RULE ON maxeme_service_reminder_queue (rule_id)');
        $this->addSql('CREATE INDEX IDX_MX_REMINDER_QUEUE_SERVICE ON maxeme_service_reminder_queue (service_id)');
        $this->addSql('CREATE INDEX IDX_MX_REMINDER_QUEUE_VEHICLE ON maxeme_service_reminder_queue (vehicle_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE maxeme_service_reminder_queue');
    }
}
