<?php

declare(strict_types=1);

namespace App\Service\Onboarding\Checks;

use App\Contract\Onboarding\OnboardingCheckInterface;
use App\Contract\Onboarding\OnboardingCheckResult;
use App\Entity\AdminUser;
use Doctrine\ORM\EntityManagerInterface;

/**
 * "check at least 1 superadmin user" (#426). An install with zero active Super Admins has no one
 * who can promote anyone else — the same failure mode UserController::isTheOnlyActiveSuperAdmin()
 * guards against on the staff user screen, checked here as a standalone launch-readiness signal.
 *
 * Matches both role spellings the app has carried ('ROLE_SUPER_ADMIN' is current,
 * 'ROLE_SUPERADMIN' is the legacy value UserController::normalizeRoleLabel() still recognizes).
 */
final class SuperAdminExistsCheck implements OnboardingCheckInterface
{
    private const SUPERADMIN_ROLES = ['ROLE_SUPER_ADMIN', 'ROLE_SUPERADMIN'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function getKey(): string
    {
        return 'superadmin_exists';
    }

    public function getGroup(): string
    {
        return 'Staff Users';
    }

    public function getLabel(): string
    {
        return 'At Least 1 Super Admin User';
    }

    public function getSortOrder(): int
    {
        return 10;
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
            if (array_intersect($admin->getRoles(), self::SUPERADMIN_ROLES) !== []) {
                return OnboardingCheckResult::pass();
            }
        }

        return OnboardingCheckResult::fail('No active Super Admin user exists. Create or promote one under Users > Staff Users.');
    }
}
