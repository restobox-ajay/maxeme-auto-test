<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Entity\AppSetting;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mark tech_support_email as a Tech Support setting (issue #351).
 *
 * Version20260803160000 seeded the row, but /admin/settings showed it to the store owner like any
 * other key — and let them edit it. AppSettings gives a non-blank DB value precedence over the env
 * fallback, so a store admin typing their own address in there quietly redirected the "database
 * console opened" alert to themselves: an alert that names the admin who took raw SQL access, their
 * IP and the time, and is confidential to whoever operates the installation.
 *
 * `visibility` says which of the two audiences owns a row: 'store' for everything the store owner
 * configures, 'tech_support' for the operator's own configuration. It is a column rather than a key
 * list in ConfigController because a list has to be re-applied by every screen that reads the table;
 * the column travels with the row, so the settings query filters once and any screen that forgets
 * the filter shows nothing rather than everything.
 *
 * Existing rows are backfilled to 'store' — that is what they all were — and only tech_support_email
 * moves. Nothing about the seeding migration's semantics changes; a row it already inserted is
 * simply relabelled here.
 */
final class Version20260803170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add app_setting.visibility and mark tech_support_email as Tech Support only.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('app_setting')) {
            return;
        }

        if (!$schema->getTable('app_setting')->hasColumn('visibility')) {
            // ALTER TABLE ... ADD COLUMN with a literal default is fine on the SQLite dev/prod links
            // (see Version20260802100000); the NOT NULL default is what backfills the existing rows.
            $this->addSql(sprintf(
                "ALTER TABLE app_setting ADD COLUMN visibility VARCHAR(20) NOT NULL DEFAULT '%s'",
                AppSetting::VISIBILITY_STORE,
            ));
        }
    }

    /**
     * Runs after the ALTER so the column exists to be written. Both statements are idempotent: the
     * first only touches rows the ALTER's default could not reach (a database where the column was
     * added by some other route), the second is a straight relabel.
     */
    public function postUp(Schema $schema): void
    {
        if (!$schema->hasTable('app_setting')) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE app_setting SET visibility = ? WHERE visibility IS NULL OR visibility = \'\'',
            [AppSetting::VISIBILITY_STORE],
        );

        $this->connection->executeStatement(
            'UPDATE app_setting SET visibility = ? WHERE setting_key = ?',
            [AppSetting::VISIBILITY_TECH_SUPPORT, 'tech_support_email'],
        );
    }

    public function down(Schema $schema): void
    {
        // Dropping the column needs SQLite 3.35+ and a table rebuild below that, which is a lot of
        // machinery to undo one label. Putting every row back to 'store' restores the pre-migration
        // behaviour — the whole table visible to the store owner — which is what down() is for.
        $this->addSql(sprintf(
            "UPDATE app_setting SET visibility = '%s'",
            AppSetting::VISIBILITY_STORE,
        ));
    }
}
