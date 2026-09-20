<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Say what `fulfillment_regions` actually is: a first-run seed, not live configuration.
 *
 * The setting looks like every other editable row on the settings screen, but every reader of it is
 * an empty-fallback. ConfigController::syncFulfillmentRegionsFromSettings() returns early as soon as
 * lockedFulfillmentRegionId() finds any row, and AbstractCustomerController/ProductController only
 * parse it when their own lookup came back empty. The `fulfillment_region` table is created the first
 * time anyone opens the Fulfillment Regions screen, so from that point on editing this value creates
 * nothing, renames nothing and deletes nothing.
 *
 * The text being replaced was wrong twice over:
 *   - "The first region is the default" — "default" is now a real per-region flag
 *     (FulfillmentRegion::$defaultForNewCompany). Reordering this list has no bearing on it.
 *   - "and can't be deleted" — true, but the undeletable region is the lowest id in the table
 *     (lockedFulfillmentRegionId()), which reordering this list also does not change.
 *
 * Only the description moves. The value is left exactly as the installation has it: on an install
 * past first run it is inert, and on one that has not run yet it is still the seed.
 */
final class Version20260804210000 extends AbstractMigration
{
    private const DESCRIPTION = 'First-run seed only: these names create the initial fulfillment '
        . 'regions on a new installation. Once any region exists this value is ignored — add, '
        . 'rename, delete and configure regions under Fulfillment Regions instead.';

    public function getDescription(): string
    {
        return 'Describe fulfillment_regions as the first-run seed it is, not as live configuration.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('app_setting')) {
            return;
        }

        $this->addSql(
            'UPDATE app_setting SET description = :description, updated_at = CURRENT_TIMESTAMP WHERE setting_key = :key',
            ['description' => self::DESCRIPTION, 'key' => 'fulfillment_regions'],
        );
    }

    public function down(Schema $schema): void
    {
        // Deliberately not reverted: the prior text described a "default" that no longer exists and
        // an ordering that never did anything. Putting it back would only mislead again.
    }
}
