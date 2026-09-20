<?php

declare(strict_types=1);

namespace CartHoldBundle\Tests\Unit;

use CartHoldBundle\Bundle\CartHoldBundleDescriptor;
use CartHoldBundle\Menu\CartHoldMenuItem;
use PHPUnit\Framework\TestCase;

final class CartHoldMenuItemTest extends TestCase
{
    public function testRouteMatchesTheBundleDescriptorsEditRoute(): void
    {
        $menuItem = new CartHoldMenuItem();
        $descriptor = new CartHoldBundleDescriptor();

        self::assertSame($descriptor->getEditRoute(), $menuItem->getRoute());
    }

    public function testExposesANonEmptyLabelAndSection(): void
    {
        $menuItem = new CartHoldMenuItem();

        self::assertNotSame('', $menuItem->getLabel());
        self::assertNotSame('', $menuItem->getSection());
    }
}
