<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Checks;

use App\Contract\Onboarding\OnboardingCheckInterface;
use App\Contract\Onboarding\OnboardingCheckResult;
use App\Entity\AdminUser;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "check at least 1 tech support user - dont show detail s[j]ust yes / no" (#426).
 *
 * The message is a fixed yes/no-shaped string in both branches, deliberately never including a
 * name, email, or count — Tech Support accounts have elevated access (error log, database
 * console, mail queue — see AdminSystemCest/SystemController) and this page is visible to every
 * plain admin, not just Tech Support itself.
 */
final class TechSupportUserExistsCheck implements OnboardingCheckInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getKey(): string
    {
        return 'tech_support_exists';
    }

    public function getGroup(): string
    {
        return 'Staff Users';
    }

    public function getLabel(): string
    {
        return 'At Least 1 Tech Support User';
    }

    public function getSortOrder(): int
    {
        return 20;
    }

    public function run(): OnboardingCheckResult
    {
        $connection = $this->entityManager->getConnection();
        if (!$connection->createSchemaManager()->tablesExist(['admin_user'])) {
            return OnboardingCheckResult::fail('The admin_user table is missing. Run migrations (php bin/console doctrine:migrations:migrate).');
        }

        /** @var list<AdminUser> $admins */
        $admins = $this->entityManager->getRepository(AdminUser::class)->findBy(['status' => 'Active']);

        foreach ($admins as $admin) {
            if (in_array('ROLE_TECH_SUPPORT', $admin->getRoles(), true)) {
                return OnboardingCheckResult::pass('Yes');
            }
        }

        return OnboardingCheckResult::fail('No — no active Tech Support user exists.');
    }
}
