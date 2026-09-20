<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stage 1 of the manual stock adjustment work (#425): add product_inventory.manual_adjustment.
 *
 * This is column-only. It deliberately does not feed into
 * ProductInventory::getAvailableQuantity() or any other availability math, and it is not surfaced
 * on the product inventory table or anywhere else inventory breakdown is shown yet — that is
 * stage 2, tracked separately in #425. Signed (not clamped at 0 like the hold buckets) because an
 * admin needs to be able to adjust availability in either direction — up for stock a supplier
 * physically topped off outside the normal import, down for shrinkage/damage found on a shelf
 * count — the same reasoning FulfillmentRegion.default_for_new_company's migration
 * (Version20260803190000) used for a NOT NULL default: it backfills every existing row to 0, a
 * no-op adjustment, without touching current availability.
 */
final class Version20260805030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add product_inventory.manual_adjustment (stage 1 of #425; not yet used in availability math).';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('product_inventory')) {
            return;
        }

        if (!$schema->getTable('product_inventory')->hasColumn('manual_adjustment')) {
            // ALTER TABLE ... ADD COLUMN with a literal default is fine on the SQLite dev/prod
            // links (see Version20260803190000); the NOT NULL default is what backfills the
            // existing rows to 0.
            $this->addSql('ALTER TABLE product_inventory ADD COLUMN manual_adjustment INTEGER DEFAULT 0 NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        // Dropping the column needs SQLite 3.35+ and a table rebuild below that, which is a lot of
        // machinery to undo one number that nothing yet reads. Zeroing it restores the
        // pre-migration behaviour — no manual adjustment in play — which is what down() is for.
        $this->addSql('UPDATE product_inventory SET manual_adjustment = 0');
    }
}
