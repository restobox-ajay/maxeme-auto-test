<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AppSetting;
use App\Service\AppSettings;
use App\Tests\DoctrineIntegrationTestCase;
use PHPUnit\Framework\Attributes\Depends;

/**
 * A setting one test writes is not readable by the next one.
 *
 * ## Why this needs a test of its own
 *
 * {@see DoctrineIntegrationTestCase} promises every test a clean slate, and it delivers that by
 * deleting var/data_test.db and rebuilding the schema. For a service that reads the database
 * directly, that is the whole promise. {@see AppSettings} does not read the database directly: it
 * caches the ENTIRE app_setting table under one key, for an hour, in the application cache pool —
 * so the promise is only kept if the pool is emptied along with the database, and for a while it
 * was not.
 *
 * It used to be emptied by accident. tests/bootstrap.php removes var/cache/test before every run,
 * and the app pool lived there. Symfony 7.4 moved it: framework.cache now defaults to
 * `%kernel.share_dir%/pools/app` — var/share/<env> — a directory that exists precisely so that
 * application cache SURVIVES a cache clear. Nothing in either bootstrap swept it, so a settings row
 * written by one test became a row every later test and every later RUN could read, out of a
 * database that had been deleted in between.
 *
 * What that cost: ProductImportServiceTest stores default_sales_tax_code = 'E' in one test to prove
 * the import honours a configured default. From the next run onwards the two tests beside it —
 * "a missing sales_tax_code column defaults to S", "a blank cell defaults to S" — read that dead
 * 'E' and failed. They failed claiming imported products land Exempt and therefore untaxed, which
 * is a money bug, against an import that has been correct since #115. A fixture that fabricates a
 * money bug costs more than a missing test does.
 *
 * So the reset is pinned here rather than left as a comment in the base class, and it is pinned
 * from the OUTSIDE — one test writes, the next asserts it is gone — because that is the only shape
 * in which the failure was ever visible. Asserting it inside a single test would pass against the
 * broken base class.
 *
 * The ordering is explicit via #[Depends] rather than left to declaration order, since the whole
 * point is that the second test runs after the first and sees none of it.
 */
final class AppSettingsCacheDoesNotOutliveItsDatabaseTest extends DoctrineIntegrationTestCase
{
    private const KEY = 'default_sales_tax_code';

    /**
     * Writes a setting AND reads it back, which is the part that matters: the read is what pulls
     * the whole table into the cache pool, so a test that only persisted a row would leave nothing
     * behind to leak and this file would prove nothing.
     */
    public function testASettingWrittenByATestIsVisibleToThatTest(): void
    {
        $settings = self::getContainer()->get(AppSettings::class);

        $this->em->persist((new AppSetting())->setSettingKey(self::KEY)->setName('Default Sales Tax Code')->setSettingValue('E'));
        $this->em->flush();

        self::assertSame('E', $settings->get(self::KEY), 'a persisted setting is readable in the test that wrote it');
        self::assertArrayHasKey(self::KEY, $settings->all(), 'and the whole table has now been pulled into the cache pool');
    }

    /**
     * The database this test got is a brand new one, so the settings table is empty — and
     * AppSettings must say so, rather than answering out of the pool the previous test warmed.
     *
     * The default argument is the tell. `get(KEY, 'S')` returning 'E' is precisely the failure the
     * import tests were reporting: a caller asking for a fallback and being handed a value from a
     * database that no longer exists.
     */
    #[Depends('testASettingWrittenByATestIsVisibleToThatTest')]
    public function testTheNextTestSeesNoneOfIt(): void
    {
        $settings = self::getContainer()->get(AppSettings::class);

        self::assertSame(
            0,
            $this->em->getRepository(AppSetting::class)->count([]),
            'the database was rebuilt, so nothing the previous test wrote is in it',
        );
        self::assertFalse(
            $settings->has(self::KEY),
            'and AppSettings agrees: a setting from a deleted database must not still be readable through the cache',
        );
        self::assertSame(
            'S',
            $settings->get(self::KEY, 'S'),
            'so a caller asking for a default gets its own default back, not the previous test\'s value',
        );
        self::assertSame([], $settings->all(), 'the whole table reads empty, not just the one key');
    }
}
