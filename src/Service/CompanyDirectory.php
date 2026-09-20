<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Company;
use App\Repository\CompanyRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Find-or-create a Company by name — the one real implementation, extracted from
 * Number1CustomerImportBundle\Service\CustomerImportService::resolveCompany() so a second importer
 * (WooCommerceBundle's order connector, #739) does not hand-roll its own copy of "match a company by
 * name, or mint a minimal one." Every caller that needs this goes through here; a caller with its
 * own bookkeeping (the CSV importer's per-row created/updated counters) calls findByName() first and
 * only calls create() when it returns null, so it still knows which happened without this class
 * needing to care.
 */
final class CompanyDirectory
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly CompanyCodeGenerator $companyCodeGenerator,
    ) {
    }

    public function findByName(string $name): ?Company
    {
        return $this->companies->findOneByNameInsensitive($name);
    }

    /** Always mints a new row — call findByName() first if an existing one should be reused. */
    public function create(EntityManagerInterface $entityManager, string $name, string $accountType = 'Business'): Company
    {
        // No setStatus() call: 'Active' is the entity's own constructor default, and this always
        // mints a fresh, not-yet-persisted row.
        $company = (new Company())
            ->setName($name)
            ->setAccountType($accountType);
        $company->setCode($this->companyCodeGenerator->generate($entityManager, $name));

        $entityManager->persist($company);
        $entityManager->flush();

        return $company;
    }

    /** Convenience for a caller with no bookkeeping need to tell "found" apart from "created". */
    public function findOrCreateByName(EntityManagerInterface $entityManager, string $name, string $accountType = 'Business'): Company
    {
        return $this->findByName($name) ?? $this->create($entityManager, $name, $accountType);
    }
}
