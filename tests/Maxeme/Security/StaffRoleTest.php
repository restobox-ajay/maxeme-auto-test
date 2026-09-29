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
        yield 'no roles (FOS ROLE_USER only)' => [[], StaffRole::Staff];
        yield 'ROLE_USER' => [['ROLE_USER'], StaffRole::Staff];
        yield 'ROLE_ADMIN' => [['ROLE_ADMIN'], StaffRole::Admin];
        yield 'ROLE_SUPER_ADMIN wins over ROLE_ADMIN' => [['ROLE_USER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], StaffRole::SuperAdmin];
    }

    /** @param list<string> $legacyRoles */
    #[DataProvider('legacyRoleLists')]
    public function testMapsLegacyRoles(array $legacyRoles, StaffRole $expected): void
    {
        self::assertSame($expected, StaffRole::fromLegacyRoles($legacyRoles));
    }

    public function testReadsTheTierFromTheAccountDespiteTheForcedRoleAdmin(): void
    {
        // AdminUser::getRoles() adds ROLE_ADMIN to every account; it must not read as the Admin tier.
        self::assertSame(StaffRole::Staff, StaffRole::of((new AdminUser())->setRoles([StaffRole::Staff->value])));
        self::assertSame(StaffRole::Admin, StaffRole::of((new AdminUser())->setRoles([StaffRole::Admin->value])));
        self::assertSame(StaffRole::SuperAdmin, StaffRole::of((new AdminUser())->setRoles(['ROLE_TECH_SUPPORT'])));
    }

    public function testLegacyRoleListsMatchTheLegacyManageAdminsColumn(): void
    {
        self::assertSame(['ROLE_USER'], StaffRole::Staff->legacyRoles());
        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], StaffRole::Admin->legacyRoles());
        self::assertSame(['ROLE_SUPER_ADMIN', 'ROLE_USER'], StaffRole::SuperAdmin->legacyRoles());
    }
}
