<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Maxeme Auto, phase 1: admin_user.username (a second login name beside the email, unique when set)
 * and admin_user.legacy_salt (the per-user salt of a password hash imported from the legacy app).
 *
 * ADD-only: every existing row reads NULL for both, which is correct. A unique index on a
 * nullable column allows any number of NULLs on SQLite.
 */
final class Version20260929100000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Maxeme: add admin_user.username and admin_user.legacy_salt';
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('admin_user', 'username')) {
            $this->addSql('ALTER TABLE admin_user ADD COLUMN username VARCHAR(180) DEFAULT NULL');
        }

        if (!$this->columnExists('admin_user', 'legacy_salt')) {
            $this->addSql('ALTER TABLE admin_user ADD COLUMN legacy_salt VARCHAR(255) DEFAULT NULL');
        }

        if (!$this->indexExists('uniq_admin_user_username')) {
            $this->addSql('CREATE UNIQUE INDEX uniq_admin_user_username ON admin_user (username)');
        }
    }

    /** The columns stay: dropping one needs SQLite's table-rebuild path, for columns that are harmless NULL. */
    public function down(Schema $schema): void
    {
        if ($this->indexExists('uniq_admin_user_username')) {
            $this->addSql('DROP INDEX uniq_admin_user_username');
        }
    }
}
