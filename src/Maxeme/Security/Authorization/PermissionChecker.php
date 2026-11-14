<?php

declare(strict_types=1);

namespace App\Maxeme\Security\Authorization;

use App\Entity\AdminUser;
use App\Maxeme\Security\StaffRole;

/**
 * Whether an account holds a permission: any of its shop roles (StaffRole::allOf()) and that role's file. The
 * one place both PermissionVoter (is_granted('/area/view')) and PermissionEnforcementSubscriber
 * (#[RequiresPermission]) ask.
 *
 * Matching: the exact name, then `/area/*`, then `/*`. An account without a shop role holds nothing.
 */
final class PermissionChecker
{
    public function __construct(
        private readonly RolePermissionFileLoader $files,
    ) {
    }

    public function isGranted(AdminUser $user, string $permission): bool
    {
        // Several roles: allowed when any of them allows it (mockup 3d5095e).
        foreach (StaffRole::allOf($user) as $role) {
            if (self::grants($this->files->permissionsFor($role), $permission)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $granted */
    public static function grants(array $granted, string $permission): bool
    {
        if (in_array($permission, $granted, true) || in_array('/*', $granted, true)) {
            return true;
        }

        $segments = explode('/', trim($permission, '/'));
        while (count($segments) > 1) {
            array_pop($segments);
            if (in_array('/' . implode('/', $segments) . '/*', $granted, true)) {
                return true;
            }
        }

        return false;
    }
}
