<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Service\AppSettings;
use App\Service\ReferenceData\Seeders\FulfillmentRegionSeeder;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Creating a warehouse named after a fulfillment region that already exists — conducted per #624.
 *
 * ## The defect
 *
 * `WarehouseFulfillmentRegionService::createRegionForWarehouse()` asked whether THIS WAREHOUSE had
 * a region. It never asked whether a region of that NAME already existed. So the ordinary first
 * hour of an installation produced a duplicate: an admin logs in, the central reference-data
 * seeder creates `Main`, `Overflow` and `Supplier Direct` with no warehouses (queue item 61 — it
 * has no province to invent one from), the admin adds the building called `Main`, and a SECOND
 * region called `Main` is created beside the first.
 *
 * ## What the second row cost, which is why this is not a tidiness fix
 *
 * The two halves of the application then disagreed about which row the word "Main" meant. The new
 * warehouse paired with the NEW region, so the SEEDED one — the row every id-ordered resolver
 * reaches first — was left with no warehouse at all:
 *
 *  - `CustomerPricingResolver::resolveFulfillmentRegionEntity('Main')` walks `findAll()` and binds
 *    a cart line to the seeded row. `CartService::availableQuantity()` asks that region for its
 *    warehouse, gets none, and reads the stock as ZERO — 500 units sitting in the building called
 *    Main, invisible to every customer ordering from the region called Main.
 *  - `AdminOrderStockValidator` goes through `warehousesByLowerRegionName()`, which is keyed by
 *    `strtolower(trim(name))` and finds the link, and sees the full 500. Same word, same screen
 *    name, two answers.
 *  - Create Customer rendered TWO indistinguishable `Main` checkboxes, each with its own price
 *    list select, and a new company got two `company_fulfillment_region` rows named Main.
 *    `priceListForCompanyRegion()` matches by name and returns the first ACTIVE one, so the price
 *    chosen against the second box is never the price applied — and the document stores only the
 *    name, so the choice cannot be recovered afterwards.
 *  - `ProductImportService::templateCsv()` emitted `fulfillment_region__main` twice.
 *
 * ## What replaced it
 *
 * A region of that name with NO warehouse is adopted — that is the documented remedy, not a
 * compromise: the Fulfillment Regions screen marks the unpaired rows and the Warehouses screen is
 * where they are answered, and adopting is what the mirror path
 * (`warehouseForRegionNameOrCreate()`) has always done. A region another warehouse already serves
 * is refused at the name box, because `uniq_wfr_region` gives a region exactly one building and
 * the only remaining alternative is the duplicate this exists to stop. Either way the admin is
 * TOLD.
 *
 * ## The ordering is the test
 *
 * `WarehouseToInvoiceWalkthroughCest` and `InventoryDepthCest` both seed the shipped reference data
 * AFTER creating their warehouse, and both say in comments that they do it to step around this
 * defect. Every case here does it the other way round on purpose — seeder first, warehouse second —
 * because that is the order that triggers it, and a fixture that avoids the order proves nothing
 * about the fix.
 *
 * ## Assertions
 *
 * Every case drives the real screens with plain form POSTs carrying a CSRF token scraped from the
 * page being posted to, creates its own data, and reads `table.column` back out of SQLite. The
 * claim is always `SELECT ... FROM fulfillment_region`, never the rendered list. Nothing calls
 * `see()` on a bare word (#627): the warehouse form carries no element ids and adding them to a
 * frozen core template is outside this fix's approved scope, so each message assertion is anchored
 * by XPath to the one element that must carry it — the flash span, or the `field-error` span
 * belonging to the NAME box specifically rather than to any of the three that form renders — and
 * every absence is paired with a positive control on that same element.
 */
final class WarehouseNamedAfterAnExistingRegionCest
{
    /** The only region name any case here collides with, and one of the three the seeder ships. */
    private const SHIPPED = 'Main';

    /**
     * Codeception reuses ONE instance of this class across every method, so anything cached on
     * $this outlives the per-test transaction rollback that resets the database. Nothing is cached
     * on $this — every case reads its ids back out of SQLite — and the settings cache, which is a
     * service and not this object, is cleared for the same reason the other config Cests clear it.
     */
    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    // ---------------------------------------------------------------- conducting the real screens

    private function actAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('wh-region-name-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * The shipped regions, created the way a real admin login creates them — and FIRST.
     *
     * `amLoggedInAs()` installs a token straight into token storage and never runs the
     * authenticator, so it dispatches no `LoginSuccessEvent` and seeds nothing;
     * `haveSeededReferenceData()` is the helper the repo added for exactly that.
     */
    private function seedTheShippedRegionsFirst(FunctionalTester $I): void
    {
        $I->haveSeededReferenceData();

        $names = $this->connection($I)->fetchFirstColumn('SELECT name FROM fulfillment_region ORDER BY id');
        $I->assertSame(
            FulfillmentRegionSeeder::SHIPPED_REGION_NAMES,
            $names,
            'the shipped regions are not on file, so every case below would be testing an empty table',
        );
        $I->assertSame(
            0,
            (int) $this->connection($I)->fetchOne('SELECT COUNT(*) FROM warehouse_fulfillment_region'),
            'a seeded region already has a warehouse, so the adoption cases would prove nothing',
        );
    }

    /**
     * POST the Create Warehouse screen with exactly the fields it renders.
     *
     * Returns the warehouse id when the save went through and 0 when it did not, so a caller can
     * assert either outcome without the helper deciding which one is correct.
     */
    private function postCreateWarehouse(FunctionalTester $I, string $name): int
    {
        $I->amOnPage('/admin/warehouse/create');
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form.config-form-grid input[name="_token"]', 'value');

        $I->sendFormPostRequest('/admin/warehouse/create', [
            '_token' => $token,
            'name' => $name,
            'status' => 'Active',
            'province' => 'BC',
            'country' => 'CA',
        ]);

        return (int) $this->connection($I)->fetchOne('SELECT id FROM warehouse WHERE name = ? ORDER BY id ASC', [$name]);
    }

    private function connection(FunctionalTester $I): \Doctrine\DBAL\Connection
    {
        return $I->grabService('doctrine.orm.entity_manager')->getConnection();
    }

    /** How many regions wear this name — the claim every case makes, read by column. */
    private function regionsNamed(FunctionalTester $I, string $name): int
    {
        return (int) $this->connection($I)->fetchOne('SELECT COUNT(*) FROM fulfillment_region WHERE name = ?', [$name]);
    }

    /** @return array<string, mixed> the region row, straight out of SQLite */
    private function regionRow(FunctionalTester $I, int $id): array
    {
        $row = $this->connection($I)->fetchAssociative(
            'SELECT id, name, status, guest_visible, default_for_new_company, guest_price_list_id, created_at '
                . 'FROM fulfillment_region WHERE id = ?',
            [$id],
        );
        $I->assertIsArray($row, 'fulfillment_region row ' . $id . ' is missing');

        return $row;
    }

    /** The region this warehouse is linked to, read from the join table by column. 0 for none. */
    private function regionServedBy(FunctionalTester $I, int $warehouseId): int
    {
        return (int) $this->connection($I)->fetchOne(
            'SELECT fulfillment_region_id FROM warehouse_fulfillment_region WHERE warehouse_id = ?',
            [$warehouseId],
        );
    }

    // ------------------------------------------------------------------------------- the elements

    /** The flash span, rather than the page: 'Main' is a word that appears all over these screens. */
    private const FLASH_SUCCESS = '//div[contains(@class,"flash-messages")]/span[@data-type="success"]';

    /**
     * The field-error span belonging to the NAME box.
     *
     * Scoped to that label rather than to `.field-error`, because the warehouse form renders one of
     * these for the province and one for the country too, and an unscoped assertion would pass on
     * the wrong refusal.
     */
    private const NAME_FIELD_ERROR = '//form[contains(@class,"config-form-grid")]//label[input[@name="name"]]/span[contains(@class,"field-error")]';

    // ------------------------------------------------------------------------------------- cases

    /**
     * 1. Seeder first, then a warehouse named after a shipped region: ONE region of that name.
     *
     * The exact sequence two workers reported, and the one both existing walkthroughs reorder to
     * avoid. Before the fix this left `fulfillment_region` holding four rows, two of them called
     * Main, with the new warehouse serving the new one and the seeded one left with no building.
     */
    public function aWarehouseNamedAfterASeededRegionAdoptsItInsteadOfDuplicatingIt(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $this->seedTheShippedRegionsFirst($I);

        $seededMainId = (int) $this->connection($I)->fetchOne('SELECT id FROM fulfillment_region WHERE name = ?', [self::SHIPPED]);
        $I->assertGreaterThan(0, $seededMainId, 'the seeded region is missing, so this case proves nothing');
        $seededMainBefore = $this->regionRow($I, $seededMainId);
        $regionsBefore = (int) $this->connection($I)->fetchOne('SELECT COUNT(*) FROM fulfillment_region');

        $warehouseId = $this->postCreateWarehouse($I, self::SHIPPED);
        $I->assertGreaterThan(0, $warehouseId, 'the warehouse was not created, so the region assertions would be vacuous');

        // The claim, read by column rather than off the list screen.
        $I->assertSame(1, $this->regionsNamed($I, self::SHIPPED), 'fulfillment_region rows named "' . self::SHIPPED . '"');
        $I->assertSame(
            $regionsBefore,
            (int) $this->connection($I)->fetchOne('SELECT COUNT(*) FROM fulfillment_region'),
            'a fulfillment_region row was created somewhere',
        );

        // And it is the row that was already there that the building now answers for.
        $I->assertSame($seededMainId, $this->regionServedBy($I, $warehouseId), 'warehouse_fulfillment_region.fulfillment_region_id');

        // The row that must NOT change: the seeded region, compared whole. Its status, guest
        // visibility, price list and default-for-new-customer are settings made on the region
        // screen, and a building appearing underneath it does not restate them from this form.
        $I->assertSame($seededMainBefore, $this->regionRow($I, $seededMainId), 'creating the warehouse rewrote the region it adopted');

        // The admin is told, on the element that carries it and nowhere else.
        $I->seeElement(self::FLASH_SUCCESS);
        $I->see('no second region of that name was created', self::FLASH_SUCCESS);
        $I->see('already existed and keeps its own settings', self::FLASH_SUCCESS);
    }

    /**
     * 2. A genuinely new name still creates its region. The positive control.
     *
     * Without this, case 1 passes just as well if region creation were broken outright — one row
     * named Main is exactly what "never create a region again" also produces.
     *
     * It carries the negative half of case 1's message assertion too: the adoption sentence is
     * absent from the SAME flash element that case 1 requires it on, so neither case can pass by
     * the message being unconditional.
     */
    public function aWarehouseWithAnUnusedNameStillGetsItsOwnRegion(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $this->seedTheShippedRegionsFirst($I);

        $regionsBefore = (int) $this->connection($I)->fetchOne('SELECT COUNT(*) FROM fulfillment_region');
        $fresh = 'Prairie DC ' . strtoupper(substr(uniqid(), -6));
        $I->assertSame(0, $this->regionsNamed($I, $fresh), 'the fixture name is already taken, so this proves nothing');

        $warehouseId = $this->postCreateWarehouse($I, $fresh);
        $I->assertGreaterThan(0, $warehouseId, 'the warehouse was not created');

        // A region WAS created, it wears the name, and the building serves it.
        $I->assertSame(1, $this->regionsNamed($I, $fresh), 'fulfillment_region rows named "' . $fresh . '"');
        $I->assertSame(
            $regionsBefore + 1,
            (int) $this->connection($I)->fetchOne('SELECT COUNT(*) FROM fulfillment_region'),
            'creating a warehouse with an unused name did not create exactly one region',
        );

        $freshRegionId = (int) $this->connection($I)->fetchOne('SELECT id FROM fulfillment_region WHERE name = ?', [$fresh]);
        $I->assertSame($freshRegionId, $this->regionServedBy($I, $warehouseId), 'warehouse_fulfillment_region.fulfillment_region_id');
        $I->assertSame('Active', $this->regionRow($I, $freshRegionId)['status'], 'fulfillment_region.status of the region created with the warehouse');

        // The three shipped rows are untouched by a warehouse that had nothing to do with them.
        $I->assertSame(1, $this->regionsNamed($I, self::SHIPPED), 'fulfillment_region rows named "' . self::SHIPPED . '"');
        $I->assertSame(
            0,
            (int) $this->connection($I)->fetchOne(
                'SELECT COUNT(*) FROM warehouse_fulfillment_region l JOIN fulfillment_region r ON r.id = l.fulfillment_region_id WHERE r.name = ?',
                [self::SHIPPED],
            ),
            'an unrelated warehouse was paired with a shipped region',
        );

        // Same element as case 1: it says the plain thing, and does NOT say the adoption thing.
        $I->seeElement(self::FLASH_SUCCESS);
        $I->see('was created successfully', self::FLASH_SUCCESS);
        $I->dontSee('no second region of that name was created', self::FLASH_SUCCESS);

        // And the name box carries no refusal. Paired with case 4, which requires one there.
        $I->dontSeeElement(self::NAME_FIELD_ERROR);
    }

    /**
     * 3. Two warehouses that legitimately need separate regions still get them. Unchanged.
     *
     * The behaviour this fix must not reach. Two buildings with two different names are two
     * territories, and each gets its own region and its own link row — which is also what keeps
     * "adopt an existing region" from quietly becoming "share one region between buildings", a
     * thing `uniq_wfr_region` does not allow and this model does not mean.
     *
     * Seeded first like every other case, so the ordering that triggers the defect is present
     * while the untouched behaviour is asserted.
     */
    public function twoWarehousesWithDifferentNamesStillGetOneRegionEach(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $this->seedTheShippedRegionsFirst($I);

        $regionsBefore = (int) $this->connection($I)->fetchOne('SELECT COUNT(*) FROM fulfillment_region');
        $tag = strtoupper(substr(uniqid(), -6));
        $alpha = 'Alpha DC ' . $tag;
        $beta = 'Beta DC ' . $tag;

        $alphaWarehouseId = $this->postCreateWarehouse($I, $alpha);
        $betaWarehouseId = $this->postCreateWarehouse($I, $beta);
        $I->assertGreaterThan(0, $alphaWarehouseId, 'the first warehouse was not created');
        $I->assertGreaterThan(0, $betaWarehouseId, 'the second warehouse was not created');
        $I->assertNotSame($alphaWarehouseId, $betaWarehouseId, 'the two warehouses are one row');

        $I->assertSame(
            $regionsBefore + 2,
            (int) $this->connection($I)->fetchOne('SELECT COUNT(*) FROM fulfillment_region'),
            'two differently named warehouses did not produce two regions',
        );

        $alphaRegionId = (int) $this->connection($I)->fetchOne('SELECT id FROM fulfillment_region WHERE name = ?', [$alpha]);
        $betaRegionId = (int) $this->connection($I)->fetchOne('SELECT id FROM fulfillment_region WHERE name = ?', [$beta]);
        $I->assertGreaterThan(0, $alphaRegionId, 'the first warehouse got no region of its own');
        $I->assertGreaterThan(0, $betaRegionId, 'the second warehouse got no region of its own');
        $I->assertNotSame($alphaRegionId, $betaRegionId, 'the two warehouses were made to share one region');

        // Each building answers for its own territory, read from the join table by column.
        $I->assertSame($alphaRegionId, $this->regionServedBy($I, $alphaWarehouseId), 'warehouse_fulfillment_region for ' . $alpha);
        $I->assertSame($betaRegionId, $this->regionServedBy($I, $betaWarehouseId), 'warehouse_fulfillment_region for ' . $beta);
        $I->assertSame(
            2,
            (int) $this->connection($I)->fetchOne(
                'SELECT COUNT(*) FROM warehouse_fulfillment_region WHERE warehouse_id IN (?, ?)',
                [$alphaWarehouseId, $betaWarehouseId],
            ),
            'warehouse_fulfillment_region rows for the two new warehouses',
        );
    }

    /**
     * 4. A name whose region is ALREADY served is refused at the name box, and nothing is written.
     *
     * The one collision adoption cannot answer. `uniq_wfr_region` gives a region exactly one
     * warehouse, so there is no link to make; before the fix this minted the second row instead,
     * which is the case that splits the stock. Refused before anything is persisted rather than
     * after — a half-created warehouse with no region is its own defect.
     */
    public function aWarehouseNamedAfterARegionThatIsAlreadyServedIsRefusedAndSaysWhy(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $this->seedTheShippedRegionsFirst($I);

        // The first building adopts the seeded region, which is case 1's outcome and this case's
        // setup: the name is now taken by a region that HAS a warehouse.
        $firstWarehouseId = $this->postCreateWarehouse($I, self::SHIPPED);
        $I->assertGreaterThan(0, $firstWarehouseId, 'the setup warehouse was not created');
        $I->assertSame(1, $this->regionsNamed($I, self::SHIPPED), 'the setup did not leave exactly one region named ' . self::SHIPPED);

        $regionsBefore = (int) $this->connection($I)->fetchOne('SELECT COUNT(*) FROM fulfillment_region');
        $warehousesBefore = (int) $this->connection($I)->fetchOne('SELECT COUNT(*) FROM warehouse');
        $linksBefore = (int) $this->connection($I)->fetchOne('SELECT COUNT(*) FROM warehouse_fulfillment_region');

        // A second building asking for the same name.
        $I->amOnPage('/admin/warehouse/create');
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form.config-form-grid input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/warehouse/create', [
            '_token' => $token,
            'name' => self::SHIPPED,
            'status' => 'Active',
            'province' => 'AB',
            'country' => 'CA',
        ]);

        // Nothing moved, in any of the three tables.
        $I->assertSame(1, $this->regionsNamed($I, self::SHIPPED), 'fulfillment_region rows named "' . self::SHIPPED . '"');
        $I->assertSame(
            $regionsBefore,
            (int) $this->connection($I)->fetchOne('SELECT COUNT(*) FROM fulfillment_region'),
            'the refused save still wrote a fulfillment_region row',
        );
        $I->assertSame(
            $warehousesBefore,
            (int) $this->connection($I)->fetchOne('SELECT COUNT(*) FROM warehouse'),
            'the refused save still wrote a warehouse row',
        );
        $I->assertSame(
            $linksBefore,
            (int) $this->connection($I)->fetchOne('SELECT COUNT(*) FROM warehouse_fulfillment_region'),
            'the refused save still wrote a warehouse_fulfillment_region row',
        );

        // The person is told, at the box they have to change — the same element case 2 requires to
        // be absent — and told which building already answers for that name.
        $I->seeResponseCodeIs(422);
        $I->seeElement(self::NAME_FIELD_ERROR);
        $I->see('already exists and warehouse', self::NAME_FIELD_ERROR);
        $I->see('Give this warehouse a different name', self::NAME_FIELD_ERROR);
    }
}
