<?php

declare(strict_types=1);

namespace InventoryDepthBundle\ReferenceData;

use App\Contract\ReferenceData\ReferenceDataSeederInterface;
use Doctrine\ORM\EntityManagerInterface;
use InventoryDepthBundle\Entity\InventoryAdjustmentReason;
use InventoryDepthBundle\Repository\InventoryAdjustmentReasonRepository;

/**
 * The eight shipped adjustment reasons (#585).
 *
 * This is what `InventoryAdjustmentReasonRepository::ensureCatalogue()` did, called from
 * `AdjustmentController::form():157` — a GET action creating the rows it was about to render. The
 * cost of that was the sharpest of the set: the adjustment screen is a REASON-FIRST form, so with
 * no reasons there is no form at all, and every other screen that reads a reason code read nothing.
 *
 * ## Matched on `code`, existing rows untouched
 *
 * Straight from the repository method this replaces, and its reasoning holds unchanged: these are
 * configurable rows. An admin who has relabelled "Lost / shrinkage", deactivated "Scrapped" or set
 * a G/L account on "Damaged" must not have it put back — a find-or-UPDATE would do exactly that.
 * The seed is a floor, not a template.
 *
 * ## What changed, and it is the deletion rule
 *
 * `ensureCatalogue()` ran on every render, so a code deleted outright came straight back — stated
 * in its docblock as deliberate, on the grounds that the screen cannot offer "Damaged" if the row is
 * gone. That is no longer true of this seeder and could not be: once
 * `inventory_depth.adjustment_reason` is marked in `reference_data_seed_mark` it is never called
 * again, so a deleted reason stays deleted. Deactivating is still the supported way to take one off
 * the list — `InventoryAdjustmentReason::$active` exists for it — and the difference now is only
 * that deleting works too, permanently, which is what deleting ought to mean.
 */
final class InventoryAdjustmentReasonSeeder implements ReferenceDataSeederInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InventoryAdjustmentReasonRepository $reasons,
    ) {
    }

    public function getKey(): string
    {
        return 'inventory_depth.adjustment_reason';
    }

    public function getLabel(): string
    {
        return 'Inventory adjustment reasons';
    }

    public function seed(): int
    {
        $created = 0;

        foreach (InventoryAdjustmentReason::defaults() as $index => $default) {
            if ($this->reasons->findOneBy(['code' => $default['code']]) instanceof InventoryAdjustmentReason) {
                continue;
            }

            $this->em->persist(
                (new InventoryAdjustmentReason())
                    ->setCode($default['code'])
                    ->setLabel($default['label'])
                    ->setFromStatus($default['from'])
                    ->setToStatus($default['to'])
                    ->setReversal($default['reversal'])
                    ->setHelp($default['help'])
                    // The shipped order, spaced so an admin can insert between two of them without
                    // renumbering — see InventoryAdjustmentReason::$sortKey.
                    ->setSortKey(($index + 1) * 10)
                    ->setActive(true)
            );
            $created++;
        }

        return $created;
    }
}
