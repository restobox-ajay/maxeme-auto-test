<?php

declare(strict_types=1);

namespace App\Tests\Support\Fixtures;

use App\Contract\ReferenceData\ReferenceDataSeederInterface;
use App\Entity\TrackingPolicy;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stands in for "a bundle installed a year after the system went live".
 *
 * NOT registered in the container — deliberately. The property under test is what happens when a
 * seeder is ABSENT from one run and PRESENT in the next, and a service that the container always
 * yields cannot be absent from a run. `ReferenceDataSeedingCest` constructs the real
 * {@see \App\Service\ReferenceData\ReferenceDataSeeder} twice with two different iterables — the
 * second including this one — against the real EntityManager, the real `reference_data_seed_mark`
 * table and real rows. Everything about the mechanism under test is genuine; only the "which
 * bundles are installed today" input is supplied by hand, because it has to vary.
 *
 * It writes `tracking_policy` rows under names of its own, which no shipped seeder touches
 * ({@see \App\Service\ReferenceData\Seeders\TrackingPolicySeeder} creates only
 * `TrackingPolicy::DEFAULT_NAME`), so its rows are countable and separable from core's.
 */
final class LateBundleReferenceDataSeeder implements ReferenceDataSeederInterface
{
    public const KEY = 'test.late_bundle';

    /** The rows this "bundle" ships. Named so nothing else in the suite can collide with them. */
    public const POLICY_NAMES = ['Late Bundle Policy A', 'Late Bundle Policy B'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function getKey(): string
    {
        return self::KEY;
    }

    public function getLabel(): string
    {
        return 'Late bundle test data';
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

        return $created;
    }
}
