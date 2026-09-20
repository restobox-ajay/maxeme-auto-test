<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Record who an error happened to and where they came from.
 *
 * The IP was already captured, but only inside the JSON `message` blob, which makes it
 * unsearchable and awkward to show as its own column. This adds it (and the actor identity,
 * which wasn't captured at all) as dedicated columns, matching audit_log's ip_address (see
 * Version20260731130000).
 *
 * All columns are nullable: most errors happen to anonymous storefront visitors with no
 * identity to record, and plenty have no request/referrer behind them at all (console
 * commands, queued jobs).
 *
 * down() does not DROP COLUMN, in line with every other additive migration in this chain (see
 * Version20260803190000, Version20260805003000, Version20260806010000): that needs SQLite 3.35+
 * and a full table rebuild below that, and production is still on 3.26.0.
 */
final class Version20260806130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add error_log.ip_address, user_type, user_id, user_email, referrer.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('error_log')) {
            return;
        }

        $table = $schema->getTable('error_log');

        if (!$table->hasColumn('ip_address')) {
            $this->addSql('ALTER TABLE error_log ADD COLUMN ip_address VARCHAR(45) DEFAULT NULL');
        }

        if (!$table->hasColumn('user_type')) {
            $this->addSql("ALTER TABLE error_log ADD COLUMN user_type VARCHAR(16) DEFAULT NULL");
        }

        if (!$table->hasColumn('user_id')) {
            $this->addSql('ALTER TABLE error_log ADD COLUMN user_id INTEGER DEFAULT NULL');
        }

        if (!$table->hasColumn('user_email')) {
            $this->addSql('ALTER TABLE error_log ADD COLUMN user_email VARCHAR(255) DEFAULT NULL');
        }

        if (!$table->hasColumn('referrer')) {
            $this->addSql('ALTER TABLE error_log ADD COLUMN referrer VARCHAR(2048) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('error_log')) {
            return;
        }

        $table = $schema->getTable('error_log');

        // Dropping these columns needs SQLite 3.35+ and a full table rebuild below that (production
        // is on 3.26.0) — a lot of machinery, and ErrorLogSubscriber still writes these columns on
        // every request, so a dropped column would break every exception handled while a rollback is
        // in effect. Clearing the values back to NULL — what every row had before this migration
        // ran, since all five columns are nullable with no default — is the closest a rollback can
        // get without the rebuild, and matches the down() idiom used throughout this chain.
        foreach (['ip_address', 'user_type', 'user_id', 'user_email', 'referrer'] as $column) {
            if ($table->hasColumn($column)) {
                $this->addSql("UPDATE error_log SET {$column} = NULL");
            }
        }
    }
}
