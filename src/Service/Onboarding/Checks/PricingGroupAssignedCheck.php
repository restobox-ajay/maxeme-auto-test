<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Checks;

use App\Contract\Onboarding\OnboardingCheckInterface;
use App\Contract\Onboarding\OnboardingCheckResult;
use App\Entity\CompanyFulfillmentRegion;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "check at least 1 pricing group, assigned to 1 location" (#426). "Pricing group" is this app's
 * PriceList entity (see the "Pricing Groups" sidebar label / admin_price_list_index route);
 * "location" is a FulfillmentRegion. The two are joined per-company via CompanyFulfillmentRegion
 * (Company x FulfillmentRegion x nullable PriceList) — a PriceList existing on its own is not
 * enough, since nothing would ever apply it; it must actually be assigned on at least one
 * company/region mapping.
 */
final class PricingGroupAssignedCheck implements OnboardingCheckInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getKey(): string
    {
        return 'pricing_group_assigned';
    }

    public function getGroup(): string
    {
        return 'Catalog & Pricing';
    }

    public function getLabel(): string
    {
        return 'At Least 1 Pricing Group Assigned To A Location';
    }

    public function getSortOrder(): int
    {
        return 20;
    }

    public function run(): OnboardingCheckResult
    {
        $connection = $this->entityManager->getConnection();
        if (!$connection->createSchemaManager()->tablesExist(['company_fulfillment_region'])) {
            return OnboardingCheckResult::fail('The company_fulfillment_region table is missing. Run migrations (php bin/console doctrine:migrations:migrate).');
        }

        $count = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(CompanyFulfillmentRegion::class, 'r')
            ->andWhere('r.priceList IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();

        if ($count < 1) {
            return OnboardingCheckResult::fail(
                'No Pricing Group is assigned to any customer/location. Assign one under '
                . 'Products > Customer Pricing.'
            );
        }

        return OnboardingCheckResult::pass();
    }
}
