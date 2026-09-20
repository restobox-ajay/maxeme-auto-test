<?php

declare(strict_types=1);

namespace App\Tests\Support\Fixtures;

use App\Contract\ReferenceData\ReferenceDataSeederInterface;
use App\Entity\UnitOfMeasure;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A bundle that ships one `unit_of_measure` row and assumes it owns that code.
 *
 * NOT registered in the container. Two instances of this, under two different seeder KEYS and the
 * same unit CODE, stand in for two bundles whose catalogues overlap — which is the one collision
 * the seeding design does not arbitrate: `reference_data_seed_mark.seeder_key` is unique per
 * SEEDER, and nothing anywhere is unique per reference ROW except the entity table's own index.
 *
 * ## Why it deliberately does NOT guard
 *
 * Every shipped seeder looks its natural key up first — `AbstractFeeCatalogueSeeder` asks
 * `findBySlug()` per definition, `CanadaSimpleTaxSeeder` keeps an in-memory `$persisted` set
 * because five provinces name one federal slug. That guard is what makes the shipped collisions
 * harmless, and it is a CONVENTION each seeder has to remember rather than anything the contract
 * enforces: `ReferenceDataSeederInterface::seed()` asks only for `persist()` calls and a count.
 *
 * So this models the seeder that forgets — a plausible new bundle — and the question it asks is
 * what that costs the system: whether the collision is contained to the bundle that caused it, or
 * whether it leaves every admin login retrying a seeder that can never succeed.
 */
final class ClaimsAUnitCodeReferenceDataSeeder implements ReferenceDataSeederInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $key,
        /** The `unit_of_measure.code` this "bundle" believes is its own. */
        private readonly string $code,
        private readonly string $name,
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return 'Unit claimer ' . $this->key;
    }

    public function seed(): int
    {
        $this->em->persist(
            (new UnitOfMeasure())
                ->setCode($this->code)
                ->setName($this->name)
                ->setFamily(UnitOfMeasure::FAMILY_QUANTITY)
                ->setFactorToFamilyBase('1')
                ->setRoundingPrecision('1')
        );

        return 1;
    }
}
