<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reminders (mockups aa322bb, 1b7f972 and the queue audit fixes of 8b30420 / 34181b0):
 * - a reminder and a queue entry each have a phone status (how the call went);
 * - a delayed reminder keeps its reason (and when) apart from its note;
 * - a queue entry keeps its own template and message (its email still reads right if the service
 *   reminder is deleted) and the subject and body of the email that went out ("View email").
 */
final class Version20261114140000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    private const COLUMNS = [
        'maxeme_reminder' => [
            'phone_status' => "VARCHAR(20) DEFAULT 'not_called' NOT NULL",
            'delay_note' => 'CLOB DEFAULT NULL',
            'delayed_at' => 'DATETIME DEFAULT NULL',
        ],
        'maxeme_service_reminder_queue' => [
            'phone_status' => "VARCHAR(20) DEFAULT 'not_called' NOT NULL",
            'template_id' => 'INTEGER DEFAULT NULL REFERENCES maxeme_service_reminder_template (id) ON DELETE SET NULL',
            'message' => 'CLOB DEFAULT NULL',
            'sent_subject' => 'VARCHAR(255) DEFAULT NULL',
            'sent_body' => 'CLOB DEFAULT NULL',
        ],
    ];

    public function getDescription(): string
    {
        return 'Reminders: phone status, delay reason; queue entry template, message and sent copy';
    }

    public function up(Schema $schema): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column => $definition) {
                if (!$this->columnExists($table, $column)) {
                    $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
                }
            }
        }
        if (!$this->indexExists('IDX_MX_SRQ_TEMPLATE')) {
            $this->addSql('CREATE INDEX IDX_MX_SRQ_TEMPLATE ON maxeme_service_reminder_queue (template_id)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_MX_SRQ_TEMPLATE');
        foreach (self::COLUMNS as $table => $columns) {
            foreach (array_keys($columns) as $column) {
                $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN %s', $table, $column));
            }
        }
    }
}
