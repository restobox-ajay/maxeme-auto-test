<?php

declare(strict_types=1);

namespace AdminMenuBundle\Tests\Unit;

use AdminMenuBundle\Bundle\AdminMenuBundleDescriptor;
use AdminMenuBundle\Menu\AdminMenuMenuItem;
use PHPUnit\Framework\TestCase;

final class AdminMenuMenuItemTest extends TestCase
{
    public function testRouteMatchesTheBundleDescriptorsEditRoute(): void
    {
        $menuItem = new AdminMenuMenuItem();
        $descriptor = new AdminMenuBundleDescriptor();

        self::assertSame($descriptor->getEditRoute(), $menuItem->getRoute());
    }

    public function testExposesANonEmptyLabelAndSection(): void
    {
        $menuItem = new AdminMenuMenuItem();

        self::assertNotSame('', $menuItem->getLabel());
        self::assertNotSame('', $menuItem->getSection());
    }

    /**
     * The label is the bundle's clean display name, never the internal "hiding is not access
     * control" caveat that used to ride along with it (see AdminMenuBundleDescriptor) — this
     * guards against that leaking back into the sidebar link.
     */
    public function testLabelDoesNotCarryTheInternalCaveat(): void
    {
        $menuItem = new AdminMenuMenuItem();

        self::assertSame('Admin Menu', $menuItem->getLabel());
    }
}
