<?php

declare(strict_types=1);

namespace App\Maxeme\Security\Authorization;

use App\Maxeme\Security\StaffRole;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads what a role grants from its own file, config/rbac/role_permissions/{key}_role_permission.php
 * (StaffRole::permissionKey()). One file per role so a role's access is read and changed in one
 * place; each returns a flat list of Permission names, `/area/*` or `/*`.
 *
 * Takes an ordered list of directories, first match wins, so a test can lay fixture files over the
 * real ones.
 */
final class RolePermissionFileLoader
{
    /** @var array<string, list<string>> */
    private array $loaded = [];

    /** @param list<string> $directories */
    public function __construct(
        #[Autowire(param: 'maxeme.rbac.role_permission_dirs')]
        private readonly array $directories,
    ) {
    }

    /**
     * @return list<string> the role's granted permissions
     *
     * @throws \LogicException when the role has no file: every StaffRole must have one
     */
    public function permissionsFor(StaffRole $role): array
    {
        $key = $role->permissionKey();

        if (!isset($this->loaded[$key])) {
            $this->loaded[$key] = $this->load($key);
        }

        return $this->loaded[$key];
    }

    /** @return list<string> */
    private function load(string $key): array
    {
        foreach ($this->directories as $directory) {
            $path = sprintf('%s/%s_role_permission.php', $directory, $key);
            if (is_file($path)) {
                $permissions = require $path;

                if (!is_array($permissions) || !array_is_list($permissions)) {
                    throw new \LogicException(sprintf('%s must return a list of permission names.', $path));
                }

                return $permissions;
            }
        }

        throw new \LogicException(sprintf('No %s_role_permission.php in %s.', $key, implode(', ', $this->directories)));
    }
}
