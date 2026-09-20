<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\SqliteMigrationIntrospection;
use App\Entity\AppSetting;
use App\Service\QuantityScale;
use App\Service\Uom\LineDenomination;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seed `quantity_decimal_places` — the store-wide precision every quantity is stored and shown at.
 *
 * A row on `app_setting` like `timezone`, not a schema change: the columns have been NUMERIC(14,4)
 * since #645 and stay that. The setting narrows what is WRITTEN, never what the column holds, so
 * the default is the column's own scale — the only default that cannot round away a stored figure.
 *
 * Seeded rather than left to the code default so it is editable at /admin/settings without an admin
 * having to know the key exists. Existing rows are untouched; only later writes are rounded.
 */
final class Version20260920160000 extends AbstractMigration
{
    use SqliteMigrationIntrospection;

    public function getDescription(): string
    {
        return 'Seed quantity_decimal_places (default 4) — the store-wide quantity precision.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->tableExists('app_setting')) {
            return;
        }

        // Same INSERT ... WHERE NOT EXISTS shape as the other settings migrations, so re-running
        // against a database that already has the key is a no-op rather than a duplicate row.
        $this->addSql(
            'INSERT INTO app_setting (setting_key, name, setting_value, description, created_at, updated_at, category, visibility)
             SELECT :key, :name, :value, :description, CURRENT_TIMESTAMP, NULL, :category, :visibility
             WHERE NOT EXISTS (SELECT 1 FROM app_setting WHERE setting_key = :key)',
            [
                'key' => QuantityScale::SETTING_KEY,
                'name' => 'Quantity Decimal Places',
                'value' => (string) LineDenomination::QUANTITY_SCALE,
                'description' => 'How many decimal places a quantity is kept and shown to, everywhere: order and '
                    . 'invoice lines, purchase orders, receipts, shipments and stock levels. '
                    . LineDenomination::QUANTITY_SCALE . ' is the most the database can hold, so a higher number is '
                    . 'treated as ' . LineDenomination::QUANTITY_SCALE . '. Set it to 0 for a business that only ever '
                    . 'counts whole items. Changing it affects what is entered from now on and never rewrites a '
                    . 'figure already stored. Blank or invalid falls back to ' . LineDenomination::QUANTITY_SCALE . '.',
                'category' => 'General',
                'visibility' => AppSetting::VISIBILITY_STORE,
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
