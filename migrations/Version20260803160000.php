<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seed the tech_support_email setting (issue #351).
 *
 * The "database console switched on / opened" security alert used to be addressed to app_email,
 * falling back to sales_email — both of which are the *store's* own contact addresses. That alert is
 * not for the store: it says someone just took raw SQL access to the installation, and the people who
 * need to see it are whoever operates the software.
 *
 * Deliberately distinct from support_email, which stays what it was: the store owner's address for
 * non-sales customer questions.
 *
 * Seeded blank. AppSettings resolves a blank value from APP_SETTING_TECH_SUPPORT_EMAIL /
 * TECH_SUPPORT_EMAIL, so an operator running many installs can set it once in the environment
 * instead of per database; Admin\ConfigController::alert() still falls back to app_email when
 * neither is set, so an installation that has not configured it does not silently lose the alert.
 */
final class Version20260803160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the tech_support_email app setting (recipient of the database console security alert).';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('app_setting')) {
            return;
        }

        $this->addSql(
            "INSERT INTO app_setting (setting_key, name, setting_value, description, created_at, updated_at)
             SELECT 'tech_support_email', 'Tech Support Email', NULL, :description, CURRENT_TIMESTAMP, NULL
             WHERE NOT EXISTS (SELECT 1 FROM app_setting WHERE setting_key = 'tech_support_email')",
            [
                'description' => 'Where system notifications about this installation go — currently the database console '
                    . 'security alert. This is the team that runs the software, not the store: customer-facing support '
                    . 'is Support Email.',
            ],
        );
    }

    public function down(Schema $schema): void
    {
        // No-op, in line with the other settings migrations: dropping a row an admin may have
        // filled in is not worth being able to undo.
        $this->addSql('SELECT 1');
    }
}
