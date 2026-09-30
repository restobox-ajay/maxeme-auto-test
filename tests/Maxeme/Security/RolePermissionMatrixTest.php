<?php

declare(strict_types=1);

namespace App\Tests\Maxeme\Security;

use App\Maxeme\Security\Authorization\PermissionChecker;
use App\Maxeme\Security\Authorization\RolePermissionFileLoader;
use App\Maxeme\Security\StaffRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shop's role matrix, asserted against the real config/rbac/role_permissions/ files: change a
 * file and this names the cell that moved; change the matrix and you edit it here too.
 *
 * E = Edit, V = View, - = no access.
 */
final class RolePermissionMatrixTest extends TestCase
{
    private const AREAS = ['appointment', 'reminder', 'service', 'parts', 'work-order', 'accounting', 'people', 'car', 'staff'];

    /** @var array<string, string> role => one letter per AREAS entry */
    private const MATRIX = [
        //                        appt rem svc parts wo acct people car staff
        'ROLE_TECH_SUPPORT' => 'EEEEEEEEE',
        'ROLE_SUPER_ADMIN' => 'EEEEEEEEE',
        'ROLE_SHOP_ADMIN' => 'EEEEEEEEE',
        'ROLE_SECRETARY_1' => 'EEEEEEEE-',
        'ROLE_SECRETARY_2' => 'EEEEEVEE-',
        'ROLE_RECEPTIONIST' => 'EEVVVVEE-',
        'ROLE_TECHNICIAN' => 'VVVVE--E-',
    ];

    private static function loader(): RolePermissionFileLoader
    {
        return new RolePermissionFileLoader([dirname(__DIR__, 3) . '/config/rbac/role_permissions']);
    }

    public function testEveryRoleIsInTheMatrix(): void
    {
        self::assertSame(array_map(static fn (StaffRole $role): string => $role->value, StaffRole::cases()), array_keys(self::MATRIX));
    }

    /** @return iterable<string, array{StaffRole, string, string}> */
    public static function cells(): iterable
    {
        foreach (self::MATRIX as $role => $row) {
            foreach (self::AREAS as $i => $area) {
                yield sprintf('%s %s', $role, $area) => [StaffRole::from($role), $area, $row[$i]];
            }
        }
    }

    #[DataProvider('cells')]
    public function testTheRoleFileGrantsExactlyItsCell(StaffRole $role, string $area, string $access): void
    {
        $granted = self::loader()->permissionsFor($role);

        self::assertSame($access !== '-', PermissionChecker::grants($granted, "/{$area}/view"), 'view');
        self::assertSame($access === 'E', PermissionChecker::grants($granted, "/{$area}/edit"), 'edit');
    }

    #[DataProvider('roles')]
    public function testEveryRoleManagesItsOwnAccount(StaffRole $role): void
    {
        self::assertTrue(PermissionChecker::grants(self::loader()->permissionsFor($role), '/account/manage'));
    }

    /** @return iterable<string, array{StaffRole}> */
    public static function roles(): iterable
    {
        foreach (StaffRole::cases() as $role) {
            yield $role->label() => [$role];
        }
    }

    /** Manage Admins beyond the matrix: Admin and above add accounts (StaffAccountVoter guards Super Admin ones). */
    #[DataProvider('roles')]
    public function testOnlyAdminAndAboveAddAccounts(StaffRole $role): void
    {
        $granted = self::loader()->permissionsFor($role);
        $adminOrAbove = in_array($role, [StaffRole::TechSupport, StaffRole::SuperAdmin, StaffRole::Admin], true);

        self::assertSame($adminOrAbove, PermissionChecker::grants($granted, '/staff/create'));
    }

    public function testWildcardMatching(): void
    {
        self::assertTrue(PermissionChecker::grants(['/parts/*'], '/parts/edit'));
        self::assertTrue(PermissionChecker::grants(['/*'], '/anything/at-all'));
        self::assertFalse(PermissionChecker::grants(['/parts/view'], '/parts/edit'));
        self::assertFalse(PermissionChecker::grants(['/parts/*'], '/people/view'));
    }
}
