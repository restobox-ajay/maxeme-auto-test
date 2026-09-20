<?php

declare(strict_types=1);

namespace App\Service\ReferenceData;

use App\Contract\ReferenceData\ReferenceDataSeederInterface;
use App\Entity\Fee;
use App\Repository\FeeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The shipped `fee` rows a fee bundle owns, seeded once.
 *
 * A base class in core rather than five copies in five bundles, on the precedent of
 * {@see \App\Service\Onboarding\Checks\AbstractSettingsFilledCheck}, and in core specifically
 * because `Fee` and {@see FeeRepository} are core's — a bundle extending this adds a subclass
 * declaring two constants' worth of facts and nothing else.
 *
 * ## What it replaces
 *
 * Each fee bundle's config screen opened with a loop of `FeeRepository::ensureBySlug()` — a GET
 * action creating the very rows it was about to render (`BCTireFeeConfigController::config():48`
 * and its four siblings). Until somebody opened that screen the fee did not exist as a row, so
 * Product → Fees had nothing to offer and an admin could not attach the fee to anything.
 *
 * ## Matched on slug, and an existing row is never touched
 *
 * `fee.slug` is what the calculators look the row up by, and the row is configuration: the name,
 * the value, the tax class and the placement are all admin-editable on that same screen. Restating
 * any of them would silently undo a rate somebody set, so this creates a missing slug and steps
 * over a present one. Per-slug rather than the whole-table check {@see Seeders\UnitOfMeasureSeeder}
 * uses, because these rows are added to over time — a bundle that ships a second fee wants that fee
 * created beside the one the customer already has, and (unlike the unit list) no screen offers a
 * delete, so there is no deliberate deletion to preserve.
 *
 * ## The fee calculators still call `ensureBySlug()` and that is deliberate
 *
 * Removing it there was not in scope and would not be safe: a calculator that cannot find its row
 * mid-order would silently stop charging the fee. Those calls now find the row this seeder created
 * and write nothing. They remain the one lazy write left on this path — reported, not fixed here.
 */
abstract class AbstractFeeCatalogueSeeder implements ReferenceDataSeederInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FeeRepository $fees,
    ) {
    }

    /**
     * The bundle's `FEES` constant, verbatim.
     *
     * @return list<array{slug: string, name: string, taxClass: string, defaultValue?: float}>
     */
    abstract protected function definitions(): array;

    /** The bundle's calculator `SOURCE`, which is what `FeeRepository::findBySource()` groups by. */
    abstract protected function source(): string;

    final public function seed(): int
    {
        $created = 0;

        foreach ($this->definitions() as $definition) {
            if ($this->fees->findBySlug($definition['slug']) instanceof Fee) {
                continue;
            }

            $this->em->persist(
                (new Fee())
                    ->setSlug($definition['slug'])
                    ->setName($definition['name'])
                    ->setTaxClass($definition['taxClass'])
                    ->setDefaultValue($definition['defaultValue'] ?? 0.0)
                    ->setSource($this->source())
            );
            $created++;
        }

        return $created;
    }
}
