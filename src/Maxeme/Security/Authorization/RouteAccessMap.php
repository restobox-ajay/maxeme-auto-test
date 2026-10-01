<?php

declare(strict_types=1);

namespace App\Maxeme\Security\Authorization;

use App\Maxeme\EventSubscriber\PermissionEnforcementSubscriber;
use App\Maxeme\Security\Attribute\PubliclyAccessible;
use App\Maxeme\Security\Attribute\RequiresPermission;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\RouterInterface;

/**
 * Every Maxeme route with the permission its action declares, read from the same attributes
 * PermissionEnforcementSubscriber enforces, plus the core / module pages the shop uses with the
 * permission `maxeme.rbac.core_route_permissions` gives them. Backs Config › Roles & Access.
 */
final class RouteAccessMap
{
    private const CONTROLLER_NAMESPACE = 'App\\Maxeme\\Controller\\';

    /** @var list<RouteAccess>|null */
    private ?array $routes = null;

    /** @param array<string, list<string>> $coreRoutePermissions permission => core route names */
    public function __construct(
        private readonly RouterInterface $router,
        #[Autowire(param: 'maxeme.rbac.core_route_permissions')]
        private readonly array $coreRoutePermissions = [],
    ) {
    }

    /**
     * @return list<RouteAccess> by path
     *
     * @throws \LogicException for an action that declares neither attribute (the subscriber would refuse it)
     */
    public function all(): array
    {
        if ($this->routes !== null) {
            return $this->routes;
        }

        $routes = [];
        $core = PermissionEnforcementSubscriber::byRoute($this->coreRoutePermissions);
        foreach ($this->router->getRouteCollection() as $name => $route) {
            $controller = (string) $route->getDefault('_controller');
            if (!str_starts_with($controller, self::CONTROLLER_NAMESPACE)) {
                if (isset($core[$name])) {
                    $routes[] = new RouteAccess($name, $route->getPath(), $route->getMethods() ?: ['ANY'], $core[$name]);
                }
                continue;
            }

            [$class, $method] = explode('::', $controller) + [1 => '__invoke'];
            $permission = null;

            if (PermissionEnforcementSubscriber::attribute($class, $method, PubliclyAccessible::class) === null) {
                $required = PermissionEnforcementSubscriber::attribute($class, $method, RequiresPermission::class)
                    ?? throw new \LogicException(sprintf('%s (%s) declares no permission.', $name, $controller));
                $permission = $required->permission;
            }

            $routes[] = new RouteAccess($name, $route->getPath(), $route->getMethods() ?: ['ANY'], $permission);
        }

        usort($routes, static fn (RouteAccess $a, RouteAccess $b): int => [$a->path, $a->name] <=> [$b->path, $b->name]);

        return $this->routes = $routes;
    }
}
