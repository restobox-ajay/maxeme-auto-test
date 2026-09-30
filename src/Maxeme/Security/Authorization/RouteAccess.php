<?php

declare(strict_types=1);

namespace App\Maxeme\Security\Authorization;

use App\Maxeme\Security\Permission;

/** One Maxeme route and what its action requires; `permission` null means #[PubliclyAccessible]. */
final class RouteAccess
{
    /** @param list<string> $methods */
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly array $methods,
        public readonly ?string $permission,
    ) {
    }

    public function isPublic(): bool
    {
        return $this->permission === null;
    }

    /** The area key (Permission::AREAS), or 'public'. */
    public function area(): string
    {
        return $this->permission === null ? 'public' : Permission::areaOf($this->permission);
    }
}
