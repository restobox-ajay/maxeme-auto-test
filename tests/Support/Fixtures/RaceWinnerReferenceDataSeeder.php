<?php

declare(strict_types=1);

namespace App\Tests\Support\Fixtures;

use App\Contract\ReferenceData\ReferenceDataSeederInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stands in for the OTHER admin, logging in at the same instant and getting there first.
 *
 * NOT registered in the container. `ReferenceDataSeedingCest` hands it to a hand-built
 * {@see \App\Service\ReferenceData\ReferenceDataSeeder} alongside the seeder that is about to lose
 * the race, and relies on the runner sorting by key: `aaa.` sorts ahead of `core.`, so this runs
 * first and claims the victim's key out from under it.
 *
 * ## Why this is the only way to test the thing that actually protects us
 *
 * The `seededKeys()` read at the top of a run is an optimisation, not the guard — two simultaneous
 * logins both read an empty marks table and both pass it. What stops the second one is the UNIQUE
 * index refusing its INSERT. A test that simply pre-inserts the mark before the run never reaches
 * that INSERT, because the read short-circuits it; it proves the fast path and nothing about the
 * race. This inserts the mark AFTER that read has happened and BEFORE the victim's claim, which is
 * exactly the window a real race opens.
 *
 * The INSERT is raw SQL on the shared connection rather than a persisted entity, because that is
 * what the other process's committed write looks like from here: a row that is simply there.
 */
final class RaceWinnerReferenceDataSeeder implements ReferenceDataSeederInterface
{
    public const KEY = 'aaa.race_winner';

    public function __construct(
        private readonly EntityManagerInterface $em,
        /** The key this pretends the other process claimed first. */
        private readonly string $victimKey,
    ) {
    }

    public function getKey(): string
    {
        return self::KEY;
    }

    public function getLabel(): string
    {
        return 'Race winner test seeder';
    }

    public function seed(): int
    {
        $this->em->getConnection()->insert('reference_data_seed_mark', [
            'seeder_key' => $this->victimKey,
            'rows_created' => 0,
            'seeded_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return 0;
    }
}
