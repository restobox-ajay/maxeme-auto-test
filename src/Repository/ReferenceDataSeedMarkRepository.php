<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ReferenceDataSeedMark;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReferenceDataSeedMark>
 *
 * Deliberately read-only. Nothing here creates a mark: the mark is claimed by
 * {@see \App\Service\ReferenceData\ReferenceDataSeeder} with a raw INSERT inside the seeding
 * transaction, because the point of the claim is that it can FAIL (the unique index) and take the
 * rows with it. A `findOrCreate()` helper on this class would be exactly the check-then-insert the
 * design is avoiding, and would be reached for by the next person who needs a mark.
 */
class ReferenceDataSeedMarkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReferenceDataSeedMark::class);
    }

    /**
     * Every key that has been seeded, as a set.
     *
     * One query for the WHOLE run rather than one per seeder — this is the steady-state cost of the
     * login listener, and the table holds one short row per seeder (ten-ish), so it is a single
     * index scan returning a handful of strings. Keys come back as the array KEYS so the caller
     * tests membership with `isset()` and never scans a list.
     *
     * @return array<string, true>
     */
    public function seededKeys(): array
    {
        /** @var list<string> $keys */
        $keys = $this->createQueryBuilder('m')
            ->select('m.seederKey')
            ->getQuery()
            ->getSingleColumnResult();

        return array_fill_keys(array_map('strval', $keys), true);
    }

    public function isSeeded(string $key): bool
    {
        return $this->findOneBy(['seederKey' => $key]) instanceof ReferenceDataSeedMark;
    }
}
