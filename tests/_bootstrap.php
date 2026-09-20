<?php

// Global bootstrap, run once before any Codeception suite starts. Codeception has no
// per-suite _bootstrap.php auto-discovery in this version — everything that must happen
// before the Functional suite's Symfony module boots its kernel (env loading, fresh schema)
// lives here.
//
// APP_ENV must be forced to 'test' *before* bootEnv() — Dotenv::bootEnv() decides which
// .env.$APP_ENV file to layer on top of .env by reading $_SERVER['APP_ENV'] at call time, and
// with nothing set yet it falls back to .env's own APP_ENV=dev, silently skipping .env.test
// entirely (including its DATABASE_URL override) — every service in this process would then
// point at the real dev database instead of the throwaway test one. PHPUnit avoids this the
// same way, via phpunit.dist.xml's <server name="APP_ENV" value="test" force="true">.
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/DoctrineIntegrationTestCase.php';

(new \Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__) . '/.env');

// The Functional suite's Symfony module boots one kernel and reuses its connection for the
// whole run (relying on per-test transaction rollback for isolation, not a fresh connection
// per test) — so the schema needs to exist on disk *before* that first connection opens, and
// file-based SQLite (not :memory:) is what makes a schema built here visible to a connection
// opened later. See DoctrineIntegrationTestCase for the same reasoning applied to PHPUnit.
\App\Tests\DoctrineIntegrationTestCase::resetTestDatabaseFile();

// Guards against a stale compiled container/metadata cache under var/cache/test carrying
// mappings for an entity shape that no longer matches current code.
(new \Symfony\Component\Filesystem\Filesystem())->remove(dirname(__DIR__) . '/var/cache/test');

// And the app cache pool, which since Symfony 7.4 is a SEPARATE directory: framework.cache defaults
// to %kernel.share_dir%/pools/app — var/share/test, not var/cache/test — precisely so application
// cache survives a cache clear. The database was just deleted above, and AppSettings caches the
// whole app_setting table for an hour, so leaving the pool in place hands this run a settings table
// belonging to the previous one. AdminNumber1ProductImportCest writes default_sales_tax_code, and
// that value outliving its database is what made two ProductImportServiceTest cases fail on every
// run but the first — see DoctrineIntegrationTestCase::resetTestSettingsCache() for the full story.
//
// Run level only. This suite isolates with transaction rollback rather than a fresh database per
// test, so a setting written and rolled back mid-run is still in the pool for the tests after it.
(new \Symfony\Component\Filesystem\Filesystem())->remove(dirname(__DIR__) . '/var/share/test');

// Shells out to the console command rather than booting a kernel and fetching the
// EntityManager directly, since `doctrine.orm.entity_manager` isn't guaranteed public on a
// plain (non-test) container — bin/console's own commands always work regardless.
$process = new \Symfony\Component\Process\Process([
    PHP_BINARY,
    dirname(__DIR__) . '/bin/console',
    'doctrine:schema:create',
    '--env=test',
    '--no-interaction',
]);
$process->run();

if (!$process->isSuccessful()) {
    throw new \RuntimeException('Failed to create the Codeception test schema: ' . $process->getErrorOutput());
}

// Same reason as DoctrineIntegrationTestCase: schema:create does not run migrations, so the
// country/province reference rows have to be inserted before any test asks whether 'BC' is a valid
// province. Shelled out for the same reason schema:create is.
$seed = new \Symfony\Component\Process\Process([
    PHP_BINARY,
    dirname(__DIR__) . '/bin/console',
    'app:seed-regions',
    '--env=test',
    '--no-interaction',
]);
$seed->run();

if (!$seed->isSuccessful()) {
    throw new \RuntimeException('Failed to seed country/province reference data: ' . $seed->getErrorOutput());
}

// Bundles are inert until activated (BundleStatusRepository::isActive()), and the one-time upgrade
// that writes the previously-implicit Active rows is a MIGRATION — Version20260916104500 — which
// this database never sees, because the schema above is built from Doctrine metadata and the chain
// is never replayed. Without this step every bundle in the suite would be switched OFF: procurement,
// warehouse ops, inventory depth, barcodes, every fee, tax, shipping and payment app. Hundreds of
// tests would fail, and they would fail as 404s and empty lists rather than as anything naming the
// cause.
//
// So the test database is put into the same state a migrated one is in, using the same command a
// deploy would use to recover a database that missed the migration. Exactly the precedent
// app:seed-regions sets directly above: a command exists so that an environment built from entity
// metadata can reach the state the migration chain would have produced.
//
// This is run-level configuration written before any test opens a transaction, so it is committed
// and every connection sees it — see Tests\Support\Helper\BundlesOff for why a per-test write would
// not be visible to the kernel serving amOnPage(). The bundles-off environment still overrides its
// four sources back to Inactive in _beforeSuite(), after this.
$activate = new \Symfony\Component\Process\Process([
    PHP_BINARY,
    dirname(__DIR__) . '/bin/console',
    'app:bundle:activate',
    '--all-present',
    '--env=test',
    '--no-interaction',
]);
$activate->run();

if (!$activate->isSuccessful()) {
    throw new \RuntimeException('Failed to activate the installed bundles: ' . $activate->getErrorOutput());
}
