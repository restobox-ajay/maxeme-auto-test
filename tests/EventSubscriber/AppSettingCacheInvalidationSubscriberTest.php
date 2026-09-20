<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\AppSetting;
use App\Entity\ProductCategory;
use App\Service\AppSettings;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * The point of every test here is what it does NOT contain: a call to AppSettings::clearCache()
 * between the write and the read. Each one writes an AppSetting the way a bundle does — straight
 * through the EntityManager, never through ConfigController — and expects the new value back
 * immediately (#442).
 *
 * Extends DoctrineIntegrationTestCase specifically because it boots the real kernel, and the
 * listener under test is only registered there. A mocked EntityManager fires no Doctrine events
 * at all, so a unit test of this could pass while the wiring was broken.
 */
final class AppSettingCacheInvalidationSubscriberTest extends DoctrineIntegrationTestCase
{
    private const PROBE_KEY = 'app_setting_cache_probe';

    private AppSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = self::getContainer()->get(AppSettings::class);

        // The database file is rebuilt for every test; the cache pool is on the filesystem and is
        // not. Start from a known-empty entry instead of whatever an earlier run left there.
        $this->settings->clearCache();
    }

    protected function tearDown(): void
    {
        // Same asymmetry in reverse: a probe value left in the pool would outlive this class and
        // surface in some unrelated test as a setting nobody set. That cross-test poisoning is the
        // bug #442 exists to stop, so this suite had better not cause it.
        if (self::$kernel !== null) {
            self::getContainer()->get(AppSettings::class)->clearCache();
        }

        parent::tearDown();
    }

    private function persistProbe(string $value): AppSetting
    {
        $setting = (new AppSetting())
            ->setSettingKey(self::PROBE_KEY)
            ->setName('Cache invalidation probe')
            ->setSettingValue($value);

        $this->em->persist($setting);
        $this->em->flush();

        return $setting;
    }

    public function testInsertIsVisibleImmediatelyWithoutClearingTheCache(): void
    {
        // Reading first is the whole setup: it populates the cache with the pre-write state, so
        // the assertion below can only pass if something invalidated it.
        self::assertNull($this->settings->get(self::PROBE_KEY));

        $this->persistProbe('inserted');

        self::assertSame('inserted', $this->settings->get(self::PROBE_KEY));
    }

    public function testUpdateIsVisibleImmediatelyWithoutClearingTheCache(): void
    {
        $setting = $this->persistProbe('before');
        self::assertSame('before', $this->settings->get(self::PROBE_KEY));

        $setting->setSettingValue('after');
        $this->em->flush();

        self::assertSame('after', $this->settings->get(self::PROBE_KEY));
    }

    public function testDeleteIsVisibleImmediatelyWithoutClearingTheCache(): void
    {
        $setting = $this->persistProbe('doomed');
        self::assertSame('doomed', $this->settings->get(self::PROBE_KEY));

        $this->em->remove($setting);
        $this->em->flush();

        self::assertNull($this->settings->get(self::PROBE_KEY));
        self::assertFalse($this->settings->has(self::PROBE_KEY));
    }

    /**
     * Several settings can move in one flush — a config screen saving a whole form is the normal
     * case — and one invalidation has to cover all of them.
     */
    public function testASingleFlushTouchingSeveralSettingsInvalidatesOnceForAllOfThem(): void
    {
        self::assertNull($this->settings->get(self::PROBE_KEY));

        foreach (['alpha' => 'a', 'beta' => 'b'] as $suffix => $value) {
            $this->em->persist(
                (new AppSetting())
                    ->setSettingKey(self::PROBE_KEY . '_' . $suffix)
                    ->setName('Cache invalidation probe ' . $suffix)
                    ->setSettingValue($value)
            );
        }
        $this->em->flush();

        self::assertSame('a', $this->settings->get(self::PROBE_KEY . '_alpha'));
        self::assertSame('b', $this->settings->get(self::PROBE_KEY . '_beta'));
    }

    /**
     * Guards the other edge: the listener must not throw the settings cache away on every flush in
     * the application. Proving a cache entry survived means changing the row underneath it without
     * the ORM noticing, which is also an honest demonstration of the listener's known blind spot —
     * raw SQL (the admin console, migrations, the sqlite3 CLI) still needs an explicit clearCache()
     * or the TTL, which is why both are still in place.
     */
    public function testAFlushOfUnrelatedEntitiesLeavesTheSettingsCacheAlone(): void
    {
        $this->persistProbe('cached');
        self::assertSame('cached', $this->settings->get(self::PROBE_KEY));

        $this->em->getConnection()->executeStatement(
            'UPDATE app_setting SET setting_value = :value WHERE setting_key = :key',
            ['value' => 'changed behind the cache', 'key' => self::PROBE_KEY],
        );

        // Without this the identity map would keep handing back the in-memory entity, still
        // holding 'cached', and the assertions below would hold whether or not the cache was
        // dropped — i.e. they would prove nothing. Detaching is safe here only because nothing
        // writes to $setting afterwards: persisting a detached AppSetting would INSERT a second
        // row and trip uniq_app_setting_key.
        $this->em->clear();

        $this->em->persist((new ProductCategory())->setName('Unrelated to any setting'));
        $this->em->flush();

        self::assertSame('cached', $this->settings->get(self::PROBE_KEY));

        // And the control: the cache really was the only thing holding 'cached' in place.
        $this->settings->clearCache();
        self::assertSame('changed behind the cache', $this->settings->get(self::PROBE_KEY));
    }
}
