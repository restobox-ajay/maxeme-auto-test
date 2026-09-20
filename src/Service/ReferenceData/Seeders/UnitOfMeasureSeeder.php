<?php

declare(strict_types=1);

namespace App\Service\ReferenceData\Seeders;

use App\Contract\ReferenceData\ReferenceDataSeederInterface;
use App\Entity\UnitOfMeasure;
use App\Repository\UnitOfMeasureRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The four shipped units (#601, #643).
 *
 * This is what `UnitOfMeasureRepository::ensureSeeded()` did, called from
 * `UnitOfMeasureController::index()` and `::new()` — two GET actions that wrote. The rows are the
 * same rows; what changed is when they appear. Before this, a product's unit dropdown was empty
 * until somebody opened Units of Measure.
 *
 * ## Only a COMPLETELY empty table is seeded, and that is not laziness
 *
 * `ensureSeeded()` had the same rule and the reason it had it survives the move: the marks table is
 * new, so on an installation upgrading to this code EVERY seeder's key is unmarked while the rows it
 * would create are already there — put there by the config-page visit this change removes. A per-code
 * top-up would therefore fire once, on the upgrade, against a list a customer has had for months,
 * and would re-create exactly the units they had deliberately deleted. Seeding only an empty table
 * makes that impossible: three units present means somebody has been here, and this is not the code
 * that decides what they meant by it.
 *
 * A unit is frozen the moment anything points at it ({@see UnitOfMeasure}) — restating a factor
 * would restate every quantity derived through it — so no existing row is written to either way.
 */
final class UnitOfMeasureSeeder implements ReferenceDataSeederInterface
{
    /**
     * Exactly the values `product_core.unit` held as free text before #643, so this restates
     * nothing: every one of them was already in use, spelled this way, before there was a table.
     */
    private const UNITS = [
        'EA' => 'Each',
        'BOX' => 'Box',
        'PR' => 'Pair',
        'BAG' => 'Bag',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UnitOfMeasureRepository $units,
    ) {
    }

    public function getKey(): string
    {
        return 'core.unit_of_measure';
    }

    public function getLabel(): string
    {
        return 'Units of measure';
    }

    public function seed(): int
    {
        if ($this->units->allByCode() !== []) {
            return 0;
        }

        $created = 0;

        foreach (self::UNITS as $code => $name) {
            $this->em->persist(
                (new UnitOfMeasure())
                    ->setCode($code)
                    ->setName($name)
                    ->setFamily(UnitOfMeasure::FAMILY_QUANTITY)
                    ->setFactorToFamilyBase('1')
                    ->setRoundingPrecision('1')
            );
            $created++;
        }

        return $created;
    }
}
