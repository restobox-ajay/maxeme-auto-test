<?php

declare(strict_types=1);

namespace App\Maxeme\Security\Authorization;

use App\Entity\AdminUser;
use App\Maxeme\Security\Permission;
use App\Maxeme\Security\StaffRole;
use App\Maxeme\Service\StaffAccountService;

/**
 * Config › Roles & Access: what each role's file grants, summarised per area of the matrix and
 * expanded to the routes it reaches. Read-only on purpose: a role changes by editing its file under
 * config/rbac/role_permissions/, the one source PermissionChecker reads.
 */
final class RoleAccessReport
{
    public function __construct(
        private readonly RolePermissionFileLoader $files,
        private readonly RouteAccessMap $routes,
        private readonly StaffAccountService $accounts,
    ) {
    }

    /** @return list<string> the role's file, as written */
    public function permissions(StaffRole $role): array
    {
        return $this->files->permissionsFor($role);
    }

    /**
     * Per area: 'Edit' (every action), 'View' (view only), the granted action names for a partial
     * grant, or null for none.
     *
     * @return array<string, ?string> area key => level
     */
    public function levels(StaffRole $role): array
    {
        $granted = $this->permissions($role);
        $levels = [];

        foreach (Permission::byArea() as $area => $actions) {
            $held = array_values(array_filter($actions, static fn (string $action): bool => PermissionChecker::grants($granted, $action)));
            $levels[$area] = match (true) {
                $held === [] => null,
                count($held) === count($actions) => 'Edit',
                $held === ["/{$area}/view"] => 'View',
                default => implode(', ', array_map(static fn (string $action): string => ucfirst(substr($action, strrpos($action, '/') + 1)), $held)),
            };
        }

        return $levels;
    }

    /**
     * Every Maxeme route by area (AREAS order, public last), each with whether the role reaches it.
     *
     * @return array<string, list<array{route: RouteAccess, allowed: bool}>> area key => rows
     */
    public function routes(StaffRole $role): array
    {
        $granted = $this->permissions($role);
        $grouped = array_fill_keys([...array_keys(Permission::AREAS), 'public'], []);

        foreach ($this->routes->all() as $route) {
            $grouped[$route->area()][] = [
                'route' => $route,
                'allowed' => $route->isPublic() || PermissionChecker::grants($granted, $route->permission),
            ];
        }

        return array_filter($grouped);
    }

    public function allowedRouteCount(StaffRole $role): int
    {
        return count(array_filter(array_merge(...array_values($this->routes($role))), static fn (array $row): bool => $row['allowed']));
    }

    public function routeCount(): int
    {
        return count($this->routes->all());
    }

    /** @return list<AdminUser> active accounts holding the role */
    public function accounts(StaffRole $role): array
    {
        return array_values(array_filter($this->accounts->activeAccounts(), static fn (AdminUser $user): bool => StaffRole::of($user) === $role));
    }
}
