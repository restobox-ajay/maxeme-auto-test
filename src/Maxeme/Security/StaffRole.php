<?php

declare(strict_types=1);

namespace App\Maxeme\Security;

use App\Entity\AdminUser;

/**
 * The shop's staff tiers, each a security role (hierarchy in config/packages/security.yaml:
 * ROLE_SUPER_ADMIN > ROLE_MANAGER > ROLE_STAFF).
 *
 * The legacy app's ROLE_USER / ROLE_ADMIN / ROLE_SUPER_ADMIN map onto Staff / Admin / SuperAdmin.
 * Admin is ROLE_MANAGER rather than ROLE_ADMIN because core gives every AdminUser ROLE_ADMIN to
 * pass the /admin firewall, so ROLE_ADMIN cannot tell the tiers apart.
 *
 * Gate screens with the case value: #[IsGranted(StaffRole::MANAGER)] / is_granted('ROLE_MANAGER').
 */
enum StaffRole: string
{
    public const STAFF = 'ROLE_STAFF';
    public const MANAGER = 'ROLE_MANAGER';
    public const SUPER_ADMIN = 'ROLE_SUPER_ADMIN';

    case Staff = self::STAFF;
    case Admin = self::MANAGER;
    case SuperAdmin = self::SUPER_ADMIN;

    public function label(): string
    {
        return match ($this) {
            self::Staff => 'Staff',
            self::Admin => 'Admin',
            self::SuperAdmin => 'Super admin',
        };
    }

    /**
     * The role names the legacy app listed for this tier (Manage Admins' Role column).
     *
     * @return list<string>
     */
    public function legacyRoles(): array
    {
        return match ($this) {
            self::Staff => ['ROLE_USER'],
            self::Admin => ['ROLE_ADMIN', 'ROLE_USER'],
            self::SuperAdmin => ['ROLE_SUPER_ADMIN', 'ROLE_USER'],
        };
    }

    /** The highest tier among the account's stored roles (display only; access checks use is_granted()). */
    public static function of(AdminUser $user): self
    {
        return self::highestOf($user->getRoles(), self::SuperAdmin->value, self::Admin->value);
    }

    /** Maps a legacy (FOSUserBundle) role list to its tier. */
    public static function fromLegacyRoles(array $legacyRoles): self
    {
        return self::highestOf($legacyRoles, 'ROLE_SUPER_ADMIN', 'ROLE_ADMIN');
    }

    /** @return array<string, string> value => label */
    public static function choices(): array
    {
        $choices = [];
        foreach (self::cases() as $case) {
            $choices[$case->value] = $case->label();
        }

        return $choices;
    }

    /** @param list<string> $roles */
    private static function highestOf(array $roles, string $superAdminRole, string $adminRole): self
    {
        return match (true) {
            in_array($superAdminRole, $roles, true) || in_array('ROLE_TECH_SUPPORT', $roles, true) => self::SuperAdmin,
            in_array($adminRole, $roles, true) => self::Admin,
            default => self::Staff,
        };
    }
}
