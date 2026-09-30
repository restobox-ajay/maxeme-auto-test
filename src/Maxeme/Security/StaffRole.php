<?php

declare(strict_types=1);

namespace App\Maxeme\Security;

use App\Entity\AdminUser;

/**
 * The shop's staff roles, highest first. Each is a security role stored on the AdminUser, and what
 * it may do is its own file under config/rbac/role_permissions/ (permissionKey() names it).
 *
 * Admin is ROLE_SHOP_ADMIN rather than ROLE_ADMIN because core gives every AdminUser ROLE_ADMIN to
 * pass the /admin firewall, so ROLE_ADMIN cannot tell the roles apart. Tech Support is core's own
 * role (it inherits ROLE_SUPER_ADMIN in security.yaml).
 */
enum StaffRole: string
{
    case TechSupport = 'ROLE_TECH_SUPPORT';
    case SuperAdmin = 'ROLE_SUPER_ADMIN';
    case Admin = 'ROLE_SHOP_ADMIN';
    case SecretaryOne = 'ROLE_SECRETARY_1';
    case SecretaryTwo = 'ROLE_SECRETARY_2';
    case Receptionist = 'ROLE_RECEPTIONIST';
    case Technician = 'ROLE_TECHNICIAN';

    public function label(): string
    {
        return match ($this) {
            self::TechSupport => 'Tech Support',
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Admin',
            self::SecretaryOne => 'Secretary I',
            self::SecretaryTwo => 'Secretary II',
            self::Receptionist => 'Receptionist',
            self::Technician => 'Technician',
        };
    }

    /** The role's permission file: config/rbac/role_permissions/{key}_role_permission.php. */
    public function permissionKey(): string
    {
        return match ($this) {
            self::TechSupport => 'tech_support',
            self::SuperAdmin => 'super_admin',
            self::Admin => 'admin',
            self::SecretaryOne => 'secretary_1',
            self::SecretaryTwo => 'secretary_2',
            self::Receptionist => 'receptionist',
            self::Technician => 'technician',
        };
    }

    public static function fromPermissionKey(string $key): ?self
    {
        foreach (self::cases() as $role) {
            if ($role->permissionKey() === $key) {
                return $role;
            }
        }

        return null;
    }

    /**
     * The roles Manage Admins can give an account; Tech Support is the vendor's, set up outside the shop.
     *
     * @return list<self>
     */
    public static function assignable(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $role): bool => $role !== self::TechSupport));
    }

    /** The highest shop role among the account's stored roles, or null for an account with none. */
    public static function of(AdminUser $user): ?self
    {
        foreach (self::cases() as $role) {
            if (in_array($role->value, $user->getRoles(), true)) {
                return $role;
            }
        }

        return null;
    }

    /**
     * Maps a legacy (FOSUserBundle) role list to a role: ROLE_SUPER_ADMIN => Super Admin,
     * ROLE_ADMIN => Admin, anything else (the legacy view-only ROLE_USER) => Receptionist.
     */
    public static function fromLegacyRoles(array $legacyRoles): self
    {
        return match (true) {
            in_array('ROLE_SUPER_ADMIN', $legacyRoles, true) => self::SuperAdmin,
            in_array('ROLE_ADMIN', $legacyRoles, true) => self::Admin,
            default => self::Receptionist,
        };
    }
}
