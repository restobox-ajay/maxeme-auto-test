<?php

declare(strict_types=1);

namespace App\Tests\Service\Onboarding\Checks;

use App\Entity\AdminUser;
use App\Service\DocumentActor;
use App\Service\Onboarding\Checks\SuperAdminExistsCheck;
use App\Tests\DoctrineIntegrationTestCase;

/**
 * #426: "check at least 1 superadmin user". Adversarial focus: the many ways a Super Admin row
 * can exist yet not actually count — inactive, wrong role, legacy role spelling must still count.
 */
final class SuperAdminExistsCheckTest extends DoctrineIntegrationTestCase
{
    private function makeAdmin(array $roles, string $status = 'Active', string $email = 'admin@example.test'): AdminUser
    {
        static $n = 0;
        $n++;
        $admin = (new AdminUser())
            ->setEmail($n . '-' . $email)
            ->setPassword('irrelevant-hash')
            ->setRoles($roles);
        $admin->setStatus($status, DocumentActor::system());
        $this->em->persist($admin);
        $this->em->flush();

        return $admin;
    }

    public function testNoAdminUsersAtAllFails(): void
    {
        self::assertFalse((new SuperAdminExistsCheck($this->em))->run()->passed);
    }

    public function testOrdinaryAdminWithNoSuperAdminRoleFails(): void
    {
        $this->makeAdmin([]);

        self::assertFalse((new SuperAdminExistsCheck($this->em))->run()->passed);
    }

    public function testActiveSuperAdminPasses(): void
    {
        $this->makeAdmin(['ROLE_SUPER_ADMIN']);

        self::assertTrue((new SuperAdminExistsCheck($this->em))->run()->passed);
    }

    public function testLegacyRoleSpellingStillCounts(): void
    {
        // UserController::normalizeRoleLabel() still recognizes the legacy 'Superadmin' label /
        // 'ROLE_SUPERADMIN' role string — this check must not regress behind that.
        $this->makeAdmin(['ROLE_SUPERADMIN']);

        self::assertTrue((new SuperAdminExistsCheck($this->em))->run()->passed);
    }

    public function testInactiveSuperAdminDoesNotCount(): void
    {
        $this->makeAdmin(['ROLE_SUPER_ADMIN'], 'Inactive');

        self::assertFalse((new SuperAdminExistsCheck($this->em))->run()->passed, 'a deactivated Super Admin must not satisfy the check');
    }

    public function testTechSupportRoleAloneDoesNotCountAsSuperAdmin(): void
    {
        $this->makeAdmin(['ROLE_TECH_SUPPORT']);

        self::assertFalse((new SuperAdminExistsCheck($this->em))->run()->passed);
    }

    public function testOneInactiveAndOneActiveSuperAdminStillPasses(): void
    {
        $this->makeAdmin(['ROLE_SUPER_ADMIN'], 'Inactive', 'inactive@example.test');
        $this->makeAdmin(['ROLE_SUPER_ADMIN'], 'Active', 'active@example.test');

        self::assertTrue((new SuperAdminExistsCheck($this->em))->run()->passed);
    }
}
