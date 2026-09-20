<?php

declare(strict_types=1);

namespace App\Service\ReferenceData\Seeders;

use App\Contract\ReferenceData\ReferenceDataSeederInterface;
use App\Entity\AppSetting;
use App\Entity\FulfillmentRegion;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The shipped fulfillment regions.
 *
 * This is what `ConfigController::syncFulfillmentRegionsFromSettings()` did, called from TWO GET
 * actions — `fulfillmentRegions()` (`/admin/fulfillment-region`) and `warehouses()`
 * (`/admin/warehouse`). It is the costliest of the lazily-created lists, because a region is not
 * decoration: until one exists a customer cannot be given one, and nothing can be quoted or ordered
 * at all. The customer edit screen said "No fulfillment regions configured yet", the quote screen
 * refused in a disabled box, and the Customer Fulfillment Regions screen offered no way to add one —
 * three screens all telling the admin to do something none of them could do, with the actual remedy
 * being to visit an unrelated config screen once.
 *
 * ## Only a COMPLETELY empty table is seeded
 *
 * Exactly what `syncFulfillmentRegionsFromSettings()` did — it returned early the moment
 * `lockedFulfillmentRegionId()` found any row — and it matters more here than anywhere else,
 * because regions are named by hand on a real installation. Seeding into a populated table would
 * add "Overflow" and "Supplier Direct" next to the regions a customer actually operates.
 *
 * ## Named from the setting, when there is one
 *
 * `fulfillment_regions` is an app setting whose own description says it is "first-run seed only".
 * That is honoured: an installation that edited the names before anybody opened the regions screen
 * gets the names it asked for. Read straight off the `app_setting` row rather than through
 * {@see \App\Service\AppSettings}, because that service caches the whole table and this runs inside
 * the seeding transaction, where a cache read could be served from before it.
 *
 * ## No warehouse is created
 *
 * Queue item 61: a region CAN exist with no warehouse, and creating one here would mean inventing an
 * address. There is no province to be had on an installation that has said nothing about itself, and
 * `company_state` is where the OFFICE is — stamping it on a building would compute tax against a
 * guess and never mention it. The Fulfillment Regions screen marks every region that has no
 * warehouse, which is a state an admin can see and answer.
 */
final class FulfillmentRegionSeeder implements ReferenceDataSeederInterface
{
    /**
     * The shipped names, and the single source of them.
     *
     * `ConfigController::coreSettingDefaults()` builds the `fulfillment_regions` setting's default
     * VALUE from this list, so the setting and the rows cannot drift into disagreeing about what a
     * new installation ships with.
     */
    public const SHIPPED_REGION_NAMES = ['Main', 'Overflow', 'Supplier Direct'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function getKey(): string
    {
        return 'core.fulfillment_region';
    }

    public function getLabel(): string
    {
        return 'Fulfillment regions';
    }

    public function seed(): int
    {
        if ($this->em->getRepository(FulfillmentRegion::class)->findOneBy([]) instanceof FulfillmentRegion) {
            return 0;
        }

        $created = 0;
        foreach ($this->namesToSeed() as $name) {
            $this->em->persist((new FulfillmentRegion())->setName($name)->setStatus('Active'));
            $created++;
        }

        return $created;
    }

    /**
     * The names from the `fulfillment_regions` setting, or the shipped list when it says nothing.
     *
     * Splits on newlines, commas and semicolons and drops blanks and duplicates — the same parsing
     * `ConfigController::fulfillmentRegionDefaults()` does, because the setting is free text an
     * admin types into and has always accepted all three separators.
     *
     * ## Duplicates are dropped the way the DATABASE reads them
     *
     * This used to be `array_unique()`, which compares the strings exactly, and that was the one
     * remaining way this seeder could produce a collision: `uniq_fulfillment_region_name`
     * (`Version20260918120000`) is a UNIQUE index over `LOWER(TRIM(name))`, so a setting reading
     * `Main, main` is not two regions — it is one name typed twice, and the flush would have died on
     * a `UniqueConstraintViolationException` inside the first admin login of the installation, with
     * nothing on screen able to say which of three seeders had failed or why.
     *
     * The spelling that survives is the FIRST one the setting gives, which is the one the admin
     * wrote first; the rest were never going to become rows.
     *
     * @return list<string>
     */
    private function namesToSeed(): array
    {
        $setting = $this->em->getRepository(AppSetting::class)->findOneBy(['settingKey' => 'fulfillment_regions']);
        $raw = $setting instanceof AppSetting ? (string) $setting->getSettingValue() : '';

        $names = [];
        foreach (preg_split('/\R+/', str_replace([',', ';'], "\n", $raw)) ?: [] as $part) {
            $name = trim((string) $part);
            // strtolower() and not mb_strtolower(): the index folds with SQLite's LOWER(), which is
            // ASCII-only, and a seeder that folded more than the index does would silently drop a
            // name the database would have accepted.
            if ($name !== '' && !isset($names[strtolower($name)])) {
                $names[strtolower($name)] = $name;
            }
        }

        $names = array_values($names);

        return $names === [] ? self::SHIPPED_REGION_NAMES : $names;
    }
}
