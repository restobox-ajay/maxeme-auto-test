<?php

declare(strict_types=1);

namespace App\Tests\Maxeme\Security;

use App\Maxeme\Security\Authorization\RouteAccessMap;
use App\Maxeme\Security\Permission;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every Maxeme route reaches an action that declares its access (RouteAccessMap throws otherwise),
 * and every declared permission is a real Permission name, so a typo cannot silently lock a screen.
 */
final class ControllerPermissionCoverageTest extends KernelTestCase
{
    public function testEveryMaxemeRouteDeclaresAKnownPermissionOrIsPublic(): void
    {
        self::bootKernel();
        $known = array_merge(...array_values(Permission::byArea()));
        $routes = self::getContainer()->get(RouteAccessMap::class)->all();

        foreach ($routes as $route) {
            if (!$route->isPublic()) {
                self::assertContains($route->permission, $known, sprintf('%s requires an unknown permission.', $route->name));
            }
        }

        self::assertGreaterThan(50, count($routes));
    }

    public function testEveryPermissionBelongsToANamedArea(): void
    {
        foreach ((new \ReflectionClass(Permission::class))->getConstants() as $value) {
            if (is_string($value)) {
                self::assertArrayHasKey(Permission::areaOf($value), Permission::AREAS, $value);
            }
        }
    }
}
