<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Checks;

use App\Contract\Onboarding\OnboardingCheckInterface;
use App\Contract\Onboarding\OnboardingCheckResult;
use App\Entity\SalesTax;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "check tax table is filled with at least 1 row. If not, open a app, tax, and click save once"
 * (#426) — a store with zero sales_tax rows charges no tax at all on every order, which is
 * almost never intentional on a real launch.
 */
final class TaxTableFilledCheck implements OnboardingCheckInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getKey(): string
    {
        return 'tax_table_filled';
    }

    public function getGroup(): string
    {
        return 'Catalog & Pricing';
    }

    public function getLabel(): string
    {
        return 'Sales Tax Table Has At Least 1 Row';
    }

    public function getSortOrder(): int
    {
        return 10;
    }

    public function run(): OnboardingCheckResult
    {
        $connection = $this->entityManager->getConnection();
        if (!$connection->createSchemaManager()->tablesExist(['sales_tax'])) {
            return OnboardingCheckResult::fail('The sales_tax table is missing. Run migrations (php bin/console doctrine:migrations:migrate).');
        }

        $count = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(SalesTax::class, 't')
            ->getQuery()
            ->getSingleScalarResult();

        if ($count < 1) {
            return OnboardingCheckResult::fail(
                'No sales tax rows exist yet. Open Settings > Sales Tax and click Save at least once.'
            );
        }

        return OnboardingCheckResult::pass();
    }
}
