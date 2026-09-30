<?php

declare(strict_types=1);

namespace App\Tests\Maxeme\Security;

use App\Entity\AdminUser;
use App\Maxeme\Security\StaffRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StaffRoleTest extends TestCase
{
    /** @return iterable<string, array{list<string>, StaffRole}> */
    public static function legacyRoleLists(): iterable
    {
        yield 'no roles (FOS ROLE_USER only)' => [[], StaffRole::Receptionist];
        yield 'ROLE_USER' => [['ROLE_USER'], StaffRole::Receptionist];
        yield 'ROLE_ADMIN' => [['ROLE_ADMIN'], StaffRole::Admin];
        yield 'ROLE_SUPER_ADMIN wins over ROLE_ADMIN' => [['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], StaffRole::SuperAdmin];
    }

    /** @param list<string> $legacyRoles */
    #[DataProvider('legacyRoleLists')]
    public function testMapsLegacyRoles(array $legacyRoles, StaffRole $expected): void
    {
        self::assertSame($expected, StaffRole::fromLegacyRoles($legacyRoles));
    }

    public function testReadsTheRoleFromTheAccountDespiteTheForcedRoleAdmin(): void
    {
        // AdminUser::getRoles() adds ROLE_ADMIN to every account; it must not read as the Admin role.
        self::assertNull(StaffRole::of(new AdminUser()));
        self::assertSame(StaffRole::Technician, StaffRole::of((new AdminUser())->setRoles([StaffRole::Technician->value])));
        self::assertSame(StaffRole::Admin, StaffRole::of((new AdminUser())->setRoles([StaffRole::Admin->value])));
        self::assertSame(StaffRole::TechSupport, StaffRole::of((new AdminUser())->setRoles(['ROLE_SUPER_ADMIN', 'ROLE_TECH_SUPPORT'])));
    }

    public function testTechSupportCannotBeGivenFromManageAdmins(): void
    {
        self::assertNotContains(StaffRole::TechSupport, StaffRole::assignable());
        self::assertCount(count(StaffRole::cases()) - 1, StaffRole::assignable());
    }
}
