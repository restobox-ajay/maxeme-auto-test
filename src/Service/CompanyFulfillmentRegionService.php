<?php

namespace App\Service;

use App\Entity\Company;
use App\Entity\CompanyFulfillmentRegion;
use App\Entity\FulfillmentRegion;
use App\Entity\PriceList;
use App\Validation\Constraint\ValidFulfillmentRegionActivation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Validation;

final class CompanyFulfillmentRegionService
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    /** Creates an inactive CompanyFulfillmentRegion row for every existing region, for a newly created company. */
    public function backfillForNewCompany(Company $company): void
    {
        $regions = $this->entityManager->getRepository(FulfillmentRegion::class)->findAll();
        foreach ($regions as $region) {
            $this->ensureRow($company, $region);
        }
    }

    /** Creates an inactive CompanyFulfillmentRegion row for every existing company, for a newly created region. */
    public function backfillForNewRegion(FulfillmentRegion $region): void
    {
        $companies = $this->entityManager->getRepository(Company::class)->findAll();
        foreach ($companies as $company) {
            $this->ensureRow($company, $region);
        }
    }

    private function ensureRow(Company $company, FulfillmentRegion $region): void
    {
        $existing = $this->entityManager->getRepository(CompanyFulfillmentRegion::class)->findOneBy([
            'company' => $company,
            'fulfillmentRegion' => $region,
        ]);
        if ($existing instanceof CompanyFulfillmentRegion) {
            return;
        }

        $row = (new CompanyFulfillmentRegion())
            ->setCompany($company)
            ->setFulfillmentRegion($region)
            ->setStatus('Inactive');

        $this->entityManager->persist($row);
    }

    /** @return list<CompanyFulfillmentRegion> */
    public function activeRowsForCompany(Company $company): array
    {
        return $this->entityManager->getRepository(CompanyFulfillmentRegion::class)->findBy(
            ['company' => $company, 'status' => 'Active'],
            ['id' => 'ASC']
        );
    }

    /**
     * Resolves the price list for the given region name (case-insensitive, matching how
     * availableQuantityForProduct() matches region names) among the company's active rows.
     * With no region name given (cart/checkout/home have no per-request region context, and a
     * brand-new admin order has no region picked yet), this only resolves unambiguously when the
     * company has exactly one active region.
     */
    public function priceListForCompanyRegion(Company $company, ?string $regionName): ?PriceList
    {
        $activeRows = $this->activeRowsForCompany($company);
        if ($activeRows === []) {
            return null;
        }

        $regionName = trim((string) $regionName);
        if ($regionName !== '') {
            foreach ($activeRows as $row) {
                if (strcasecmp($row->getFulfillmentRegion()->getName(), $regionName) === 0) {
                    return $row->getPriceList();
                }
            }

            return null;
        }

        return count($activeRows) === 1 ? $activeRows[0]->getPriceList() : null;
    }

    /**
     * Shared validation rule: a row cannot be saved as Active without a price list.
     *
     * @return list<string>
     */
    public function validate(CompanyFulfillmentRegion $row): array
    {
        return $this->validateActivation($row->isActive(), $row->getPriceList() !== null, $row->getFulfillmentRegion()->getName());
    }

    /**
     * Same rule as validate(), usable before a CompanyFulfillmentRegion row exists yet
     * (e.g. validating raw submitted form data ahead of persisting anything).
     *
     * Ported to a symfony/validator constraint for #317, following ValidRedirect's #222/#305
     * pattern: ValidFulfillmentRegionActivationValidator runs the same rule this method used to
     * run by hand, so a future fix is inherited by CompanyController,
     * CompanyFulfillmentRegionController and ConfigController::guestFulfillmentRegions() without
     * any of them changing anything.
     *
     * @return list<string>
     */
    public function validateActivation(bool $active, bool $hasPriceList, string $regionName): array
    {
        $violations = Validation::createValidator()->validate($active, new ValidFulfillmentRegionActivation($hasPriceList, $regionName));

        return array_map(static fn ($violation): string => (string) $violation->getMessage(), iterator_to_array($violations));
    }
}
