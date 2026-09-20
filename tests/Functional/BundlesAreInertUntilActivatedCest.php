<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Bundle\InstalledBundleDirectory;
use App\Command\Bundle\BundleActivateCommand;
use App\Command\Bundle\BundleDeactivateCommand;
use App\Entity\AdminUser;
use App\Entity\BundleStatus;
use App\Repository\BundleStatusRepository;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryAdjustmentReason;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Tests\Support\FunctionalTester;

// migrations/ is deliberately outside the autoloader (config/packages/doctrine_migrations.yaml
// says so: "migrations classes should NOT be autoloaded"). The upgrade step is the subject of two
// of the cases below, so it is required by hand and then run as itself — the real class, its real
// SQL, against the real connection. Re-implementing its INSERT in the test would prove only that
// the test can write rows.
require_once dirname(__DIR__, 2) . '/migrations/Version20260916104500.php';

/**
 * A bundle present on disk is INERT until a person activates it, and the upgrade that made that
 * safe for databases which predate the rule.
 *
 * ## What changed
 *
 * `BundleStatusRepository::isActive()` used to answer true for a bundle with no status row. Absence
 * meant enabled, so a bundle worked from the moment its folder appeared. It now answers false, and
 * only an explicit `Active` row switches one on.
 *
 * The dangerous half is not the new rule, it is every database that already exists. None of them
 * has rows — that WAS the enabled state — so the flip on its own takes procurement, tax, fees,
 * shipping, inventory depth and barcodes dark simultaneously. `Version20260916104500` writes the
 * rows that were previously implicit, and {@see self::theUpgradeStepKeepsEveryPreviouslyEnabledBundleActive()}
 * is the case that matters most: it starts from a database with NO rows, shows the application dark,
 * runs the upgrade, and shows it lit again.
 *
 * ## How these assert
 *
 * By COLUMN, after every POST — `bundle_status.status` read back out of the database, never the
 * badge rendered on the page (#624). Where a screen IS the subject, the assertion anchors to a
 * specific element id and pairs the absence with a positive control on the SAME element (#627), so
 * a renamed class or a vanished row cannot pass as a pass.
 *
 * ## State on $this
 *
 * There is none, on purpose. Codeception reuses one Cest instance for every method in the file, so
 * anything cached on `$this` leaks between cases; every method here reads what it needs from the
 * container. The database is reset by the Doctrine module's per-test rollback, which returns it to
 * the committed baseline `tests/_bootstrap.php` leaves behind — every installed bundle Active, via
 * `app:bundle:activate --all-present`.
 */
final class BundlesAreInertUntilActivatedCest
{
    /**
     * Two bundles that gate their own admin screens on `isActive()` and 404 when off.
     *
     * Barcodes is the subject and Inventory Depth is the control. Both are outside the areas other
     * workers are live in tonight, and both answer the question with an HTTP status rather than a
     * rendered string, which is what makes "this bundle went dark" assertable without reading text.
     */
    private const SUBJECT_SOURCE = 'BarcodeBundle';
    private const SUBJECT_ROUTE = '/admin/bundles/barcodes';

    private const CONTROL_SOURCE = 'InventoryDepthBundle';
    private const CONTROL_ROUTE = '/admin/bundles/inventory-depth/bins';

    private const PASSWORD = 'test-password-123';

    // ----------------------------------------------------------------- case 1

    /**
     * THE upgrade case. A database with no `bundle_status` rows at all — today's "everything
     * enabled" shape — must come out of the upgrade with every previously-enabled bundle still on.
     *
     * Asserted twice over, because a count alone would pass on rows nothing reads: the number of
     * Active rows is compared against the number of installed modules, AND a screen belonging to a
     * bundle that would otherwise have gone dark is driven and has to answer.
     *
     * The dark state is asserted BEFORE the upgrade rather than assumed. That half is what fails,
     * loudly, if the upgrade step is ever removed from the chain.
     */
    public function theUpgradeStepKeepsEveryPreviouslyEnabledBundleActive(FunctionalTester $I): void
    {
        $this->loginAsTechSupport($I, 'techsupport-bundles-upgrade@example.test');

        $em = $I->grabService(EntityManagerInterface::class);
        $installed = $I->grabService(InstalledBundleDirectory::class)->sources();

        // The pre-flip shape: not one row anywhere, which under the old rule meant every bundle on.
        $this->deleteEveryStatusRow($I);
        $I->assertSame(0, $this->statusRowCount($I), 'the upgrade case must start from a database with no bundle_status rows');

        // The app IS dark at this point, and that is asserted, not assumed — this is the state the
        // upgrade exists to prevent an installation ever being left in.
        $I->amOnPage(self::SUBJECT_ROUTE);
        $I->seeResponseCodeIs(404);
        $I->amOnPage(self::CONTROL_ROUTE);
        $I->seeResponseCodeIs(404);

        $this->runUpgradeMigration($I);

        // Every installed module now carries an explicit Active row. Compared against the installed
        // set rather than a hard-coded 40, so adding a module to modules/ cannot silently shrink
        // what this covers.
        $activeSources = $this->sourcesWithStatus($I, BundleStatus::STATUS_ACTIVE);
        $I->assertSame(
            $installed,
            array_values(array_intersect($installed, $activeSources)),
            'every installed bundle must hold an Active row after the upgrade step',
        );
        $I->assertGreaterThan(0, count($installed), 'the installed bundle list must not be empty, or this case asserts nothing');

        // ...and by column, for the two this case then drives.
        $I->seeInRepository(BundleStatus::class, ['source' => self::SUBJECT_SOURCE, 'status' => BundleStatus::STATUS_ACTIVE]);
        $I->seeInRepository(BundleStatus::class, ['source' => self::CONTROL_SOURCE, 'status' => BundleStatus::STATUS_ACTIVE]);

        $em->clear();

        // The half a row count cannot prove: a screen from a bundle that would otherwise have gone
        // dark actually serves.
        $I->amOnPage(self::SUBJECT_ROUTE);
        $I->seeResponseCodeIsSuccessful();
        $I->amOnPage(self::CONTROL_ROUTE);
        $I->seeResponseCodeIsSuccessful();
    }

    // ----------------------------------------------------------------- case 2

    /**
     * A bundle installed on disk, with no row and no upgrade behind it, is INACTIVE and its
     * features are not reachable.
     *
     * The absence of the subject's screen is paired with the presence of the control's, so a
     * suite-wide breakage that 404s everything cannot pass this.
     */
    public function aBundleWithNoRowIsInactiveAndItsScreensAreUnreachable(FunctionalTester $I): void
    {
        $this->loginAsTechSupport($I, 'techsupport-bundles-norow@example.test');

        $this->deleteStatusRow($I, self::SUBJECT_SOURCE);

        // By column: the subject has no row; the control still has its Active one.
        $I->dontSeeInRepository(BundleStatus::class, ['source' => self::SUBJECT_SOURCE]);
        $I->seeInRepository(BundleStatus::class, ['source' => self::CONTROL_SOURCE, 'status' => BundleStatus::STATUS_ACTIVE]);

        $I->amOnPage(self::SUBJECT_ROUTE);
        $I->seeResponseCodeIs(404);

        $I->amOnPage(self::CONTROL_ROUTE);
        $I->seeResponseCodeIsSuccessful();

        // And the management screen says which of the three states it is in. Positive and negative
        // on the SAME element id, so a row that stopped rendering fails both halves.
        $I->amOnPage('/admin/bundle-management');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#bundle-state-' . self::SUBJECT_SOURCE . '.badge.warn');
        $I->dontSeeElement('#bundle-state-' . self::SUBJECT_SOURCE . '.badge.success');
        $I->dontSeeElement('#bundle-state-' . self::SUBJECT_SOURCE . '.badge.danger');
    }

    /**
     * Rendering App Management must not switch anything on.
     *
     * It used to: `index()` called `ensureBySource()` once per descriptor, so opening a read-only
     * listing created an Active row for every app that did not have one, and "never activated"
     * could not survive a single page view.
     */
    public function openingTheManagementScreenActivatesNothing(FunctionalTester $I): void
    {
        $this->loginAsTechSupport($I, 'techsupport-bundles-noside@example.test');

        $this->deleteStatusRow($I, self::SUBJECT_SOURCE);
        $countBefore = $this->statusRowCount($I);

        $I->amOnPage('/admin/bundle-management');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#bundle-row-' . self::SUBJECT_SOURCE);

        $I->grabService(EntityManagerInterface::class)->clear();

        $I->dontSeeInRepository(BundleStatus::class, ['source' => self::SUBJECT_SOURCE]);
        $I->assertSame($countBefore, $this->statusRowCount($I), 'rendering App Management must write no bundle_status rows');

        $I->amOnPage(self::SUBJECT_ROUTE);
        $I->seeResponseCodeIs(404);
    }

    // ----------------------------------------------------------------- case 3

    /**
     * Pressing Activate on the real screen, as a plain form POST with the token scraped off the
     * page, turns a never-activated bundle on. Asserted by column, then by the screen it unlocks.
     */
    public function pressingActivateOnTheScreenTurnsABundleOn(FunctionalTester $I): void
    {
        $this->loginAsTechSupport($I, 'techsupport-bundles-activate@example.test');

        $this->deleteStatusRow($I, self::SUBJECT_SOURCE);

        $I->amOnPage('/admin/bundle-management');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#bundle-state-' . self::SUBJECT_SOURCE . '.badge.warn');
        $I->dontSeeElement('#bundle-state-' . self::SUBJECT_SOURCE . '.badge.success');

        $token = $I->grabAttributeFrom(
            'form[action$="/' . self::SUBJECT_SOURCE . '/toggle"] input[name="_token"]',
            'value',
        );
        $I->assertNotSame('', $token, 'without a real token this POST would be measuring CSRF, not activation');

        $I->sendFormPostRequest('/admin/bundle-management/' . self::SUBJECT_SOURCE . '/toggle', ['_token' => $token]);

        // THE assertion: the column, read back, not the flash and not the badge.
        $I->grabService(EntityManagerInterface::class)->clear();
        $I->seeInRepository(BundleStatus::class, ['source' => self::SUBJECT_SOURCE, 'status' => BundleStatus::STATUS_ACTIVE]);

        $I->amOnPage('/admin/bundle-management');
        $I->seeElement('#bundle-state-' . self::SUBJECT_SOURCE . '.badge.success');
        $I->dontSeeElement('#bundle-state-' . self::SUBJECT_SOURCE . '.badge.warn');

        $I->amOnPage(self::SUBJECT_ROUTE);
        $I->seeResponseCodeIsSuccessful();
    }

    // ----------------------------------------------------------------- case 4

    /**
     * Deactivate, then reactivate, and nothing is deleted in between — not the status row, and not
     * one row of the data the bundle owns.
     *
     * The reference rows Inventory Depth ships are counted before, between and after. Their
     * survival is the whole promise of "off means the gates answer false": switching back on needs
     * no recount, no re-import and no manual step.
     *
     * The status row's own id is asserted stable too, which is how "updated" is told apart from
     * "deleted and written again" — a distinction no status column can show.
     */
    public function deactivateThenReactivateWorksAndDeletesNothing(FunctionalTester $I): void
    {
        $this->loginAsTechSupport($I, 'techsupport-bundles-roundtrip@example.test');

        // Real shipped reference data, created by the real seeder, so the rows being counted are
        // rows the bundle genuinely owns rather than fixtures this test invented.
        $I->haveSeededReferenceData();

        $em = $I->grabService(EntityManagerInterface::class);
        $repo = $I->grabService(BundleStatusRepository::class);

        $reasonsBefore = $this->countReasons($I);
        $I->assertGreaterThan(0, $reasonsBefore, 'the bundle must own some data, or "deletes nothing" asserts nothing');

        $rowIdBefore = $repo->findBySource(self::CONTROL_SOURCE)?->getId();
        $I->assertNotNull($rowIdBefore, 'the control bundle must start with a status row');

        $I->amOnPage(self::CONTROL_ROUTE);
        $I->seeResponseCodeIsSuccessful();

        // --- off ---
        $this->pressToggle($I, self::CONTROL_SOURCE);

        $em->clear();
        $I->seeInRepository(BundleStatus::class, ['source' => self::CONTROL_SOURCE, 'status' => BundleStatus::STATUS_INACTIVE]);
        $I->assertSame($reasonsBefore, $this->countReasons($I), 'deactivating must not delete the bundle\'s own rows');
        $I->assertSame($rowIdBefore, $repo->findBySource(self::CONTROL_SOURCE)?->getId(), 'the status row must be updated, not replaced');

        $I->amOnPage(self::CONTROL_ROUTE);
        $I->seeResponseCodeIs(404);

        // --- and back on ---
        $this->pressToggle($I, self::CONTROL_SOURCE);

        $em->clear();
        $I->seeInRepository(BundleStatus::class, ['source' => self::CONTROL_SOURCE, 'status' => BundleStatus::STATUS_ACTIVE]);
        $I->assertSame($reasonsBefore, $this->countReasons($I), 'reactivating must not have needed the rows recreated');
        $I->assertSame($rowIdBefore, $repo->findBySource(self::CONTROL_SOURCE)?->getId(), 'the status row must be updated, not replaced');

        $I->amOnPage(self::CONTROL_ROUTE);
        $I->seeResponseCodeIsSuccessful();
    }

    // ----------------------------------------------------------------- case 5

    /**
     * An explicitly Inactive bundle survives the upgrade step switched OFF.
     *
     * Somebody turning a bundle off is a decision on the record, and an upgrade that sweeps it back
     * on would be the upgrade overruling a person. `WHERE NOT EXISTS` in the migration is what
     * enforces this; a migration written as an unconditional INSERT OR REPLACE would pass every
     * other case in this file and fail only this one.
     */
    public function anExplicitlyInactiveBundleIsNotSweptUpByTheUpgradeStep(FunctionalTester $I): void
    {
        $this->loginAsTechSupport($I, 'techsupport-bundles-inactive-survives@example.test');

        // The pre-upgrade shape, with one deliberate decision already made: no rows anywhere except
        // a single explicit Inactive.
        $this->deleteEveryStatusRow($I);
        $I->grabService(BundleStatusRepository::class)->deactivate(self::SUBJECT_SOURCE);
        $I->seeInRepository(BundleStatus::class, ['source' => self::SUBJECT_SOURCE, 'status' => BundleStatus::STATUS_INACTIVE]);

        $this->runUpgradeMigration($I);

        $I->grabService(EntityManagerInterface::class)->clear();

        // The decision stands...
        $I->seeInRepository(BundleStatus::class, ['source' => self::SUBJECT_SOURCE, 'status' => BundleStatus::STATUS_INACTIVE]);
        $I->dontSeeInRepository(BundleStatus::class, ['source' => self::SUBJECT_SOURCE, 'status' => BundleStatus::STATUS_ACTIVE]);
        // ...while everything that had made no decision was switched on, which is the positive
        // control that proves the upgrade actually ran over this database.
        $I->seeInRepository(BundleStatus::class, ['source' => self::CONTROL_SOURCE, 'status' => BundleStatus::STATUS_ACTIVE]);

        $I->amOnPage(self::SUBJECT_ROUTE);
        $I->seeResponseCodeIs(404);
        $I->amOnPage(self::CONTROL_ROUTE);
        $I->seeResponseCodeIsSuccessful();

        $I->amOnPage('/admin/bundle-management');
        $I->seeElement('#bundle-state-' . self::SUBJECT_SOURCE . '.badge.danger');
        $I->dontSeeElement('#bundle-state-' . self::SUBJECT_SOURCE . '.badge.success');
        $I->dontSeeElement('#bundle-state-' . self::SUBJECT_SOURCE . '.badge.warn');
    }

    // ----------------------------------------------------------------- case 6

    /**
     * The console reaches the same columns as the button, with nobody logged in.
     *
     * Two things at once, and both matter. The state assertions are deliberately the SAME ones
     * {@see self::pressingActivateOnTheScreenTurnsABundleOn()} and
     * {@see self::deactivateThenReactivateWorksAndDeletesNothing()} make after a form POST — one
     * activation path, so one set of columns.
     *
     * And nothing here logs in. No admin is created, no session exists, no request is made before
     * the commands run. That is what makes the console a real escape hatch: if somebody deactivates
     * whatever the admin UI needs, or no Tech Support account can get in, this still works.
     */
    public function theConsoleCommandReachesTheSameColumnsAsTheScreen(FunctionalTester $I): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $repo = $I->grabService(BundleStatusRepository::class);

        // No row at all — the state a freshly installed bundle is in.
        $this->deleteStatusRow($I, self::SUBJECT_SOURCE);
        $I->dontSeeInRepository(BundleStatus::class, ['source' => self::SUBJECT_SOURCE]);

        // --- activate, from the shell, with no user and no request ---
        $activate = new CommandTester($I->grabService(BundleActivateCommand::class));
        $activate->execute(['source' => self::SUBJECT_SOURCE]);
        $I->assertSame(0, $activate->getStatusCode(), 'app:bundle:activate must succeed: ' . $activate->getDisplay());

        $em->clear();
        $I->seeInRepository(BundleStatus::class, ['source' => self::SUBJECT_SOURCE, 'status' => BundleStatus::STATUS_ACTIVE]);

        $rowIdAfterActivate = $repo->findBySource(self::SUBJECT_SOURCE)?->getId();
        $I->assertNotNull($rowIdAfterActivate, 'activating from the console must create the row');

        // --- deactivate, from the shell ---
        $deactivate = new CommandTester($I->grabService(BundleDeactivateCommand::class));
        $deactivate->execute(['source' => self::SUBJECT_SOURCE]);
        $I->assertSame(0, $deactivate->getStatusCode(), 'app:bundle:deactivate must succeed: ' . $deactivate->getDisplay());

        $em->clear();
        $I->seeInRepository(BundleStatus::class, ['source' => self::SUBJECT_SOURCE, 'status' => BundleStatus::STATUS_INACTIVE]);
        $I->assertSame(
            $rowIdAfterActivate,
            $repo->findBySource(self::SUBJECT_SOURCE)?->getId(),
            'deactivating from the console must update the row, not delete and rewrite it',
        );

        // A source that is not installed is refused rather than written, so a typo cannot report
        // success for a bundle that is not there.
        $typo = new CommandTester($I->grabService(BundleActivateCommand::class));
        $typo->execute(['source' => 'NotARealBundle']);
        $I->assertSame(1, $typo->getStatusCode(), 'activating an uninstalled source must fail');
        $I->dontSeeInRepository(BundleStatus::class, ['source' => 'NotARealBundle']);
    }

    /**
     * The two entry points agree: a bundle switched on from the console is on for the screens, and
     * one switched off from the console is off for them.
     *
     * The previous case proves the console writes the same columns. This one proves the application
     * reads them — the same gates, reaching the same answer, whichever entry point wrote the row.
     */
    public function whatTheConsoleWritesIsWhatTheScreensRead(FunctionalTester $I): void
    {
        $this->loginAsTechSupport($I, 'techsupport-bundles-console-read@example.test');

        $deactivate = new CommandTester($I->grabService(BundleDeactivateCommand::class));
        $deactivate->execute(['source' => self::SUBJECT_SOURCE]);

        $I->grabService(EntityManagerInterface::class)->clear();
        $I->seeInRepository(BundleStatus::class, ['source' => self::SUBJECT_SOURCE, 'status' => BundleStatus::STATUS_INACTIVE]);

        $I->amOnPage(self::SUBJECT_ROUTE);
        $I->seeResponseCodeIs(404);
        $I->amOnPage(self::CONTROL_ROUTE);
        $I->seeResponseCodeIsSuccessful();

        $I->amOnPage('/admin/bundle-management');
        $I->seeElement('#bundle-state-' . self::SUBJECT_SOURCE . '.badge.danger');
        $I->dontSeeElement('#bundle-state-' . self::SUBJECT_SOURCE . '.badge.success');

        $activate = new CommandTester($I->grabService(BundleActivateCommand::class));
        $activate->execute(['source' => self::SUBJECT_SOURCE]);

        $I->grabService(EntityManagerInterface::class)->clear();
        $I->seeInRepository(BundleStatus::class, ['source' => self::SUBJECT_SOURCE, 'status' => BundleStatus::STATUS_ACTIVE]);

        $I->amOnPage(self::SUBJECT_ROUTE);
        $I->seeResponseCodeIsSuccessful();

        $I->amOnPage('/admin/bundle-management');
        $I->seeElement('#bundle-state-' . self::SUBJECT_SOURCE . '.badge.success');
        $I->dontSeeElement('#bundle-state-' . self::SUBJECT_SOURCE . '.badge.danger');
    }

    // ----------------------------------------------------------------- core is not a bundle

    /**
     * The flip must not take CORE dark, and this is the one place it nearly did.
     *
     * `isActiveForInstance()` derives a source from an object's root namespace segment. Four core
     * services are registered on `app.frontend_menu_item` alongside the bundles' —
     * `App\Menu\Core\ContactMenuItem`, `FaqMenuItem`, `MyOrdersMenuItem`, `MyQuotesMenuItem` — and
     * they give `App`. Under the old default `App` had no row and answered true, so nobody noticed.
     * Under the new one it would answer false and the four core entries would vanish from the
     * customer-facing nav: core going dark, not a bundle, and no screen anywhere could switch it
     * back on because `App` has no descriptor and no row to write.
     *
     * {@see BundleStatusRepository::CORE_SOURCE} is what stops it.
     *
     * Nothing in the existing suite covered this. `tests/Twig/FrontendMenuExtensionTest` stubs
     * `isActiveForInstance()` to return true unconditionally, so it would have passed with the
     * exemption removed and the storefront nav half empty.
     *
     * The pairing is inside ONE element, `#primary-navigation`: the core links must be there while
     * a BUNDLE-contributed link in the same nav is absent, with that bundle deactivated. A nav that
     * failed to render at all fails the positive half; a gate that stopped working fails the
     * negative half.
     */
    public function coreMenuItemsAreNotGatedOnABundleThatCannotExist(FunctionalTester $I): void
    {
        $repo = $I->grabService(BundleStatusRepository::class);

        // 'App' has no row and never will — nothing can create one.
        $I->dontSeeInRepository(BundleStatus::class, ['source' => BundleStatusRepository::CORE_SOURCE]);
        $I->assertTrue(
            $repo->isActive(BundleStatusRepository::CORE_SOURCE),
            'core is not a bundle and must never be gated on having been activated',
        );

        // The bundle whose menu items share that nav, switched off through the shared path.
        $repo->deactivate('Number1CategoryProductPageBundle');
        $I->grabService(EntityManagerInterface::class)->clear();
        $I->seeInRepository(BundleStatus::class, [
            'source' => 'Number1CategoryProductPageBundle',
            'status' => BundleStatus::STATUS_INACTIVE,
        ]);

        $I->haveHttpHeader('Host', '127.0.0.1');
        $I->amOnPage('/');
        $I->seeResponseCodeIsSuccessful();
        $I->seeElement('#primary-navigation');

        // Core survives...
        $I->seeElement('#primary-navigation a[href="/faq"]');
        $I->seeElement('#primary-navigation a[href="/contact"]');
        $I->seeElement('#primary-navigation a[href="/orders"]');
        $I->seeElement('#primary-navigation a[href="/estimates"]');

        // ...and the deactivated bundle's own entries are gone from that same nav, which is the
        // positive control proving the gate is running rather than the page merely rendering.
        $I->dontSeeElement('#primary-navigation a[href*="category_id"]');
    }

    // ----------------------------------------------------------------- plumbing

    /**
     * Runs the upgrade migration — the real class, its real SQL, against this database's connection.
     *
     * `AbstractMigration::up()` only QUEUES statements; `getSql()` hands back what it queued, which
     * is then executed here the way Doctrine's own executor would. Nothing about the SQL, the
     * parameters or the `WHERE NOT EXISTS` is restated by the test.
     *
     * The schema argument is unused by this migration (it writes rows, adds no structure), so an
     * empty one is honest rather than a stub standing in for something.
     */
    private function runUpgradeMigration(FunctionalTester $I): void
    {
        $connection = $I->grabService(EntityManagerInterface::class)->getConnection();

        $migration = new \DoctrineMigrations\Version20260916104500($connection, new NullLogger());
        $migration->up(new Schema());

        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /**
     * Posts the row's Activate/Deactivate button as a plain form, with the token scraped off the
     * page — and, if deactivating this source would cascade to an Active dependent (#788), follows
     * through the no-JS confirm step exactly as a person clicking twice would, so this stays "press
     * the button" regardless of which source it's pressed for.
     */
    private function pressToggle(FunctionalTester $I, string $source): void
    {
        $I->amOnPage('/admin/bundle-management');
        $I->seeResponseCodeIsSuccessful();

        $token = $I->grabAttributeFrom('form[action$="/' . $source . '/toggle"] input[name="_token"]', 'value');
        $I->assertNotSame('', $token, 'without a real token this POST would be measuring CSRF, not the toggle');

        $I->sendFormPostRequest('/admin/bundle-management/' . $source . '/toggle', ['_token' => $token]);

        if (!str_contains($I->grabPageSource(), 'name="confirmed"')) {
            return;
        }

        $confirmToken = $I->grabAttributeFrom('form[action$="/' . $source . '/toggle"] input[name="_token"]', 'value');
        $I->sendFormPostRequest('/admin/bundle-management/' . $source . '/toggle', ['_token' => $confirmToken, 'confirmed' => '1']);
    }

    /**
     * Logs in through the REAL form.
     *
     * `amLoggedInAs()` installs a token straight into token storage and never runs the
     * authenticator, so no `LoginSuccessEvent` is dispatched — which matters here because that
     * event is what seeds reference data, and case 4 counts rows it creates.
     */
    private function loginAsTechSupport(FunctionalTester $I, string $email): void
    {
        $hasher = $I->grabService(UserPasswordHasherInterface::class);

        $admin = (new AdminUser())->setEmail($email);
        $admin->setRoles(['ROLE_TECH_SUPPORT']);
        $admin->setPassword($hasher->hashPassword($admin, self::PASSWORD));
        $I->haveInRepository($admin);

        $I->haveHttpHeader('Host', 'admin.localhost');

        $I->amOnPage('/admin/login');
        $I->seeResponseCodeIs(200);
        $token = $I->grabAttributeFrom('input[name="_csrf_token"]', 'value');

        $I->sendFormPostRequest('/admin/login', [
            '_csrf_token' => $token,
            'email' => $email,
            'password' => self::PASSWORD,
        ]);
        $I->seeResponseCodeIsSuccessful();
    }

    private function deleteEveryStatusRow(FunctionalTester $I): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM ' . BundleStatus::class . ' b')->execute();
        $em->clear();
    }

    private function deleteStatusRow(FunctionalTester $I, string $source): void
    {
        $em = $I->grabService(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM ' . BundleStatus::class . ' b WHERE b.source = :source')
            ->setParameter('source', $source)
            ->execute();
        $em->clear();
    }

    private function statusRowCount(FunctionalTester $I): int
    {
        return (int) $I->grabService(EntityManagerInterface::class)
            ->createQuery('SELECT COUNT(b.id) FROM ' . BundleStatus::class . ' b')
            ->getSingleScalarResult();
    }

    /** @return list<string> */
    private function sourcesWithStatus(FunctionalTester $I, string $status): array
    {
        $rows = $I->grabService(EntityManagerInterface::class)
            ->createQuery('SELECT b.source FROM ' . BundleStatus::class . ' b WHERE b.status = :status ORDER BY b.source ASC')
            ->setParameter('status', $status)
            ->getScalarResult();

        return array_map(static fn (array $row): string => (string) $row['source'], $rows);
    }

    private function countReasons(FunctionalTester $I): int
    {
        return (int) $I->grabService(EntityManagerInterface::class)
            ->createQuery('SELECT COUNT(r.id) FROM ' . InventoryAdjustmentReason::class . ' r')
            ->getSingleScalarResult();
    }
}
