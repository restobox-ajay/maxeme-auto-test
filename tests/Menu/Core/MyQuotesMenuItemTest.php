<?php

declare(strict_types=1);

namespace App\Tests\Menu\Core;

use App\Menu\Core\MyQuotesMenuItem;
use PHPUnit\Framework\TestCase;

final class MyQuotesMenuItemTest extends TestCase
{
    public function testContract(): void
    {
        $item = new MyQuotesMenuItem();

        self::assertSame('core.my_quotes', $item->getKey());
        self::assertSame('My Quotes', $item->getLabel());
        self::assertSame('customer_estimates', $item->getRoute());
        self::assertSame([], $item->getRouteParams());
        self::assertSame('Core', $item->getSource());
        self::assertTrue($item->isVisible());
    }
}
