<?php

declare(strict_types=1);

namespace TaxCanadaSimpleBundle\ReferenceData;

use App\Contract\ReferenceData\ReferenceDataSeederInterface;
use App\Entity\SalesTax;
use App\Repository\SalesTaxRepository;
use Doctrine\ORM\EntityManagerInterface;
use TaxCanadaSimpleBundle\Tax\CanadaSimpleTaxCalculator;

/**
 * The `sales_tax` rows for every non-BC province.
 *
 * This is what `CanadaSimpleTaxConfigController::config():29` did on its way to rendering them — a
 * GET/POST action whose GET half created eleven rows. Until somebody opened that screen (or a
 * customer in one of those provinces placed an order, which reaches the same lazy write through the
 * calculator), the admin Sales Tax list had nothing in it for those provinces and nobody could see
 * what the system believed the rates were.
 *
 * ## Matched on slug, existing rows never restated
 *
 * The slug is what {@see SalesTaxRepository::getBySlug()} and the calculator both look these up by,
 * and the row is editable on the config screen — the rate and the Active/Inactive status are the
 * whole point of it. A seeder that restated the rate would silently undo a provincial rate change
 * an accountant had entered, and would do it on somebody's login. So a missing slug is created and
 * a present one is stepped over, rate and status untouched.
 *
 * ## GST is one federal row, not one per province
 *
 * Straight from `ensureProvinceRows()`: GST is identical everywhere it applies, so every province
 * that has it points at the single `gst` slug rather than getting a copy. Five of the nine provinces
 * below name it, which is why the created count is eleven rows and not fourteen.
 *
 * The calculator still calls `ensureProvinceRows()` during a tax calculation. That was left alone
 * deliberately — a calculator that cannot find its row mid-order would compute no tax — and it now
 * finds these rows and writes nothing.
 */
final class CanadaSimpleTaxSeeder implements ReferenceDataSeederInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SalesTaxRepository $salesTaxes,
    ) {
    }

    public function getKey(): string
    {
        return 'tax_canada_simple.province_rates';
    }

    public function getLabel(): string
    {
        return 'Canadian (non-BC) sales tax rates';
    }

    public function seed(): int
    {
        $created = 0;

        // Slugs this call has already persisted. Necessary because nothing is flushed until the
        // central seeder commits, so findOneBy() below cannot see them: five provinces name the
        // single federal `gst` slug, and without this it would be persisted five times and the
        // flush would hit the unique index on sales_tax.slug.
        $persisted = [];

        foreach (CanadaSimpleTaxCalculator::PROVINCES as $provinceCode => $province) {
            foreach ($province['components'] as $component) {
                $isGst = $component['type'] === 'GST';
                $slug = $isGst
                    ? 'gst'
                    : CanadaSimpleTaxCalculator::slugFor($provinceCode, $component['type']);

                if (isset($persisted[$slug]) || $this->salesTaxes->findOneBy(['slug' => $slug]) instanceof SalesTax) {
                    continue;
                }

                $this->em->persist(
                    (new SalesTax())
                        ->setSlug($slug)
                        ->setProvinceName($isGst ? 'Canada (Federal)' : $province['name'])
                        ->setAbbreviation($isGst ? '' : $provinceCode)
                        ->setTaxType($component['type'])
                        ->setRate($component['rate'])
                        ->setStatus('Active')
                        ->setSource(CanadaSimpleTaxCalculator::SOURCE)
                );
                $persisted[$slug] = true;
                $created++;
            }
        }

        return $created;
    }
}
