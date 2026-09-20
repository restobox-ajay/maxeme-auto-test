<?php

declare(strict_types=1);

namespace App\Tests;

use App\Bundle\InstalledBundleDirectory;
use App\Contract\Status\StatusVocabularyLoaderInterface;
use App\Repository\BundleStatusRepository;
use App\Service\RegionSeeder;
use App\Status\StatusVocabularyRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base for tests that need a real Doctrine EntityManager — and, by extension, the app's real
 * registered Doctrine event listeners (e.g. InventoryReconciliationSubscriber), which a
 * mocked EntityManager would bypass entirely. Boots the real kernel/container so bundle-wired
 * listeners fire exactly as they do in production.
 *
 * DATABASE_URL in .env.test points at a dedicated file-based SQLite DB (shared with the
 * Codeception Functional suite — see tests/Functional/_bootstrap.php for why file-based, not
 * :memory:). Each test here deletes that file and rebuilds the schema fresh from current
 * entity metadata via SchemaTool, so every test still gets a clean slate despite the file
 * persisting on disk between runs.
 *
 * "A clean slate" means the CACHES that describe the database too, which is why setUp() empties the
 * app cache pool right after it empties the database — see resetTestSettingsCache().
 */
abstract class DoctrineIntegrationTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();

        self::resetTestDatabaseFile();

        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        // Same reason tests/Support/Helper/Functional.php's _before() primes it for the Codeception
        // suite: StatusVocabularyRegistrySubscriber's own hooks (kernel.request, console.command,
        // Doctrine's preFlush) all assume a request, a command, or a flush has already happened, and
        // a test here routinely builds a fixture and calls a HasStatus entity's setStatus() on it
        // before the first flush of the test ever runs.
        StatusVocabularyRegistry::use(self::getContainer()->get(StatusVocabularyLoaderInterface::class));

        self::resetTestSettingsCache();

        $schemaTool = new SchemaTool($this->em);
        $schemaTool->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        // SchemaTool creates tables but never runs migrations, so the country/province reference
        // rows the seed migration would have inserted are absent. Without this every province
        // validation fails and every address dropdown renders empty.
        self::getContainer()->get(RegionSeeder::class)->seed();

        // Same reason, same shape, different migration: bundles are inert until activated
        // (BundleStatusRepository::isActive()), and the one-time upgrade that writes the Active rows
        // which used to be implicit is Version20260916104500 — a migration this database never runs.
        // Without this line every bundle is switched OFF for every test here, which surfaces not as
        // "the bundle is inactive" but as whatever that bundle's absence happens to look like:
        // TransferDriftCheckCommandTest, for instance, failed nine times over with "Product WIDGET-1
        // is on simple inventory", because InventoryDepthBundle being dark changes what inventory
        // mode a product resolves to.
        //
        // Goes through the same activation method the Activate button and app:bundle:activate use,
        // in one flush, so the test environment cannot drift from what the application does.
        self::getContainer()->get(BundleStatusRepository::class)->activateAll(
            self::getContainer()->get(InstalledBundleDirectory::class)->sources(),
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::ensureKernelShutdown();
    }

    public static function resetTestDatabaseFile(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            $path = dirname(__DIR__) . '/var/data_test.db' . $suffix;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Empties the app cache pool, because the database it describes was just deleted.
     *
     * ## What was wrong
     *
     * Deleting data_test.db is only half a reset. {@see \App\Service\AppSettings} caches the WHOLE
     * app_setting table under one key for an hour, so a test that persists a setting leaves that
     * value readable — by every later test in the run, and by every later run — against a database
     * that no longer contains it. Nothing was clearing it, and the reason it went unnoticed for so
     * long is that it used to be cleared by accident: tests/bootstrap.php removes var/cache/test,
     * and the app pool used to live there.
     *
     * It does not any more. Symfony 7.4 gave the kernel a SHARE dir and pointed framework.cache at
     * it — `directory: %kernel.share_dir%/pools/app`, i.e. var/share/<env>/pools/app — specifically
     * so that application cache SURVIVES a cache clear. So the pool quietly stepped outside the one
     * directory the test bootstrap sweeps, and a stale settings row started outliving the database,
     * the test that wrote it, and the process.
     *
     * The symptom was a pair of failures that read exactly like a money bug.
     * ProductImportServiceTest::testImportUsesConfiguredDefaultSalesTaxCodeWhenColumnAbsent stores
     * default_sales_tax_code = 'E'; from the next run onwards the two tests either side of it —
     * "a missing sales_tax_code column defaults to S", "a blank sales_tax_code cell defaults to S" —
     * read that dead 'E' out of the pool and failed, asserting that imported products land Exempt
     * and silently untaxed. The import has always been right (see #115, and the tests beside those
     * two); the environment was lying to it. A run on a clean checkout passed and every run after
     * it failed, which is the signature of state that outlives the process.
     *
     * Clearing the pool rather than the one settings key on purpose: the reset promise is about the
     * database, and any other cache keyed on database content has exactly the same problem.
     *
     * Not fixed here, and worth knowing: the Codeception Functional suite clears this pool once per
     * RUN (tests/_bootstrap.php) but not per test, and it isolates with transaction rollback — so a
     * setting written and rolled back there is still cached. AdminNumber1ProductImportCest writes
     * this very key.
     */
    protected static function resetTestSettingsCache(): void
    {
        self::getContainer()->get('cache.app')->clear();
    }
}
