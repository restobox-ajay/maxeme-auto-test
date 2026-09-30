<?php

declare(strict_types=1);

namespace App\Tests\Maxeme\Menu;

use App\Maxeme\Menu\MaxemeAdminMenuProvider;
use App\Menu\Admin\AdminMenuCatalog;
use App\Menu\Admin\AdminMenuTreeBuilder;
use App\Repository\BundleStatusRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class MaxemeAdminMenuProviderTest extends KernelTestCase
{
    private const MENU = [
        ['key' => 'schedule', 'label' => 'Schedule', 'icon' => 'calendar', 'children' => [
            ['key' => 'appointments', 'label' => 'Appointments', 'route' => 'maxeme_appointment_index', 'match' => ['maxeme_appointment_']],
        ]],
        ['key' => 'config', 'label' => 'Config', 'icon' => 'cog', 'role' => 'ROLE_SUPER_ADMIN', 'children' => [
            ['key' => 'manage_admins', 'label' => 'Manage Admins', 'route' => 'maxeme_staff_index'],
        ]],
    ];

    public function testHidesEveryCoreEntryAndIsAlwaysActive(): void
    {
        $provider = self::provider(self::MENU);

        self::assertSame(AdminMenuCatalog::keys(), $provider->getHiddenKeys());
        self::assertSame(BundleStatusRepository::CORE_SOURCE, $provider->getSource());
    }

    public function testBuildsTheConfiguredGroupsInOrderWithRolesAndHighlighting(): void
    {
        $tree = self::getContainer()->get(AdminMenuTreeBuilder::class)->build([self::provider(self::MENU)]);

        self::assertSame(['maxeme.schedule', 'maxeme.config'], array_map(static fn (array $entry): string => $entry['node']->key, $tree));

        [$schedule, $config] = $tree;
        self::assertNull($schedule['node']->requiresRole);
        self::assertSame('ROLE_SUPER_ADMIN', $config['node']->requiresRole);
        self::assertNotNull($schedule['node']->icon);

        $appointments = $schedule['children'][0];
        self::assertSame('maxeme_appointment_index', $appointments->route);
        self::assertTrue($appointments->matchesRoute('maxeme_appointment_edit'), 'a child highlights for its match prefixes');
        self::assertTrue($schedule['node']->matchesRoute('maxeme_appointment_edit'), 'the group opens for its children');
        self::assertFalse($schedule['node']->matchesRoute('maxeme_staff_index'));
    }

    public function testARowAddGivesItAPlusThatOpensTheAddModal(): void
    {
        $menu = [['key' => 'parts', 'label' => 'Parts', 'icon' => 'wrench', 'children' => [
            ['key' => 'services', 'label' => 'Services', 'route' => 'maxeme_service_index', 'role' => '/service/view',
                'add' => ['label' => 'Add a new service', 'route' => 'maxeme_service_index', 'modal' => 'manageAddModal', 'role' => '/service/edit']],
        ]]];
        [$parts] = self::getContainer()->get(AdminMenuTreeBuilder::class)->build([self::provider($menu)]);

        self::assertCount(1, $parts['children'], 'the + is not a row of its own');
        $plus = $parts['affordances']['maxeme.parts.services'][0] ?? null;
        self::assertNotNull($plus, 'the + sits on the Services row');
        self::assertSame('/admin/services#manageAddModal', $plus->url);
        self::assertSame('/service/edit', $plus->requiresRole);
        self::assertSame('Add a new service', $plus->affordanceName());
    }

    /** @param list<array<string, mixed>> $menu */
    private static function provider(array $menu): MaxemeAdminMenuProvider
    {
        self::bootKernel();

        return new MaxemeAdminMenuProvider($menu, self::getContainer()->get(UrlGeneratorInterface::class));
    }
}
