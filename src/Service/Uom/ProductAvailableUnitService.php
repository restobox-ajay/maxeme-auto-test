<?php

declare(strict_types=1);

namespace App\Service\Uom;

use App\Entity\ProductAvailableUnit;
use App\Entity\ProductCore;
use App\Entity\UnitOfMeasure;
use App\Repository\ProductAvailableUnitRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Which units a product may be expressed in, and which one a line starts on (#659).
 *
 * ## The one enhancement over stock NetSuite
 *
 * NetSuite puts every ratio on the units type and lets an item pick the type; the whole type then
 * reaches the user. Conversion staying global is what makes `Box-12` a term defined once instead of
 * a twelve typed onto hundreds of products one at a time — and the price of it is a vocabulary that
 * grows without bound, because every distinct pack size anybody ships becomes a term. This class is
 * what keeps that catalogue out of the order screen: long in the catalogue, short everywhere a
 * person works.
 *
 * ## Scoped twice, and the second scope is not a second column
 *
 * A product's available units are scoped to the PRODUCT, by `product_available_unit.product_id`, and
 * to its FAMILY — count, weight, volume or length — because conversion is only ever defined inside a
 * family and a product that was both "sold by weight" and "sold by count" would be asking the app to
 * convert kilograms into eaches. A thing that genuinely is both is two products.
 *
 * The family is **derived, never stored**: it is the family of the product's base unit. Storing it
 * again on `product_core` would be the same fact in two places, free to disagree the moment somebody
 * changed one — the shape #659 exists to remove, arriving in the code that removes it. A product
 * with no base unit therefore has no family, and no unit may be added to it: there is nothing to
 * measure against yet, and picking a family by guessing from the first unit ticked would be the app
 * deciding what the product's numbers mean.
 *
 * ## Blank is allowed
 *
 * A product may list nothing. Some items do not participate — NetSuite's own behaviour, and the
 * owner's explicit ruling. {@see choicesFor()} then offers the base unit alone, which is exactly
 * what every line did before this issue existed.
 *
 * ## The base unit is offered but never stored
 *
 * Stock is stored in it, so a line can always be written in it; a row saying so would be the base
 * unit recorded twice, on `product_core.unit_id` and in the join table, free to disagree. It is
 * prepended by {@see choicesFor()} instead, and {@see apply()} drops it from a submitted list rather
 * than refusing it — an admin ticking the unit the product is already counted in is agreeing with
 * the system, not making a mistake.
 */
final class ProductAvailableUnitService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProductAvailableUnitRepository $available,
    ) {
    }

    /**
     * The family this product's units must come from, or NULL when it has not declared a base unit.
     *
     * Derived from the base unit rather than held beside it — see the class docblock.
     */
    public function familyOf(ProductCore $product): ?string
    {
        return $product->getBaseUnit()?->getFamily();
    }

    /**
     * Every unit this product may be expressed in, base unit first, smallest ratio after it.
     *
     * The list a picker renders. It is never empty for a product that has declared a base unit, and
     * it is empty for one that has not — which is a product whose quantities are not denominated in
     * anything the app knows about, and which therefore gets no picker at all.
     *
     * @return list<UnitOfMeasure>
     */
    public function choicesFor(ProductCore $product): array
    {
        $base = $product->getBaseUnit();
        if (!$base instanceof UnitOfMeasure) {
            return [];
        }

        $choices = [$base];
        foreach ($this->available->forProduct($product) as $row) {
            if ((int) $row->getUnit()->getId() !== (int) $base->getId()) {
                $choices[] = $row->getUnit();
            }
        }

        return $choices;
    }

    /**
     * The same for many products at once, keyed by product id — one query, not one per line.
     *
     * A product absent from the map has no choices at all, which is how a caller distinguishes
     * "ordered in its base unit only" (one choice) from "declares nothing" (none).
     *
     * @param list<ProductCore> $products
     *
     * @return array<int, list<UnitOfMeasure>>
     */
    public function choicesForProducts(array $products): array
    {
        $ids = [];
        foreach ($products as $product) {
            $id = $product->getId();
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        $rowsByProduct = $this->available->forProducts($ids);

        $choices = [];
        foreach ($products as $product) {
            $id = (int) $product->getId();
            $base = $product->getBaseUnit();
            if (!$base instanceof UnitOfMeasure) {
                continue;
            }

            $list = [$base];
            foreach ($rowsByProduct[$id] ?? [] as $row) {
                if ((int) $row->getUnit()->getId() !== (int) $base->getId()) {
                    $list[] = $row->getUnit();
                }
            }

            $choices[$id] = $list;
        }

        return $choices;
    }

    /** Whether a line on $product may be denominated in $unit — the check every writer owes. */
    public function offers(ProductCore $product, UnitOfMeasure $unit): bool
    {
        foreach ($this->choicesFor($product) as $choice) {
            if ((int) $choice->getId() === (int) $unit->getId()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The units of $product's family, for the form to offer as tick boxes.
     *
     * Empty when the product has not declared a base unit — the form says so rather than listing
     * every unit in the instance, which is the defect #659 opens with: "Anchor Bolt Sleeve M12 (bag
     * of 50)" acquired base unit `BAG` because the dropdown offered the whole vocabulary.
     *
     * @return list<UnitOfMeasure>
     */
    public function familyUnitsFor(ProductCore $product): array
    {
        $family = $this->familyOf($product);
        if ($family === null) {
            return [];
        }

        /** @var list<UnitOfMeasure> $rows */
        $rows = $this->em->getRepository(UnitOfMeasure::class)
            ->createQueryBuilder('u')
            ->andWhere('u.family = :family')->setParameter('family', $family)
            ->orderBy('u.factorToFamilyBase', 'ASC')
            ->addOrderBy('u.code', 'ASC')
            ->getQuery()->getResult();

        return $rows;
    }

    /**
     * Replaces the product's available-units list and its default, or refuses.
     *
     * One method for both, because they are one statement: a default that is not among the available
     * units is a picker whose pre-selected option is not in it, and validating them separately would
     * let an admin remove a unit and leave the default pointing at it.
     *
     * Rows are reconciled rather than deleted and re-inserted: a row that is still ticked keeps its
     * id, so nothing that could ever reference it is disturbed by an unrelated save.
     *
     * Does not flush — the product form applies a dozen fields and commits once, and a service that
     * flushed here would commit half a form the validator has not finished with.
     *
     * @param list<int> $unitIds       the units ticked; `[]` is legitimate — blank is allowed
     * @param int|null  $defaultUnitId the unit a line starts on, or null for the base unit
     *
     * @throws UnitOfMeasureRefusal
     */
    public function apply(ProductCore $product, array $unitIds, ?int $defaultUnitId): void
    {
        // Validated in full BEFORE anything is scheduled on the unit of work.
        //
        // Not tidiness: this method persists rows and then reads the default, so a refusal raised
        // halfway would leave a row scheduled that the caller never asked to keep. The product form
        // catches the refusal and saves the rest of the form, which flushes — so a half-applied list
        // would be written by the very save that reported it was refused, and re-submitting the form
        // would then collide on the unique index. Nothing is touched until every rule has passed.
        $wanted = $this->resolveWanted($product, $unitIds);
        $default = $this->resolveDefault($product, $defaultUnitId, $wanted);

        $existing = [];
        foreach ($this->available->forProduct($product) as $row) {
            $existing[(int) $row->getUnit()->getId()] = $row;
        }

        foreach ($existing as $unitId => $row) {
            if (!isset($wanted[$unitId])) {
                $this->em->remove($row);
            }
        }

        foreach ($wanted as $unitId => $unit) {
            if (!isset($existing[$unitId])) {
                $this->em->persist((new ProductAvailableUnit())->setProduct($product)->setUnit($unit));
            }
        }

        $product->setDefaultUnit($default);
    }

    /**
     * The submitted ids as units, checked against the product's family.
     *
     * @param list<int> $unitIds
     *
     * @return array<int, UnitOfMeasure> keyed by unit id, base unit excluded
     *
     * @throws UnitOfMeasureRefusal
     */
    private function resolveWanted(ProductCore $product, array $unitIds): array
    {
        $family = $this->familyOf($product);
        $baseId = (int) ($product->getBaseUnit()?->getId() ?? 0);

        $wanted = [];
        foreach (array_unique(array_filter($unitIds, static fn (int $id): bool => $id > 0)) as $id) {
            $unit = $this->em->find(UnitOfMeasure::class, $id);
            if (!$unit instanceof UnitOfMeasure) {
                throw new UnitOfMeasureRefusal('One of the units submitted does not exist.');
            }

            if ($family === null) {
                throw new UnitOfMeasureRefusal(
                    'This product has not declared a base unit yet, so there is no family to draw units from. '
                    . 'Set the Unit of Measure first — it is what every quantity of this product is counted in.'
                );
            }

            if ($unit->getFamily() !== $family) {
                throw new UnitOfMeasureRefusal(sprintf(
                    '"%s" measures %s and this product is measured in %s. Nothing converts across families, so a '
                        . 'product is expressed in units of one of them — a thing that is genuinely both is two products.',
                    $unit->getCode(),
                    $unit->getFamily(),
                    $family,
                ));
            }

            // The base unit is available by definition and is never a row here; ticking it is
            // agreement, not a mistake, so it is dropped rather than refused.
            if ((int) $unit->getId() !== $baseId) {
                $wanted[(int) $unit->getId()] = $unit;
            }
        }

        return $wanted;
    }

    /**
     * The default unit the product should end up with, or NULL for "opens on the base unit".
     *
     * @param array<int, UnitOfMeasure> $wanted the list about to be applied, keyed by unit id
     *
     * @throws UnitOfMeasureRefusal
     */
    private function resolveDefault(ProductCore $product, ?int $defaultUnitId, array $wanted): ?UnitOfMeasure
    {
        if ($defaultUnitId === null || $defaultUnitId <= 0) {
            return null;
        }

        $base = $product->getBaseUnit();
        if ($base instanceof UnitOfMeasure && (int) $base->getId() === $defaultUnitId) {
            // Explicitly choosing the base unit is the same statement as choosing nothing, and
            // storing it would be the base unit held in a second column that a later base-unit
            // change could leave behind.
            return null;
        }

        if (!isset($wanted[$defaultUnitId])) {
            throw new UnitOfMeasureRefusal(
                'The default unit must be one of the units this product is available in. '
                . 'Tick it in the list first, or leave the default empty to start on the base unit.'
            );
        }

        return $wanted[$defaultUnitId];
    }
}
