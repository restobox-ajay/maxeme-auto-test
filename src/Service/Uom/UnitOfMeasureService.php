<?php

declare(strict_types=1);

namespace App\Service\Uom;

use App\Entity\UnitOfMeasure;
use App\Repository\UnitOfMeasureRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ToOneOwningSideMapping;

/**
 * Every write to the global measurement system goes through here (#659).
 *
 * ## The refusal this class exists for
 *
 * **A unit freezes once something is denominated in it.** `Box-12` means twelve forever. Editing it
 * to mean twenty-four would restate every order, invoice and estimate already written against it —
 * at once, with no movement recorded and nothing to reconcile against. A unit that needs a different
 * ratio is a NEW unit (`Box-24`), and the products that ship in twenty-fours are repointed at it.
 *
 * That is the same refusal `ProductPackagingUnitService::assertUnreferenced()` implemented for
 * per-product rungs, ported here rather than deleted with the rest of packaging: #659 moved the
 * ratio onto the global table, so the hazard moved with it. It arrives on a table that is SHARED,
 * which makes it worse rather than better — a per-product rung could only restate one product's
 * documents, and a unit can restate every product that names it.
 *
 * ## How a reference is found
 *
 * Discovered from Doctrine's metadata, never from a hand-kept list: every mapped owning to-one
 * association in the whole entity map — core or bundle, installed or not — whose target is
 * {@see UnitOfMeasure} is counted. A bundle whose `bundle_status` row is Inactive still declares its
 * mappings, so its columns are swept exactly as core's are; editing only `src/` would leave them
 * unswept and a unit editable while a purchase order line pointed at it.
 *
 * The association IS the registration. Nothing has to be added here when a new document type gains a
 * unit column, which is precisely the property that made the packaging version survive #646 adding
 * thirteen siblings to it without an edit — and the same property that carried this one through
 * #659's own steps 2, 3 and 4 unedited.
 * `testTheReferenceSweepFindsEveryColumnThatCanPointAtAUnit()` pins what the sweep discovers, so a
 * guard that stops covering something fails instead of widening in silence.
 *
 * ## What a restatement is, and what is merely a correction
 *
 * Not every edit restates history, and refusing the harmless ones would make a typo permanent:
 *
 * | field | refused once referenced | why |
 * |---|---|---|
 * | `factor_to_family_base` | yes | the arithmetic every stored base quantity was derived through |
 * | `family` | yes | moves the unit to a different base, so the same factor means something else |
 * | `code` | yes | it is what an issued document PRINTS; changing it changes what March's invoice says |
 * | `name` | no | a description nobody computes with |
 * | `rounding_precision` | no | it gates what may be typed NEXT; no stored figure changes |
 *
 * Deletion is refused outright — a referencing row whose unit vanished would be denominated in
 * nothing at all.
 *
 * ## Why the service and not the entity
 *
 * The entity can keep its own fields consistent; it cannot know whether a row is referenced, because
 * that means asking the database. So the check and the write live in one method here, and there is
 * no path to the write that does not pass the check.
 */
final class UnitOfMeasureService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UnitOfMeasureRepository $units,
    ) {
    }

    /**
     * Adds a unit. Nothing can reference a row that does not exist yet, so there is nothing to
     * freeze — only the shape of the values is checked.
     *
     * @throws UnitOfMeasureRefusal
     */
    public function add(string $code, string $name, string $family, string $factor, string $precision): UnitOfMeasure
    {
        $code = self::normalizeCode($code);
        $name = trim($name);

        $this->assertCodeIsFree($code, null);
        $this->assertNamed($name);
        $this->assertPositive($factor, 'factor to the family base');
        $this->assertPositive($precision, 'rounding precision');

        $unit = (new UnitOfMeasure())
            ->setCode($code)
            ->setName($name)
            ->setFamily($family)
            ->setFactorToFamilyBase($factor)
            ->setRoundingPrecision($precision);

        $this->em->persist($unit);
        $this->em->flush();

        return $unit;
    }

    /**
     * Re-states a unit — refused the moment anything is denominated in it and the edit changes what
     * the unit MEANS.
     *
     * @throws UnitOfMeasureRefusal
     */
    public function update(UnitOfMeasure $unit, string $code, string $name, string $family, string $factor, string $precision): void
    {
        $code = self::normalizeCode($code);
        $name = trim($name);

        $this->assertCodeIsFree($code, $unit);
        $this->assertNamed($name);
        $this->assertPositive($factor, 'factor to the family base');
        $this->assertPositive($precision, 'rounding precision');

        if ($this->restates($unit, $code, $family, $factor)) {
            $this->assertUnreferenced($unit, 'restated');
        }

        $unit
            ->setCode($code)
            ->setName($name)
            ->setFamily($family)
            ->setFactorToFamilyBase($factor)
            ->setRoundingPrecision($precision);

        $this->em->flush();
    }

    /**
     * Removes a unit nothing is denominated in.
     *
     * @throws UnitOfMeasureRefusal
     */
    public function delete(UnitOfMeasure $unit): void
    {
        $this->assertUnreferenced($unit, 'deleted');

        $this->em->remove($unit);
        $this->em->flush();
    }

    /**
     * Whether the submitted values would change what $unit MEANS — see the class docblock's table.
     *
     * Compared through the entity's own normalisers, so `12`, `12.0` and `12.000000` are one value
     * and re-saving a form nobody edited is not a restatement. Without that, opening the edit panel
     * and pressing Save would be refused on a unit an order references, which reads as a bug.
     */
    public function restates(UnitOfMeasure $unit, string $code, string $family, string $factor): bool
    {
        $probe = (new UnitOfMeasure())->setFamily($family)->setFactorToFamilyBase($factor);

        return self::normalizeCode($code) !== $unit->getCode()
            || $probe->getFamily() !== $unit->getFamily()
            || $probe->getFactorToFamilyBase() !== $unit->getFactorToFamilyBase();
    }

    /**
     * What references $unit, as `table.column => row count`, empty when nothing does.
     *
     * Public because it is what the screen shows the admin: "referenced by sales_order_line.unit_id
     * (3)" is an answer they can act on, where "cannot edit" is not.
     *
     * @return array<string, int>
     */
    public function referenceCounts(UnitOfMeasure $unit): array
    {
        $counts = [];

        foreach ($this->referencingAssociations() as [$entityClass, $field, $label]) {
            $count = (int) $this->em->createQueryBuilder()
                ->select('COUNT(r)')
                ->from($entityClass, 'r')
                ->andWhere('r.' . $field . ' = :unit')->setParameter('unit', $unit)
                ->getQuery()->getSingleScalarResult();

            if ($count > 0) {
                $counts[$label] = $count;
            }
        }

        return $counts;
    }

    /**
     * The same counts for a whole page of units, in one query per referencing column.
     *
     * The list screen shows "referenced by" on every row, and asking per row would be a COUNT per
     * unit per column — a 200-row page times sixteen columns. Grouped instead, which is one query
     * per column for the page however many rows it holds.
     *
     * @param list<UnitOfMeasure> $units
     *
     * @return array<int, array<string, int>> counts keyed by unit id; a unit nothing references keys
     *                                        an empty array rather than being absent
     */
    public function referenceCountsForPage(array $units): array
    {
        $ids = [];
        foreach ($units as $unit) {
            $id = $unit->getId();
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        /** @var array<int, array<string, int>> $counts */
        $counts = array_fill_keys($ids, []);

        foreach ($this->referencingAssociations() as [$entityClass, $field, $label]) {
            $rows = $this->em->createQueryBuilder()
                ->select('IDENTITY(r.' . $field . ') AS unitId, COUNT(r.id) AS total')
                ->from($entityClass, 'r')
                ->andWhere('IDENTITY(r.' . $field . ') IN (:ids)')->setParameter('ids', $ids)
                ->groupBy('r.' . $field)
                ->getQuery()->getArrayResult();

            foreach ($rows as $row) {
                $counts[(int) $row['unitId']][$label] = (int) $row['total'];
            }
        }

        return $counts;
    }

    /**
     * Every `table.column` in the entity map that can point at a unit, sorted.
     *
     * Exposed so a test can state what the sweep discovers, and so that the same test fails —
     * loudly, naming the new column — when a document type gains one.
     *
     * @return list<string>
     */
    public function referencingColumns(): array
    {
        $labels = array_map(static fn (array $assoc): string => $assoc[2], $this->referencingAssociations());
        sort($labels);

        return $labels;
    }

    /**
     * @return list<array{0: class-string, 1: string, 2: string}> entity class, field, `table.column`
     */
    private function referencingAssociations(): array
    {
        $found = [];

        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            if (!$meta instanceof ClassMetadata || $meta->isMappedSuperclass) {
                continue;
            }

            foreach ($meta->associationMappings as $field => $mapping) {
                if ($mapping->targetEntity !== UnitOfMeasure::class) {
                    continue;
                }

                // Owning to-one only: an inverse side owns no column, and a collection is the same
                // rows counted from the other end.
                if (!$mapping->isToOne() || !$mapping->isOwningSide()) {
                    continue;
                }

                // An inherited mapping is the parent class's row set seen twice; count it once, on
                // the class that declares it.
                if ($mapping->inherited !== null) {
                    continue;
                }

                $column = $mapping instanceof ToOneOwningSideMapping && $mapping->joinColumns !== []
                    ? $mapping->joinColumns[0]->name
                    : $field;

                $found[] = [$meta->getName(), $field, $meta->getTableName() . '.' . $column];
            }
        }

        return $found;
    }

    /** @throws UnitOfMeasureRefusal */
    private function assertUnreferenced(UnitOfMeasure $unit, string $verb): void
    {
        $counts = $this->referenceCounts($unit);
        if ($counts === []) {
            return;
        }

        $where = [];
        foreach ($counts as $label => $count) {
            $where[] = sprintf('%s (%d)', $label, $count);
        }

        throw new UnitOfMeasureRefusal(sprintf(
            '"%s" is referenced by %s and cannot be %s. A unit that needs a different ratio is a NEW unit — '
                . 'add one and repoint the products that use it, so every document already written keeps meaning what it said.',
            $unit->getLabel(),
            implode(', ', $where),
            $verb,
        ));
    }

    /** @throws UnitOfMeasureRefusal */
    private function assertCodeIsFree(string $code, ?UnitOfMeasure $self): void
    {
        if ($code === '') {
            throw new UnitOfMeasureRefusal('A unit needs a code — it is what a document prints.');
        }

        $existing = $this->units->findOneBy(['code' => $code]);
        if ($existing instanceof UnitOfMeasure && $existing !== $self) {
            throw new UnitOfMeasureRefusal(sprintf('A unit with the code "%s" already exists.', $code));
        }
    }

    /** @throws UnitOfMeasureRefusal */
    private function assertNamed(string $name): void
    {
        if ($name === '') {
            throw new UnitOfMeasureRefusal('A unit needs a name — it is what a person reads on the product form.');
        }
    }

    /** @throws UnitOfMeasureRefusal */
    private function assertPositive(string $value, string $what): void
    {
        if ((float) trim($value) <= 0.0) {
            throw new UnitOfMeasureRefusal(sprintf('The %s must be greater than zero.', $what));
        }
    }

    /** The same upper-casing {@see UnitOfMeasure::setCode()} applies, so a comparison can use it. */
    private static function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }
}
