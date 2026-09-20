<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Contract\ReferenceData\ReferenceDataSeedOutcome;
use App\Entity\AdminUser;
use App\Entity\Fee;
use App\Entity\ReferenceDataSeedMark;
use App\Entity\SalesTax;
use App\Entity\TrackingPolicy;
use App\Entity\UnitOfMeasure;
use App\Repository\ReferenceDataSeedMarkRepository;
use App\Service\ReferenceData\ReferenceDataSeeder;
use App\Service\ReferenceData\Seeders\FulfillmentRegionSeeder;
use App\Service\ReferenceData\Seeders\TrackingPolicySeeder;
use App\Service\ReferenceData\Seeders\UnitOfMeasureSeeder;
use App\Tests\Support\Fixtures\LateBundleReferenceDataSeeder;
use App\Tests\Support\Fixtures\RaceWinnerReferenceDataSeeder;
use App\Tests\Support\Fixtures\ThrowingReferenceDataSeeder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryAdjustmentReason;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

/**
 * The central reference data seeder, conducted through the real admin login form (#624).
 *
 * ## What this is about
 *
 * Seven config screens used to create the rows they were about to render — a GET action that wrote.
 * Until somebody opened the right screen the list did not exist and everything downstream behaved
 * as though the concept did not, silently. Seeding now happens once, on `LoginSuccessEvent`.
 *
 * So every case below that can be driven by a login IS driven by one: a real POST to
 * `/admin/login` carrying the `_csrf_token` scraped off the rendered login page, exactly as a
 * browser with JavaScript turned off sends it. `amLoggedInAs()` is deliberately NOT used anywhere
 * in this file — it installs a token straight into the token storage without running the
 * authenticator, so no `LoginSuccessEvent` is dispatched and the very thing under test never fires.
 * (That is also why every other Cest touching these screens now calls `haveSeededReferenceData()`.)
 *
 * Every assertion reads COLUMNS back out of the database through a raw DBAL query — never the
 * rendered page, which would pass against a screen that printed the right thing and stored nothing,
 * and never a `see()` on a bare number, which per #627 matches any number containing it.
 *
 * ## State between methods
 *
 * Codeception reuses ONE Cest instance across the methods of a class in this repository, so
 * anything stashed on `$this` leaks from one method into the next. Nothing is stashed on `$this`
 * here; the one piece of process-global state that IS touched — `ThrowingReferenceDataSeeder`'s
 * static switch — is reset in `_before` AND `_after`, so neither an earlier method's leak nor this
 * one's can reach another test.
 */
final class ReferenceDataSeedingCest
{
    /** Unique per test method so a leaked login cannot make the next method's login a no-op. */
    private const PASSWORD = 'test-password-123';

    public function _before(FunctionalTester $I): void
    {
        ThrowingReferenceDataSeeder::reset();
    }

    public function _after(FunctionalTester $I): void
    {
        ThrowingReferenceDataSeeder::reset();
    }

    // ---------------------------------------------------------------------------------------
    // (1) A fresh install, nobody having visited any config page, has the rows after one login.
    // ---------------------------------------------------------------------------------------

    /**
     * Without the change this fails on the very first assertion, because on a metadata-built schema
     * with no config page visited every one of these tables is empty:
     *
     *   Failed asserting that 0 is identical to 4.
     *   (unit_of_measure after the first admin login)
     */
    public function aFirstAdminLoginSeedsEveryReferenceListOnAFreshInstall(FunctionalTester $I): void
    {
        $connection = $this->connection($I);

        // A fresh install: nothing seeded, nothing marked, and nobody has opened a config screen.
        $I->assertSame(0, $this->countRows($connection, 'reference_data_seed_mark'), 'reference_data_seed_mark before any login');
        $I->assertSame(0, $this->countRows($connection, 'unit_of_measure'), 'unit_of_measure before any login');
        $I->assertSame(0, $this->countRows($connection, 'tracking_policy'), 'tracking_policy before any login');
        $I->assertSame(0, $this->countRows($connection, 'inventory_adjustment_reason'), 'inventory_adjustment_reason before any login');
        $I->assertSame(0, $this->countRows($connection, 'fee'), 'fee before any login');
        $I->assertSame(0, $this->countRows($connection, 'sales_tax'), 'sales_tax before any login');
        $I->assertSame(0, $this->countRows($connection, 'fulfillment_region'), 'fulfillment_region before any login');

        $this->logInThroughTheRealForm($I, 'seed-first-login@example.test');

        // COUNT on the actual tables, not text on a page.
        $I->assertSame(4, $this->countRows($connection, 'unit_of_measure'), 'unit_of_measure after the first admin login');
        $I->assertSame(1, $this->countRows($connection, 'tracking_policy'), 'tracking_policy after the first admin login');
        $I->assertSame(
            \count(InventoryAdjustmentReason::defaults()),
            $this->countRows($connection, 'inventory_adjustment_reason'),
            'inventory_adjustment_reason after the first admin login',
        );

        // Five fee bundles ship seven fee rows between them. The Canadian tax bundle ships nine
        // sales_tax rows: five HST provinces, four GST provinces that SHARE the one federal `gst`
        // row, and three provincial components (SK PST, MB PST, QC QST) — 5 + 1 + 3.
        $I->assertSame(7, $this->countRows($connection, 'fee'), 'fee after the first admin login');
        $I->assertSame(9, $this->countRows($connection, 'sales_tax'), 'sales_tax after the first admin login');

        // The one that cost the most while it was lazy: until a region existed, nothing could be
        // quoted or ordered at all, and three separate screens said so without being able to fix it.
        $I->assertSame(
            \count(FulfillmentRegionSeeder::SHIPPED_REGION_NAMES),
            $this->countRows($connection, 'fulfillment_region'),
            'fulfillment_region after the first admin login',
        );

        // Every registered seeder marked itself exactly once.
        $marks = $this->markKeys($connection);
        $I->assertContains('core.unit_of_measure', $marks);
        $I->assertContains('core.tracking_policy', $marks);
        $I->assertContains('inventory_depth.adjustment_reason', $marks);
        $I->assertContains('tax_canada_simple.province_rates', $marks);
        $I->assertContains('core.fulfillment_region', $marks);
        $I->assertSame(\count($marks), \count(array_unique($marks)), 'one mark row per seeder key');
    }

    /**
     * The config screens read what the login created, and create nothing themselves.
     *
     * The positive control is the pair: the list is populated when opened AFTER a login, and the
     * counts are identical before and after opening it, so the rows on screen were not put there by
     * opening it. Without the change the second half fails — the table is empty until the GET, and
     * `unit_of_measure` goes 0 -> 4 across the `amOnPage()`.
     */
    public function theConfigScreensReadAndNoLongerWriteOnAGet(FunctionalTester $I): void
    {
        $connection = $this->connection($I);
        $this->logInThroughTheRealForm($I, 'seed-screens-read@example.test');

        foreach ([
            '/admin/product/units-of-measure' => 'unit_of_measure',
            '/admin/product/tracking-policies' => 'tracking_policy',
            '/admin/bundles/inventory-depth/adjust' => 'inventory_adjustment_reason',
            '/admin/bundles/fees/bc-tire' => 'fee',
            '/admin/bundles/tax/canada-simple' => 'sales_tax',
            '/admin/fulfillment-region' => 'fulfillment_region',
            '/admin/warehouse' => 'fulfillment_region',
        ] as $path => $table) {
            $before = $this->countRows($connection, $table);
            $I->assertGreaterThan(0, $before, $table . ' is already populated before ' . $path . ' is opened');

            $I->amOnPage($path);
            $I->seeResponseCodeIs(200);

            $I->assertSame($before, $this->countRows($connection, $table), $path . ' must not write to ' . $table . ' on a GET');

            // seeResponseCodeIs(200) alone is not enough: the login screen is also a 200, and a
            // session that quietly died would make every "did not write" assertion above pass for
            // the wrong reason.
            $I->assertStringNotContainsString('Sign in to your account', $I->grabPageSource(), $path . ' bounced to the login page');
        }

        // The positive control for the assertions above: the screen really is rendering the rows,
        // so "the count did not change" is not passing because the page is blank. Structural rather
        // than textual — per #627 a see() on a bare word proves less than a count of the elements
        // that carry it, and 'EA' is a substring of plenty.
        $I->amOnPage('/admin/product/units-of-measure');
        $I->seeNumberOfElements('.table-card table tbody tr', 4);
    }

    // ---------------------------------------------------------------------------------------
    // (2) A second login writes nothing at all.
    // ---------------------------------------------------------------------------------------

    /**
     * Ten runs produce what one run produced, and runs 2..10 write nothing.
     *
     * "Nothing" is asserted three ways, because a row count alone would pass against a seeder that
     * deleted a row and re-created it: the counts, every row's id, and `reference_data_seed_mark`'s
     * own `seeded_at` — a re-seed would move it.
     *
     * Without the change, `ensureCatalogue()` and the `ensureBySlug()` loops ran on every render and
     * this test never gets as far as run 2: there is no marks table and the seeder does not exist.
     */
    public function seedingTenTimesProducesWhatSeedingOnceProducedAndWritesNothingAfterTheFirst(FunctionalTester $I): void
    {
        $connection = $this->connection($I);
        $seeder = $I->grabService(ReferenceDataSeeder::class);

        $this->logInThroughTheRealForm($I, 'seed-idempotent@example.test');

        $countsAfterFirst = $this->allCounts($connection);
        $idsAfterFirst = $this->allRowIds($connection);
        $marksAfterFirst = $this->markRows($connection);

        for ($run = 2; $run <= 10; $run++) {
            foreach ($seeder->run() as $outcome) {
                $I->assertTrue(
                    $outcome->wroteNothing(),
                    sprintf('run %d: %s reported %d row(s) created', $run, $outcome->key, $outcome->rowsCreated),
                );
                $I->assertContains(
                    $outcome->state,
                    [ReferenceDataSeedOutcome::STATE_ALREADY_SEEDED],
                    sprintf('run %d: %s should have been skipped outright, got "%s"', $run, $outcome->key, $outcome->state),
                );
            }
        }

        $I->assertSame($countsAfterFirst, $this->allCounts($connection), 'row counts after ten runs');
        $I->assertSame($idsAfterFirst, $this->allRowIds($connection), 'row ids after ten runs — a re-created row would renumber');
        $I->assertSame($marksAfterFirst, $this->markRows($connection), 'seed marks after ten runs — a re-seed would move seeded_at');
    }

    /** A second real login is a second run, and writes nothing either. */
    public function aSecondAdminLoginWritesNothing(FunctionalTester $I): void
    {
        $connection = $this->connection($I);

        $this->logInThroughTheRealForm($I, 'seed-login-one@example.test');
        $countsAfterFirst = $this->allCounts($connection);
        $idsAfterFirst = $this->allRowIds($connection);

        $this->logInThroughTheRealForm($I, 'seed-login-two@example.test');

        $I->assertSame($countsAfterFirst, $this->allCounts($connection), 'row counts after a second admin login');
        $I->assertSame($idsAfterFirst, $this->allRowIds($connection), 'row ids after a second admin login');
    }

    // ---------------------------------------------------------------------------------------
    // (3) A row the customer deleted on purpose is not resurrected.
    // ---------------------------------------------------------------------------------------

    /**
     * The whole point of the mark, and the one thing the old lazy seeding got wrong by design:
     * `ensureCatalogue()`'s docblock said a deleted reason "does come back, and that is deliberate".
     *
     * Without the change this fails as:
     *   Failed asserting that 8 is identical to 7.
     *   (inventory_adjustment_reason after a later run — a deleted row came back)
     */
    public function aRowTheCustomerDeletedIsNotResurrectedByALaterRun(FunctionalTester $I): void
    {
        $connection = $this->connection($I);
        $seeder = $I->grabService(ReferenceDataSeeder::class);

        $this->logInThroughTheRealForm($I, 'seed-deletion@example.test');

        $seededReasons = $this->countRows($connection, 'inventory_adjustment_reason');
        $I->assertGreaterThan(1, $seededReasons, 'the reasons were seeded in the first place');

        // The customer deletes one, on purpose, through the database the way a delete action would.
        $deletedCode = (string) $connection->fetchOne('SELECT code FROM inventory_adjustment_reason ORDER BY id ASC LIMIT 1');
        $I->assertNotSame('', $deletedCode);
        $connection->executeStatement('DELETE FROM inventory_adjustment_reason WHERE code = ?', [$deletedCode]);
        $I->assertSame($seededReasons - 1, $this->countRows($connection, 'inventory_adjustment_reason'), 'the delete took effect');

        // A later run, and a later login. Neither may put it back.
        $seeder->run();
        $this->logInThroughTheRealForm($I, 'seed-deletion-second@example.test');

        $I->assertSame(
            $seededReasons - 1,
            $this->countRows($connection, 'inventory_adjustment_reason'),
            'inventory_adjustment_reason after a later run — a deleted row came back',
        );
        $I->assertSame(
            0,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM inventory_adjustment_reason WHERE code = ?', [$deletedCode]),
            'the specific reason the customer deleted is still gone',
        );
    }

    /**
     * The same rule for a list emptied completely, which is the case a row count cannot tell apart
     * from "never seeded" and only the mark can.
     */
    public function aListEmptiedCompletelyStaysEmpty(FunctionalTester $I): void
    {
        $connection = $this->connection($I);
        $seeder = $I->grabService(ReferenceDataSeeder::class);

        $this->logInThroughTheRealForm($I, 'seed-emptied@example.test');
        $I->assertSame(4, $this->countRows($connection, 'unit_of_measure'), 'seeded to begin with');

        $connection->executeStatement('DELETE FROM unit_of_measure');
        $seeder->run();

        $I->assertSame(0, $this->countRows($connection, 'unit_of_measure'), 'an emptied list is not re-seeded');
        $I->assertSame(
            1,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM reference_data_seed_mark WHERE seeder_key = ?', ['core.unit_of_measure']),
            'and its mark is still there, which is what says the emptiness was deliberate',
        );
    }

    // ---------------------------------------------------------------------------------------
    // (4) A bundle installed later seeds; the bundles already marked write nothing.
    // ---------------------------------------------------------------------------------------

    /**
     * The per-bundle mark, which a single global "we have seeded" flag would get wrong.
     *
     * The two runs use the real {@see ReferenceDataSeeder} against the real EntityManager, the real
     * marks table and real `tracking_policy` rows; the only thing supplied by hand is WHICH bundles
     * are installed on each run, because that is the variable under test and a container-registered
     * service is present on every run by definition.
     */
    public function aBundleInstalledLaterSeedsWhileTheOnesAlreadyMarkedWriteNothing(FunctionalTester $I): void
    {
        $connection = $this->connection($I);
        $em = $I->grabService(EntityManagerInterface::class);

        $unitSeeder = $I->grabService(UnitOfMeasureSeeder::class);
        $policySeeder = $I->grabService(TrackingPolicySeeder::class);
        $lateSeeder = new LateBundleReferenceDataSeeder($em);

        // Year one: only the bundles that exist today.
        $yearOne = $this->buildSeeder($I, [$unitSeeder, $policySeeder]);
        foreach ($yearOne->run() as $outcome) {
            $I->assertSame(ReferenceDataSeedOutcome::STATE_SEEDED, $outcome->state, $outcome->key . ' on the first run');
        }

        $unitIds = $this->rowIds($connection, 'unit_of_measure');
        $policyIds = $this->rowIds($connection, 'tracking_policy');
        $I->assertSame(4, \count($unitIds), 'unit_of_measure after year one');
        $I->assertSame(1, \count($policyIds), 'tracking_policy after year one');

        // Year two: a new bundle arrives. The system has obviously had a first login long ago.
        $yearTwo = $this->buildSeeder($I, [$unitSeeder, $policySeeder, $lateSeeder]);
        $states = [];
        foreach ($yearTwo->run() as $outcome) {
            $states[$outcome->key] = $outcome->state;
        }

        $I->assertSame(ReferenceDataSeedOutcome::STATE_SEEDED, $states[LateBundleReferenceDataSeeder::KEY] ?? '', 'the late bundle seeded');
        $I->assertSame(ReferenceDataSeedOutcome::STATE_ALREADY_SEEDED, $states['core.unit_of_measure'] ?? '', 'units were skipped');
        $I->assertSame(ReferenceDataSeedOutcome::STATE_ALREADY_SEEDED, $states['core.tracking_policy'] ?? '', 'tracking policies were skipped');

        // The late bundle's own rows landed...
        $I->assertSame(
            \count(LateBundleReferenceDataSeeder::POLICY_NAMES),
            (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM tracking_policy WHERE name IN (?, ?)',
                LateBundleReferenceDataSeeder::POLICY_NAMES,
            ),
            'the late bundle wrote its rows',
        );

        // ...and nothing the already-marked bundles own moved. Ids, not counts: a delete-and-recreate
        // keeps the count and changes the id.
        $I->assertSame($unitIds, $this->rowIds($connection, 'unit_of_measure'), 'unit_of_measure ids after the late bundle seeded');
        $I->assertSame(
            $policyIds,
            $this->rowIds($connection, 'tracking_policy', 'name NOT IN (\'' . implode("','", LateBundleReferenceDataSeeder::POLICY_NAMES) . '\')'),
            'the pre-existing tracking_policy ids after the late bundle seeded',
        );
    }

    // ---------------------------------------------------------------------------------------
    // (5) A seeder that throws does not break login, and is retried.
    // ---------------------------------------------------------------------------------------

    /**
     * Login is worth more than any reference list. A bundle whose seeder throws must cost the
     * administrator nothing.
     *
     * Without the subscriber's containment (and the seeder's per-seeder try/catch) this fails as a
     * 500 on the login POST, i.e.:
     *   Failed asserting that 500 matches expected 302.
     */
    public function aSeederThatThrowsDoesNotBreakLoginAndIsRetried(FunctionalTester $I): void
    {
        $connection = $this->connection($I);
        ThrowingReferenceDataSeeder::failOnNextRun();

        $this->logInThroughTheRealForm($I, 'seed-throwing@example.test');

        // The admin got in. Positive control: a page behind the firewall renders for them, and it is
        // not the login screen wearing a 200.
        $I->amOnPage('/admin');
        $I->seeResponseCodeIs(200);
        $I->assertStringNotContainsString('Sign in to your account', $I->grabPageSource(), 'the admin is actually signed in');

        // Every other bundle still seeded.
        $I->assertSame(4, $this->countRows($connection, 'unit_of_measure'), 'units seeded despite a sibling throwing');
        $I->assertSame(1, $this->countRows($connection, 'tracking_policy'), 'tracking policies seeded despite a sibling throwing');

        // The thrower is NOT marked, so it will be tried again rather than recorded as done.
        $I->assertSame(
            0,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM reference_data_seed_mark WHERE seeder_key = ?', [ThrowingReferenceDataSeeder::KEY]),
            'a seeder that threw must not be marked done',
        );
        $I->assertGreaterThan(0, ThrowingReferenceDataSeeder::callCount(), 'positive control: it really was called');

        // Next login, with the fault cleared: it is retried, succeeds, and marks itself.
        ThrowingReferenceDataSeeder::reset();
        $this->logInThroughTheRealForm($I, 'seed-throwing-retry@example.test');

        $I->assertSame(
            1,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM reference_data_seed_mark WHERE seeder_key = ?', [ThrowingReferenceDataSeeder::KEY]),
            'the retry marked it',
        );
    }

    // ---------------------------------------------------------------------------------------
    // (6) Concurrency: the unique index, not the check, is what stops a duplicate.
    // ---------------------------------------------------------------------------------------

    /**
     * Two simultaneous logins both read an empty marks table, so the check-then-insert both of them
     * pass is not the protection. This asserts the protection that IS real: the database refuses the
     * second mark.
     *
     * Simulated rather than raced, because two PHP processes cannot share one Codeception
     * transaction — but what is simulated is only the interleaving. The INSERT, the index and the
     * exception are the production ones, and the seeder claims the mark inside the same transaction
     * as the rows, so the refusal takes the rows with it.
     */
    public function theUniqueIndexIsWhatStopsTwoSimultaneousLoginsSeedingTwice(FunctionalTester $I): void
    {
        $connection = $this->connection($I);

        $connection->insert('reference_data_seed_mark', [
            'seeder_key' => 'core.unit_of_measure',
            'rows_created' => 4,
            'seeded_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $refused = false;
        try {
            $connection->insert('reference_data_seed_mark', [
                'seeder_key' => 'core.unit_of_measure',
                'rows_created' => 4,
                'seeded_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            $refused = true;
        }

        $I->assertTrue($refused, 'a second mark for the same seeder key must be refused by the index');
        $I->assertSame(
            1,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM reference_data_seed_mark WHERE seeder_key = ?', ['core.unit_of_measure']),
            'exactly one mark survived',
        );

        // Positive control: a DIFFERENT key inserts happily, so the refusal above was the index
        // doing its job and not the table refusing everything.
        $connection->insert('reference_data_seed_mark', [
            'seeder_key' => 'core.tracking_policy',
            'rows_created' => 1,
            'seeded_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $I->assertSame(2, $this->countRows($connection, 'reference_data_seed_mark'), 'two distinct keys, two marks');
    }

    /**
     * The losing side of a real race writes NO rows, because the claim is inside the transaction
     * that writes them.
     *
     * This is the case the mark short-circuit cannot cover and the previous assertion does not
     * reach: `seededKeys()` is read ONCE at the top of a run, so a mark that appears after that read
     * is invisible to it and the loser goes all the way to its INSERT. `RaceWinnerReferenceDataSeeder`
     * opens that exact window — it sorts ahead of `core.unit_of_measure`, so it runs first and claims
     * the units key from inside the same run, standing in for the other admin's committed write.
     *
     * What must then be true is not merely "no duplicate mark": the loser must leave NO UNITS EITHER.
     * A claim taken after the work, or outside a transaction, would leave four orphan rows behind.
     */
    public function theLoserOfARaceLeavesNoRowsBehind(FunctionalTester $I): void
    {
        $connection = $this->connection($I);
        $em = $I->grabService(EntityManagerInterface::class);

        $racer = new RaceWinnerReferenceDataSeeder($em, 'core.unit_of_measure');
        $unitSeeder = $I->grabService(UnitOfMeasureSeeder::class);

        $states = [];
        foreach ($this->buildSeeder($I, [$racer, $unitSeeder])->run() as $outcome) {
            $states[$outcome->key] = $outcome->state;
        }

        $I->assertSame(
            ReferenceDataSeedOutcome::STATE_CLAIMED_ELSEWHERE,
            $states['core.unit_of_measure'] ?? '',
            'the loser must discover the race at its INSERT, not at the read',
        );
        $I->assertSame(0, $this->countRows($connection, 'unit_of_measure'), 'the loser wrote no rows');
        $I->assertSame(
            1,
            (int) $connection->fetchOne('SELECT COUNT(*) FROM reference_data_seed_mark WHERE seeder_key = ?', ['core.unit_of_measure']),
            'exactly one mark for the contested key',
        );

        // Positive control on the same run: the racer itself was not obstructed, so the loss above
        // was the unique index and not the whole run failing.
        $I->assertSame(ReferenceDataSeedOutcome::STATE_SEEDED, $states[RaceWinnerReferenceDataSeeder::KEY] ?? '');
    }

    // ---------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------

    /**
     * A real, conducted admin login: GET the login page, scrape its CSRF token, POST the form.
     *
     * This is the only way to fire `LoginSuccessEvent`, which is the trigger under test.
     */
    private function logInThroughTheRealForm(FunctionalTester $I, string $email): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);
        $admin = (new AdminUser())->setEmail($email);
        $admin->setPassword($hasher->hashPassword($admin, self::PASSWORD));
        $I->haveInRepository($admin);

        $I->haveHttpHeader('Host', 'admin.localhost');

        // A second person, at a second browser. Without this a test method that logs in twice is
        // redirected straight to /admin by AuthController's already-authenticated guard and there is
        // no form to scrape a token from — see startAFreshBrowserSession() for why /admin/logout is
        // the wrong way to get here.
        $I->startAFreshBrowserSession();

        $I->amOnPage('/admin/login');
        $I->seeResponseCodeIs(200);
        $token = $I->grabAttributeFrom('input[name="_csrf_token"]', 'value');

        $I->sendFormPostRequest('/admin/login', [
            '_csrf_token' => $token,
            'email' => $email,
            'password' => self::PASSWORD,
        ]);

        // Asserted on every single login in this file, not just the throwing case: seeding happens
        // in a LoginSuccessEvent listener, and an exception escaping one of those is a 500 on the
        // login POST — i.e. the admin cannot get in. Nothing this change does is worth that.
        $I->seeResponseCodeIsSuccessful();
    }

    /**
     * The production {@see ReferenceDataSeeder} with a chosen set of seeders.
     *
     * Every collaborator but the seeder list is the container's own, so the marks table, the
     * transaction, the claim and the unique index are all the real ones. Only "which bundles are
     * installed" is supplied, because that is what has to differ between two runs in one process.
     *
     * @param list<\App\Contract\ReferenceData\ReferenceDataSeederInterface> $seeders
     */
    private function buildSeeder(FunctionalTester $I, array $seeders): ReferenceDataSeeder
    {
        return new ReferenceDataSeeder(
            $seeders,
            $I->grabService(EntityManagerInterface::class),
            $I->grabService(ReferenceDataSeedMarkRepository::class),
            $I->grabService('doctrine'),
            $I->grabService(LoggerInterface::class),
        );
    }

    private function connection(FunctionalTester $I): Connection
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection();
    }

    private function countRows(Connection $connection, string $table): int
    {
        return (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    /**
     * Every table this change can touch, counted in one place so a test asserts the whole surface
     * rather than the one table it happened to be thinking about.
     *
     * @return array<string, int>
     */
    private function allCounts(Connection $connection): array
    {
        $counts = [];
        foreach (['unit_of_measure', 'tracking_policy', 'inventory_adjustment_reason', 'fee', 'sales_tax', 'fulfillment_region', 'reference_data_seed_mark'] as $table) {
            $counts[$table] = $this->countRows($connection, $table);
        }

        return $counts;
    }

    /** @return array<string, list<int>> */
    private function allRowIds(Connection $connection): array
    {
        $ids = [];
        foreach (['unit_of_measure', 'tracking_policy', 'inventory_adjustment_reason', 'fee', 'sales_tax', 'fulfillment_region'] as $table) {
            $ids[$table] = $this->rowIds($connection, $table);
        }

        return $ids;
    }

    /** @return list<int> */
    private function rowIds(Connection $connection, string $table, string $where = '1=1'): array
    {
        return array_map(
            'intval',
            $connection->fetchFirstColumn('SELECT id FROM ' . $table . ' WHERE ' . $where . ' ORDER BY id ASC'),
        );
    }

    /** @return list<string> */
    private function markKeys(Connection $connection): array
    {
        return array_map('strval', $connection->fetchFirstColumn('SELECT seeder_key FROM reference_data_seed_mark ORDER BY seeder_key ASC'));
    }

    /** @return list<array<string, mixed>> */
    private function markRows(Connection $connection): array
    {
        return $connection->fetchAllAssociative('SELECT id, seeder_key, rows_created, seeded_at FROM reference_data_seed_mark ORDER BY seeder_key ASC');
    }
}
