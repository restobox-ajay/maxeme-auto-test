<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add fulfillment_region.default_for_new_company.
 *
 * The flag says "tick this region on the checklist of every new company" — it is read by
 * /admin/company/create when it builds that checklist, and by customer registration when it
 * attaches regions to the company it just created. It is a convenience that saves the admin
 * forgetting a region, not an automation: the admin still confirms the checklist before saving,
 * and the price list those rows get is the existing company_registration_default_price_list_id
 * setting, so there is nothing per-region to store beyond the boolean.
 *
 * Deliberately NOT the same thing as the "default" the regions list used to show, which was the
 * lowest-id region and only ever meant "cannot be deleted"; that stays where it is, computed in
 * ConfigController, and is unaffected by this column.
 *
 * ALTER TABLE ... ADD COLUMN with a literal default is fine on the SQLite dev/prod links (see
 * Version20260803170000); the NOT NULL default is what backfills the existing rows to off.
 */
final class Version20260803190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add fulfillment_region.default_for_new_company.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('fulfillment_region')) {
            return;
        }

        if (!$schema->getTable('fulfillment_region')->hasColumn('default_for_new_company')) {
            $this->addSql('ALTER TABLE fulfillment_region ADD COLUMN default_for_new_company BOOLEAN DEFAULT 0 NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        // Same call as Version20260803170000's down(): dropping the column needs SQLite 3.35+ and a
        // table rebuild below that, which is a lot of machinery to undo one flag. Clearing it
        // restores the pre-migration behaviour — no region defaults itself on — which is the point.
        $this->addSql('UPDATE fulfillment_region SET default_for_new_company = 0');
    }
}
