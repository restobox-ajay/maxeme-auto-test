<?php

declare(strict_types=1);

namespace App\Tests\Menu\Core;

use App\Menu\Core\MyOrdersMenuItem;
use PHPUnit\Framework\TestCase;

final class MyOrdersMenuItemTest extends TestCase
{
    public function testContract(): void
    {
        $item = new MyOrdersMenuItem();

        self::assertSame('core.my_orders', $item->getKey());
        self::assertSame('My Orders', $item->getLabel());
        self::assertSame('customer_orders', $item->getRoute());
        self::assertSame([], $item->getRouteParams());
        self::assertSame('Core', $item->getSource());
        self::assertTrue($item->isVisible());
    }
}
