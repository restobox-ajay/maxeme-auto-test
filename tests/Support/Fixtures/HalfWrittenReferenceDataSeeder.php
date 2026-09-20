<?php

declare(strict_types=1);

namespace App\Tests\Support\Fixtures;

use App\Contract\ReferenceData\ReferenceDataSeederInterface;
use App\Entity\TrackingPolicy;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A seeder that gets HALF WAY and then throws — rows persisted, then a failure.
 *
 * NOT registered in the container, for the reason {@see LateBundleReferenceDataSeeder} is not: the
 * property under test is what the central seeder does with a seeder that fails PART WAY, and that
 * has to be supplied by hand.
 *
 * ## Why the shipped fixture could not answer this
 *
 * {@see ThrowingReferenceDataSeeder} throws on its FIRST statement — it persists nothing before it
 * fails. That proves a failing seeder leaves its mark unwritten and is retried, which is a real
 * property and is already covered. It cannot prove the one the transaction exists for: that rows
 * already handed to the EntityManager go back too. A seeder that persists three rows and dies on
 * the fourth is the realistic failure — a bad row in the middle of a catalogue, a constraint nobody
 * anticipated — and "nothing is left half-written" is only meaningful against it.
 *
 * The rows are `tracking_policy` rows under names of this fixture's own, so they are countable and
 * cannot collide with anything a shipped seeder creates.
 */
final class HalfWrittenReferenceDataSeeder implements ReferenceDataSeederInterface
{
    public const KEY = 'test.half_written';

    public const FAILURE_MESSAGE = 'HalfWrittenReferenceDataSeeder failed after persisting its first rows.';

    /** Written before the throw. Both must be gone afterwards. */
    public const POLICY_NAMES = ['Half Written Policy A', 'Half Written Policy B'];

    private static bool $shouldThrow = true;

    public static function failOnNextRun(): void
    {
        self::$shouldThrow = true;
    }

    /** Lets the retry succeed, so a test can show the same seeder completing afterwards. */
    public static function succeedFromNowOn(): void
    {
        self::$shouldThrow = false;
    }

    public static function reset(): void
    {
        self::$shouldThrow = true;
    }

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function getKey(): string
    {
        return self::KEY;
    }

    public function getLabel(): string
    {
        return 'Half written test seeder';
    }

    public function seed(): int
    {
        $created = 0;

        foreach (self::POLICY_NAMES as $name) {
            if ($this->em->getRepository(TrackingPolicy::class)->findOneBy(['name' => $name]) instanceof TrackingPolicy) {
                continue;
            }

            $this->em->persist(
                (new TrackingPolicy())->setName($name)->setMode(TrackingPolicy::MODE_NONE)
            );
            $created++;
        }

        // The rows above are persisted and NOT yet flushed — exactly where a real seeder dies when
        // the fifth row of a catalogue turns out to be bad.
        if (self::$shouldThrow) {
            throw new \RuntimeException(self::FAILURE_MESSAGE);
        }

        return $created;
    }
}
