<?php

declare(strict_types=1);

namespace App\Tests\Maxeme\Security;

use App\Entity\AdminUser;
use App\Maxeme\EventSubscriber\PermissionEnforcementSubscriber;
use App\Maxeme\Security\Permission;
use App\Maxeme\Security\StaffRole;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/** The core / module parts pages follow the shop's Parts permissions; every other core page stays Super Admin only. */
final class CorePageAccessTest extends KernelTestCase
{
    public function testEveryCoreRouteGivenToStaffExistsAndNeedsAKnownPermission(): void
    {
        self::bootKernel();
        $routes = self::getContainer()->get(RouterInterface::class)->getRouteCollection();
        $known = array_merge(...array_values(Permission::byArea()));

        foreach (self::getContainer()->getParameter('maxeme.rbac.core_route_permissions') as $permission => $names) {
            self::assertContains($permission, $known);
            foreach ($names as $name) {
                self::assertNotNull($routes->get($name), sprintf('%s is not a route (renamed in core?).', $name));
            }
        }
    }

    public function testAViewRoleSeesTheCataloguesButCannotChangeIt(): void
    {
        self::bootKernel();
        $this->signInAs(StaffRole::Technician);

        $this->reach('admin_product_detail_index', '/admin/product/detail/index');
        $this->reach('admin_bundle_inventory_depth_movements', '/admin/bundles/inventory-depth/movements');

        foreach (['admin_product_inventory_create' => '/admin/product/inventory/create', 'admin_bundle_inventory_depth_adjust' => '/admin/bundles/inventory-depth/adjust'] as $route => $path) {
            try {
                $this->reach($route, $path);
                self::fail($route . ' needs Parts edit.');
            } catch (AccessDeniedHttpException $denied) {
                self::assertStringContainsString('/parts/edit', $denied->getMessage());
            }
        }
    }

    public function testAnEditRoleChangesItButOtherCorePagesStaySuperAdminOnly(): void
    {
        self::bootKernel();
        $this->signInAs(StaffRole::Admin);

        $this->reach('admin_product_inventory_create', '/admin/product/inventory/create');
        $this->reach('admin_bundle_procurement_vendor_save', '/admin/bundles/procurement/vendors/save');

        $this->expectException(AccessDeniedHttpException::class);
        $this->reach('admin_bundle_procurement_purchase_orders', '/admin/bundles/procurement/purchase-orders');
    }

    private function signInAs(StaffRole $role): void
    {
        $user = (new AdminUser())->setEmail('staff@example.invalid')->setPassword('x')->setRoles([$role->value]);
        self::getContainer()->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'admin', $user->getRoles()));
    }

    /** Runs the access check for a core page; throws AccessDeniedHttpException when refused. */
    private function reach(string $route, string $path): void
    {
        $request = Request::create($path);
        $request->attributes->set('_route', $route);
        $event = new ControllerEvent(self::$kernel, [new \ArrayObject(), 'count'], $request, HttpKernelInterface::MAIN_REQUEST);

        self::getContainer()->get(PermissionEnforcementSubscriber::class)->onKernelController($event);
        $this->addToAssertionCount(1);
    }
}
