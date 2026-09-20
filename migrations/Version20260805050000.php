<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #439: AdminMenuBundle's storage for the configurable admin sidebar — hide/reorder/reparent
 * overrides for core entries (admin_menu_item_status, keyed by the entry's stable
 * App\Menu\Admin\AdminMenuCatalog key) and fully custom entries (admin_custom_menu_item).
 *
 * Both are lazily created: a core key with no admin_menu_item_status row has never been touched
 * and stays exactly where App\Menu\Admin\AdminMenuCatalog::defaultTree() puts it, so a fresh
 * install with empty tables renders the same default sidebar as before this migration ever ran.
 *
 * Same shape as frontend_menu_item_status/custom_menu_item (Version20260803150000's baseline),
 * one migration over from the sidebar this bundle configures to the one core's Frontend Menu
 * Management already configures the same way.
 */
final class Version20260805050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add admin_menu_item_status and admin_custom_menu_item for the configurable admin sidebar (#439).';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('admin_menu_item_status')) {
            $this->addSql('CREATE TABLE admin_menu_item_status (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, item_key VARCHAR(150) NOT NULL, hidden BOOLEAN DEFAULT 0 NOT NULL, sort_order INTEGER DEFAULT NULL, parent_key VARCHAR(150) DEFAULT NULL)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_ADMIN_MENU_ITEM_STATUS_KEY ON admin_menu_item_status (item_key)');
        }

        if (!$schema->hasTable('admin_custom_menu_item')) {
            $this->addSql('CREATE TABLE admin_custom_menu_item (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, item_key VARCHAR(150) NOT NULL, label VARCHAR(150) NOT NULL, url VARCHAR(2048) NOT NULL, parent_key VARCHAR(150) DEFAULT NULL, sort_order INTEGER DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL)');
            $this->addSql('CREATE UNIQUE INDEX UNIQ_ADMIN_CUSTOM_MENU_ITEM_KEY ON admin_custom_menu_item (item_key)');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('admin_custom_menu_item')) {
            $this->addSql('DROP TABLE admin_custom_menu_item');
        }

        if ($schema->hasTable('admin_menu_item_status')) {
            $this->addSql('DROP TABLE admin_menu_item_status');
        }
    }
}
