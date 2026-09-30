<?php

declare(strict_types=1);

namespace App\Maxeme\Security\Attribute;

/**
 * The permission (an App\Maxeme\Security\Permission constant) a Maxeme action, or every action of
 * a controller, requires. Enforced by PermissionEnforcementSubscriber; a method's attribute wins
 * over its class's.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final class RequiresPermission
{
    public function __construct(
        public readonly string $permission,
    ) {
    }
}
