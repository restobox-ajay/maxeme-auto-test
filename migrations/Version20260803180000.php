<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The Doctrine transport's queue table (#337).
 *
 * Schema and index are Symfony's own (symfony/doctrine-messenger's Connection::configureSchema()),
 * taken from a doctrine:migrations:diff run against this table alone rather than hand-typed, so it
 * matches what the transport itself expects to query and lock rows against. auto_setup=0 on the
 * MESSENGER_TRANSPORT_DSN means the transport never tries to create this table itself — schema here
 * is migration-managed like every other table in this app.
 */
final class Version20260803180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Create messenger_messages, the Doctrine transport's queue table.";
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('messenger_messages')) {
            return;
        }

        $this->addSql('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, body CLOB NOT NULL, headers CLOB NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messenger_messages');
    }
}
