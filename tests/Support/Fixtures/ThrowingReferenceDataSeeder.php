<?php

declare(strict_types=1);

namespace App\Tests\Support\Fixtures;

use App\Contract\ReferenceData\ReferenceDataSeederInterface;

/**
 * A reference data seeder that fails on demand, registered as `app.reference_data_seeder` only in
 * the test environment (see `when@test` in config/services.yaml), alongside
 * {@see TestFrontendMenuItem}, which is registered the same way for the same reason.
 *
 * It exists to prove the one property no real seeder can be made to demonstrate: that a bundle
 * whose seeder throws costs the administrator nothing — login still succeeds, the other seeders
 * still run, and this one's mark is NOT written, so it is retried next time.
 *
 * ## Why the static switch
 *
 * A fixture that threw unconditionally would fire on every login in every Cest in the suite, filling
 * the error log and making the seeding path noisy everywhere for the benefit of one test. It is off
 * by default, so its `seed()` is a no-op that marks itself and is never called again — exactly like
 * a real bundle with nothing to seed.
 *
 * **A test that switches it on MUST switch it back off.** This suite reuses one Cest instance across
 * a class's methods and this flag is process-global, so a leak here would leak past the Cest that
 * set it into whatever runs next.
 */
final class ThrowingReferenceDataSeeder implements ReferenceDataSeederInterface
{
    /** Message the throw carries, so a test can assert the log line came from here. */
    public const FAILURE_MESSAGE = 'ThrowingReferenceDataSeeder was asked to fail.';

    public const KEY = 'test.throwing_seeder';

    private static bool $shouldThrow = false;

    /** How many times seed() has been entered — the proof it is retried after a failure. */
    private static int $calls = 0;

    public static function failOnNextRun(): void
    {
        self::$shouldThrow = true;
    }

    public static function reset(): void
    {
        self::$shouldThrow = false;
        self::$calls = 0;
    }

    public static function callCount(): int
    {
        return self::$calls;
    }

    public function getKey(): string
    {
        return self::KEY;
    }

    public function getLabel(): string
    {
        return 'Throwing test seeder';
    }

    public function seed(): int
    {
        self::$calls++;

        if (self::$shouldThrow) {
            throw new \RuntimeException(self::FAILURE_MESSAGE);
        }

        return 0;
    }
}
