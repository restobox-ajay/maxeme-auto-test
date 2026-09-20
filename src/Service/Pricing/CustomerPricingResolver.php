<?php

declare(strict_types=1);

namespace App\Service\Pricing;

use App\Entity\Company;
use App\Entity\CustomerUser;
use App\Entity\FulfillmentRegion;
use App\Entity\PriceList;
use App\Entity\ProductCore;
use App\Entity\ProductPricing;
use App\Enum\ProductStatus;
use App\Service\CompanyFulfillmentRegionService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What a given buyer pays for a given product — the storefront's pricing engine, as a service.
 *
 * It lived as a dozen protected methods on AbstractCustomerController, which meant its real inputs
 * (the company, resolved from the security token) were never in any signature. Admin order screens
 * therefore could not call it and re-derived prices from posted form fields instead, and nothing
 * could be exercised without booting a controller.
 *
 * Everything here takes its inputs explicitly: no session, no security token, no request. The one
 * piece that unavoidably starts from the logged-in user, companyForPricing(), takes that user as an
 * argument rather than fetching it, so the caller that has a security context keeps it.
 *
 * Pricing itself is reached through for(), which binds a company and region into a
 * CustomerPricingScope. That is the unit the price list belongs to, and holding one across a whole
 * document is what step 5 of issue #165 needs in order to price a Cart.
 */
final class CustomerPricingResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CompanyFulfillmentRegionService $companyFulfillmentRegionService,
    ) {}

    /**
     * Binds a buyer and a region into a scope that prices any number of products against one
     * resolved price list. A null company is a guest.
     */
    public function for(?Company $company, ?string $regionName): CustomerPricingScope
    {
        return new CustomerPricingScope($this, $company, $regionName);
    }

    /**
     * The company a logged-in user is priced as. Deliberately re-reads the user from the database
     * rather than trusting the one on the token, whose company association may have been detached
     * or changed since the session started, and falls back to matching an Active company by primary
     * email — which is how a customer user created without a company link still prices correctly.
     *
     * Takes the user rather than reading a security context so that the engine stays callable from
     * admin code and from a Cart, neither of which has a customer token to read.
     */
    public function companyForPricing(?object $user): ?Company
    {
        if (!$user instanceof CustomerUser) {
            return null;
        }

        $email = strtolower(trim($user->getEmail()));
        $userId = $user->getId();

        $freshUser = null;
        if ($userId !== null) {
            $freshUser = $this->entityManager->getRepository(CustomerUser::class)
                ->createQueryBuilder('customerUser')
                ->leftJoin('customerUser.company', 'company')
                ->addSelect('company')
                ->andWhere('customerUser.id = :id')
                ->setParameter('id', $userId)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
        }

        if (!$freshUser instanceof CustomerUser && $email !== '') {
            $freshUser = $this->entityManager->getRepository(CustomerUser::class)
                ->createQueryBuilder('customerUser')
                ->leftJoin('customerUser.company', 'company')
                ->addSelect('company')
                ->andWhere('LOWER(customerUser.email) = :email')
                ->setParameter('email', $email)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
        }

        $company = $freshUser instanceof CustomerUser ? $freshUser->getCompany() : $user->getCompany();
        if ($company instanceof Company) {
            return $company;
        }

        if ($email === '') {
            return null;
        }

        $matchedCompany = $this->entityManager->getRepository(Company::class)
            ->createQueryBuilder('company')
            ->andWhere('LOWER(company.primaryEmail) = :email')
            ->andWhere('company.status = :status')
            ->setParameter('email', $email)
            ->setParameter('status', 'Active')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $matchedCompany instanceof Company ? $matchedCompany : null;
    }

    /** @return list<FulfillmentRegion> */
    public function guestVisibleFulfillmentRegions(): array
    {
        return $this->entityManager->getRepository(FulfillmentRegion::class)->findBy(
            ['guestVisible' => true],
            ['name' => 'ASC']
        );
    }

    /**
     * Resolves a region name (as carried in the session pricing context) to its entity,
     * case-insensitively — callers that hold cart rows need the entity, not just the name.
     */
    public function resolveFulfillmentRegionEntity(?string $regionName): ?FulfillmentRegion
    {
        $regionName = trim((string) $regionName);
        if ($regionName === '') {
            return null;
        }

        foreach ($this->entityManager->getRepository(FulfillmentRegion::class)->findAll() as $region) {
            if (strcasecmp($region->getName(), $regionName) === 0) {
                return $region;
            }
        }

        return null;
    }

    /** @internal price list lookups belong to a scope; these are the raw queries behind it */
    public function priceListForCompanyRegion(Company $company, ?string $regionName): ?PriceList
    {
        return $this->companyFulfillmentRegionService->priceListForCompanyRegion($company, $regionName);
    }

    /**
     * Guest counterpart to priceListForCompanyRegion(): the named region's guestPriceList among
     * guest-visible regions, or — with no region named — only when exactly one region is guest
     * visible, since anything else would be a guess at which price the visitor should see.
     *
     * @internal see priceListForCompanyRegion()
     */
    public function priceListForGuestRegion(?string $regionName): ?PriceList
    {
        $guestRegions = $this->guestVisibleFulfillmentRegions();
        if ($guestRegions === []) {
            return null;
        }

        $regionName = trim((string) $regionName);
        if ($regionName !== '') {
            foreach ($guestRegions as $region) {
                if (strcasecmp($region->getName(), $regionName) === 0) {
                    return $region->getGuestPriceList();
                }
            }

            return null;
        }

        return count($guestRegions) === 1 ? $guestRegions[0]->getGuestPriceList() : null;
    }

    /** @internal see CustomerPricingScope::pricingFor() */
    public function findPricing(ProductCore $product, PriceList $priceList): ?ProductPricing
    {
        $pricing = $this->entityManager->getRepository(ProductPricing::class)->findOneBy([
            'product' => $product,
            'priceList' => $priceList,
        ]);

        return $pricing instanceof ProductPricing ? $pricing : null;
    }

    /**
     * Active, visible, undeleted product by SKU, excluding products made private to companies other
     * than $visibleTo. Price-list "Hide" is applied on top of this by the scope, since that rule is
     * a property of the price list rather than of the catalog.
     *
     * @internal see CustomerPricingScope::findPurchasableProduct()
     */
    public function findVisibleProductBySku(?Company $visibleTo, string $sku): ?ProductCore
    {
        $qb = $this->entityManager->getRepository(ProductCore::class)->createQueryBuilder('p')
            ->andWhere('p.sku = :sku')
            ->andWhere('p.visible = true')
            ->andWhere('p.deleted = false')
            ->andWhere('p.status = :status')
            ->setParameter('sku', $sku)
            ->setParameter('status', ProductStatus::Active->value);

        $companyId = $visibleTo?->getId();
        if ($companyId !== null) {
            $qb->leftJoin('p.privateCompanies', 'pcMatch', 'WITH', 'pcMatch.id = :companyId')
                ->andWhere('(SIZE(p.privateCompanies) = 0 OR pcMatch.id IS NOT NULL)')
                ->setParameter('companyId', $companyId);
        } else {
            $qb->andWhere('SIZE(p.privateCompanies) = 0');
        }

        $product = $qb->getQuery()->getOneOrNullResult();

        return $product instanceof ProductCore ? $product : null;
    }
}
