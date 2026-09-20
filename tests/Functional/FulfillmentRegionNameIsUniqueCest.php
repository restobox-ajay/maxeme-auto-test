<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Entity\AdminUser;
use App\Service\AppSettings;
use App\Service\ReferenceData\Seeders\FulfillmentRegionSeeder;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * Two fulfillment regions may not share a name — conducted per #624.
 *
 * ## The defect
 *
 * `fulfillment_region.name` carried no constraint. Two regions called `Main` made the two halves of
 * the application disagree about which row the word meant:
 * `CustomerPricingResolver::resolveFulfillmentRegionEntity('Main')` walks `findAll()` in id order,
 * reaches the UNPAIRED row, asks it for a warehouse, gets none and reports **0 units** to a cart;
 * `AdminOrderStockValidator` goes through `warehousesByLowerRegionName()`, finds the link and sees
 * **500**. Same word, same screen, two answers. Create Customer rendered two indistinguishable
 * `Main` checkboxes, each with its own price-list select, and a document stores only the NAME —
 * so the price chosen against the second is unrecoverable afterwards.
 *
 * `WarehouseNamedAfterAnExistingRegionCest` closed one door: a warehouse named after an existing
 * region ADOPTS it. This closes the rest, in the only place that also closes the ones written next
 * year — `uniq_fulfillment_region_name`, a UNIQUE index over `LOWER(TRIM(name))`
 * (`Version20260918120000`).
 *
 * ## Why the case cases are not a nicety
 *
 * `ValidFulfillmentRegionRequestValidator` matched with `findOneBy(['name' => $value])`, which on
 * SQLite is case-SENSITIVE, so the Fulfillment Regions screen created `main` beside `Main` while
 * `warehousesByLowerRegionName()` — keyed on `strtolower(trim(name))` — had already decided those
 * were ONE region. Two rows spelt that way are not two regions; they are one region of which only
 * the lowest id is ever resolved, and the other holds a building, a price list and customer links
 * that nothing reaches. A plain `UNIQUE(name)` would have permitted exactly that, which is why the
 * index is on the expression and why cases 3 and 4 exist.
 *
 * ## Assertions
 *
 * Every case drives the real screen with a plain form POST carrying a CSRF token scraped from the
 * page being posted to, creates its own data, and reads `table.column` back out of SQLite. The
 * claim is always `SELECT ... FROM fulfillment_region`, never the rendered list. Nothing calls
 * `see()` on a bare word (#627): `Main` is a word that appears in this form's heading, its lead
 * paragraph and the list screen behind it, so every message assertion is anchored to the id of the
 * one element that must carry it — `#fulfillment-region-name-error`, which belongs to the NAME box
 * specifically and not to the two `field-error` spans the same form renders for the warehouse
 * country and province — and every absence is paired with a positive control on that same element.
 */
final class FulfillmentRegionNameIsUniqueCest
{
    /** The name every collision case here collides with, and one of the three the seeder ships. */
    private const SHIPPED = 'Main';

    /**
     * The refusal span belonging to the NAME box, addressed by id.
     *
     * The id was added to `admin/config/fulfillment_region_form.html.twig` for this: the form
     * renders three `field-error` spans on a create, and `.field-error` would be satisfied by the
     * warehouse province's refusal just as well as by the name's.
     */
    private const NAME_ERROR = '#fulfillment-region-name-error';

    /**
     * The statement `Version20260918120000` emits, character for character.
     *
     * BOTH suites build their schema from Doctrine metadata — `tests/_bootstrap.php` runs
     * `doctrine:schema:create`, `DoctrineIntegrationTestCase` uses `SchemaTool` — and Doctrine's
     * mapping cannot express an index over an expression at all: declaring
     * `#[ORM\UniqueConstraint(columns: ['LOWER(TRIM(name))'])]` makes `schema:create` fail outright
     * with *"There is no column with name "LOWER(TRIM(name))" on table "fulfillment_region""*. So
     * this database does not carry the index and case 5 has to build it.
     *
     * That would be circular — a test proving its own DDL — if the string were only written here.
     * It is not: case 5 asserts that the migration's source contains this exact statement, so the
     * two cannot drift, and `bin/ci-migration-replay` proves the MIGRATION creates it, on a
     * database built by replaying the real chain, which is the only place that is ever true.
     */
    private const INDEX_DDL = 'CREATE UNIQUE INDEX uniq_fulfillment_region_name ON fulfillment_region (LOWER(TRIM(name)))';

    /**
     * Codeception reuses ONE instance of this class across every method, so anything cached on
     * $this outlives the per-test transaction rollback that resets the database. Nothing is cached
     * on $this — every case reads its ids and counts back out of SQLite — and the settings cache,
     * which is a service and not this object, is cleared for the same reason the other config Cests
     * clear it.
     */
    public function _before(FunctionalTester $I): void
    {
        $I->grabService(AppSettings::class)->clearCache();
    }

    // ---------------------------------------------------------------- conducting the real screen

    private function actAsAdmin(FunctionalTester $I): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail('region-name-unique-' . uniqid() . '@example.test');
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, 'test-password-123'));
        $I->haveInRepository($admin);

        $I->amLoggedInAs($admin, 'admin');
        $I->haveHttpHeader('Host', 'admin.localhost');
    }

    /**
     * The shipped regions, created the way a real admin login creates them.
     *
     * `amLoggedInAs()` installs a token straight into token storage and never runs the
     * authenticator, so it dispatches no `LoginSuccessEvent` and seeds nothing;
     * `haveSeededReferenceData()` is the helper the repo added for exactly that.
     */
    private function seedTheShippedRegions(FunctionalTester $I): void
    {
        $I->haveSeededReferenceData();

        $I->assertSame(
            FulfillmentRegionSeeder::SHIPPED_REGION_NAMES,
            $this->connection($I)->fetchFirstColumn('SELECT name FROM fulfillment_region ORDER BY id'),
            'the shipped regions are not on file, so every case below would be testing an empty table',
        );
    }

    /** POST the Create Fulfillment Region screen with exactly the fields it renders. */
    private function postCreateRegion(FunctionalTester $I, string $name): void
    {
        $I->amOnPage('/admin/fulfillment-region/create');
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form.config-form-grid input[name="_token"]', 'value');

        // The warehouse address is valid on purpose in every case. Creating a region creates the
        // building its stock is counted in (queue item 61), and a blank province would put a SECOND
        // field error on this form — which would let a case pass on the wrong refusal.
        $I->sendFormPostRequest('/admin/fulfillment-region/create', [
            '_token' => $token,
            'name' => $name,
            'status' => 'Active',
            'default_for_new_company' => '0',
            'warehouse_country' => 'CA',
            'warehouse_province' => 'BC',
        ]);
    }

    private function connection(FunctionalTester $I): \Doctrine\DBAL\Connection
    {
        return $I->grabService('doctrine.orm.entity_manager')->getConnection();
    }

    private function regionCount(FunctionalTester $I): int
    {
        return (int) $this->connection($I)->fetchOne('SELECT COUNT(*) FROM fulfillment_region');
    }

    /** How many rows the DATABASE would consider this name to be — the claim every case makes. */
    private function regionsWearing(FunctionalTester $I, string $name): int
    {
        return (int) $this->connection($I)->fetchOne(
            'SELECT COUNT(*) FROM fulfillment_region WHERE LOWER(TRIM(name)) = LOWER(TRIM(?))',
            [$name],
        );
    }

    /** The exact spellings on file, so a case can say which one survived rather than how many did. */
    private function spellingsOnFile(FunctionalTester $I, string $name): array
    {
        return $this->connection($I)->fetchFirstColumn(
            'SELECT name FROM fulfillment_region WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) ORDER BY id',
            [$name],
        );
    }

    // ------------------------------------------------------------------------------------- cases

    /**
     * 1. A name that is already on the list is refused, and no row is written.
     *
     * The plain case, and the one a plain `UNIQUE(name)` would also have covered. It is here because
     * the refusal has to happen on the SCREEN: the index alone would turn this into a 500.
     */
    public function aNameThatAlreadyExistsIsRefusedAndNothingIsWritten(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $this->seedTheShippedRegions($I);

        $before = $this->regionCount($I);
        $warehousesBefore = (int) $this->connection($I)->fetchOne('SELECT COUNT(*) FROM warehouse');

        $this->postCreateRegion($I, self::SHIPPED);

        $I->assertSame($before, $this->regionCount($I), 'the refused save still wrote a fulfillment_region row');
        $I->assertSame(1, $this->regionsWearing($I, self::SHIPPED), 'fulfillment_region rows the database reads as "' . self::SHIPPED . '"');
        $I->assertSame(
            $warehousesBefore,
            (int) $this->connection($I)->fetchOne('SELECT COUNT(*) FROM warehouse'),
            'the refused save still created the warehouse the region would have been given',
        );

        // The person is told, at the box they have to change.
        $I->seeResponseCodeIs(422);
        $I->seeElement(self::NAME_ERROR);
        $I->see('Fulfillment region "Main" already exists.', self::NAME_ERROR);
    }

    /**
     * 2. A genuinely new name still creates one. The positive control.
     *
     * Without it, case 1 passes just as well if region creation were broken outright — no new row
     * is exactly what "never create a region again" also produces. It carries the negative half of
     * every message assertion too: the refusal element is ABSENT here, on the same screen.
     */
    public function aGenuinelyNewNameStillCreatesARegion(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $this->seedTheShippedRegions($I);

        $before = $this->regionCount($I);
        $fresh = 'Prairie DC ' . strtoupper(substr(uniqid(), -6));
        $I->assertSame(0, $this->regionsWearing($I, $fresh), 'the fixture name is already taken, so this proves nothing');

        $this->postCreateRegion($I, $fresh);

        $I->assertSame($before + 1, $this->regionCount($I), 'a new name did not create exactly one fulfillment_region row');
        $I->assertSame(1, $this->regionsWearing($I, $fresh), 'fulfillment_region rows the database reads as "' . $fresh . '"');
        $I->assertSame([$fresh], $this->spellingsOnFile($I, $fresh), 'fulfillment_region.name of the row that was created');

        // The three shipped rows are untouched by a region that had nothing to do with them.
        $I->assertSame(1, $this->regionsWearing($I, self::SHIPPED), 'fulfillment_region rows the database reads as "' . self::SHIPPED . '"');

        // And nothing was refused at the box the other four cases require a refusal on.
        $I->dontSeeElement(self::NAME_ERROR);
    }

    /**
     * 3. A case variant of an existing name is refused. The half a plain `UNIQUE(name)` would miss.
     *
     * `main` and `Main` are already ONE region to `warehousesByLowerRegionName()`, so creating both
     * does not create a second region — it creates a second ROW, and every stock lookup reaches only
     * one of them. The message names the EXISTING spelling, because it differs from what was typed
     * and the admin would otherwise go looking for their own spelling on the list screen.
     */
    public function aCaseVariantOfAnExistingNameIsRefused(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $this->seedTheShippedRegions($I);

        $before = $this->regionCount($I);

        $this->postCreateRegion($I, 'main');

        $I->assertSame($before, $this->regionCount($I), 'the refused save still wrote a fulfillment_region row');
        $I->assertSame(1, $this->regionsWearing($I, self::SHIPPED), 'fulfillment_region rows the database reads as "' . self::SHIPPED . '"');
        $I->assertSame(
            [self::SHIPPED],
            $this->spellingsOnFile($I, self::SHIPPED),
            'the spelling on file changed, or a second spelling of the same name joined it',
        );

        $I->seeResponseCodeIs(422);
        $I->seeElement(self::NAME_ERROR);
        $I->see('Fulfillment region "Main" already exists', self::NAME_ERROR);
        $I->see('without regard to capitalisation or surrounding spaces', self::NAME_ERROR);
    }

    /**
     * 4. A name differing only by leading or trailing whitespace is refused.
     *
     * The other half of the index expression. ` Main ` reaches the controller trimmed, so this is
     * really a claim about two layers agreeing — and it is the one that would fail first if the
     * index were ever narrowed to `LOWER(name)` while the screen kept trimming, or the other way
     * round.
     */
    public function aNameDifferingOnlyByWhitespaceIsRefused(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $this->seedTheShippedRegions($I);

        $before = $this->regionCount($I);

        $this->postCreateRegion($I, '  Main  ');

        $I->assertSame($before, $this->regionCount($I), 'the refused save still wrote a fulfillment_region row');
        $I->assertSame(1, $this->regionsWearing($I, self::SHIPPED), 'fulfillment_region rows the database reads as "' . self::SHIPPED . '"');
        $I->assertSame(
            [self::SHIPPED],
            $this->spellingsOnFile($I, self::SHIPPED),
            'a padded spelling of an existing name was written as its own row',
        );

        $I->seeResponseCodeIs(422);
        $I->seeElement(self::NAME_ERROR);
        $I->see('Fulfillment region "Main" already exists.', self::NAME_ERROR);
    }

    /**
     * 5. The DATABASE refuses it, not only the screen.
     *
     * The whole point of the change: an application check protects the paths somebody remembered,
     * and there are five that create a `FulfillmentRegion` today. The index protects the sixth.
     *
     * The index is built here because this database does not carry it — see {@see INDEX_DDL} for
     * why it cannot, and note that the statement built is asserted to be the migration's own. It is
     * dropped again in a `finally`: the per-test transaction rollback would undo it anyway (SQLite's
     * DDL is transactional), but a leaked UNIQUE index is the kind of thing that fails a LATER test
     * in a way that reads like a real defect.
     */
    public function theDatabaseItselfRefusesASecondRowOfTheSameName(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $this->seedTheShippedRegions($I);

        $migration = file_get_contents(\dirname(__DIR__, 2) . '/migrations/Version20260918120000.php');
        $I->assertIsString($migration, 'the migration this case builds its index from is missing');
        $I->assertStringContainsString(
            self::INDEX_DDL,
            $migration,
            'the index built below is no longer the statement Version20260918120000 emits, so this case proves nothing about production',
        );

        $connection = $this->connection($I);

        // Creating it over the three shipped rows is itself the first claim: the index is one a real
        // installation can actually take.
        $connection->executeStatement(self::INDEX_DDL);

        try {
            $before = $this->regionCount($I);

            $refusals = [];
            foreach (['main', ' Main ', 'MAIN', self::SHIPPED] as $collides) {
                try {
                    $this->insertRegion($connection, $collides);
                    $refusals[$collides] = 'accepted';
                } catch (UniqueConstraintViolationException) {
                    $refusals[$collides] = 'refused';
                }
            }

            $I->assertSame(
                ['main' => 'refused', ' Main ' => 'refused', 'MAIN' => 'refused', self::SHIPPED => 'refused'],
                $refusals,
                'the database accepted a row it must not have',
            );

            // POSITIVE CONTROL, on the same table through the same statement: a genuinely different
            // name still takes a row. Without it the four refusals above are equally consistent with
            // the table refusing everything — which is what a botched index would actually produce.
            $distinct = 'Prairie DC ' . strtoupper(substr(uniqid(), -6));
            $this->insertRegion($connection, $distinct);

            $I->assertSame($before + 1, $this->regionCount($I), 'rows added to fulfillment_region across five inserts');
            $I->assertSame(1, $this->regionsWearing($I, $distinct), 'the positive control did not land');
            $I->assertSame(
                [self::SHIPPED],
                $this->spellingsOnFile($I, self::SHIPPED),
                'a second spelling of the shipped name survived the index',
            );
        } finally {
            $connection->executeStatement('DROP INDEX IF EXISTS uniq_fulfillment_region_name');
        }
    }

    /** A region row written straight through DBAL, bypassing the screen and the validator entirely. */
    private function insertRegion(\Doctrine\DBAL\Connection $connection, string $name): void
    {
        $connection->insert('fulfillment_region', [
            'name' => $name,
            'status' => 'Active',
            'guest_visible' => 0,
            'default_for_new_company' => 0,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 6. Renaming an existing region onto another one's name is refused too.
     *
     * The update path runs the same validator with `currentRegionId` set, and that exclusion is
     * exactly what a careless case-insensitive rewrite breaks: a region compared against itself
     * case-insensitively still matches itself, so a save that changes only the status would start
     * refusing with "already exists" naming the row being saved. Both halves are asserted here —
     * the collision refused, and the region's own name saved back onto itself accepted.
     */
    public function renamingARegionOntoAnotherNameIsRefusedButSavingItsOwnNameIsNot(FunctionalTester $I): void
    {
        $this->actAsAdmin($I);
        $this->seedTheShippedRegions($I);

        $overflowId = (int) $this->connection($I)->fetchOne('SELECT id FROM fulfillment_region WHERE name = ?', ['Overflow']);
        $I->assertGreaterThan(0, $overflowId, 'the second shipped region is missing, so this case proves nothing');

        // Renaming Overflow to a case variant of Main: refused, and Overflow keeps its name.
        $this->postUpdateRegion($I, $overflowId, 'MAIN');

        $I->seeResponseCodeIs(422);
        $I->seeElement(self::NAME_ERROR);
        $I->see('Fulfillment region "Main" already exists', self::NAME_ERROR);
        $I->assertSame(
            'Overflow',
            (string) $this->connection($I)->fetchOne('SELECT name FROM fulfillment_region WHERE id = ?', [$overflowId]),
            'fulfillment_region.name of the region whose rename was refused',
        );
        $I->assertSame(1, $this->regionsWearing($I, self::SHIPPED), 'fulfillment_region rows the database reads as "' . self::SHIPPED . '"');

        // POSITIVE CONTROL on the same screen: saving the row under its OWN name goes through. This
        // is the half the currentRegionId exclusion exists for, and the half a naive fold breaks.
        $this->postUpdateRegion($I, $overflowId, 'Overflow');

        $I->dontSeeElement(self::NAME_ERROR);
        $I->assertSame(
            'Overflow',
            (string) $this->connection($I)->fetchOne('SELECT name FROM fulfillment_region WHERE id = ?', [$overflowId]),
            'fulfillment_region.name after saving the region under its own name',
        );
        $I->assertSame(3, $this->regionCount($I), 'fulfillment_region rows after two updates that create nothing');
    }

    /** POST the Update Fulfillment Region screen. It asks for no warehouse address — nothing is created. */
    private function postUpdateRegion(FunctionalTester $I, int $id, string $name): void
    {
        $I->amOnPage('/admin/fulfillment-region/' . $id . '/update');
        $I->seeResponseCodeIsSuccessful();
        $token = (string) $I->grabAttributeFrom('form.config-form-grid input[name="_token"]', 'value');

        $I->sendFormPostRequest('/admin/fulfillment-region/' . $id . '/update', [
            '_token' => $token,
            'name' => $name,
            'status' => 'Active',
            'default_for_new_company' => '0',
        ]);
    }
}
