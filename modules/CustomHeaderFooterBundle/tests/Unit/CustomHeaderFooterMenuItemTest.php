<?php

declare(strict_types=1);

namespace CustomHeaderFooterBundle\Tests\Unit;

use CustomHeaderFooterBundle\Bundle\CustomHeaderFooterBundleDescriptor;
use CustomHeaderFooterBundle\Menu\CustomHeaderFooterMenuItem;
use PHPUnit\Framework\TestCase;

final class CustomHeaderFooterMenuItemTest extends TestCase
{
    public function testRouteMatchesTheBundleDescriptorsEditRoute(): void
    {
        $menuItem = new CustomHeaderFooterMenuItem();
        $descriptor = new CustomHeaderFooterBundleDescriptor();

        self::assertSame($descriptor->getEditRoute(), $menuItem->getRoute());
    }

    public function testExposesANonEmptyLabelAndSection(): void
    {
        $menuItem = new CustomHeaderFooterMenuItem();

        self::assertNotSame('', $menuItem->getLabel());
        self::assertNotSame('', $menuItem->getSection());
    }
}
