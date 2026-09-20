<?php

declare(strict_types=1);

namespace App\Tests\Menu\Core;

use App\Menu\Core\ContactMenuItem;
use PHPUnit\Framework\TestCase;

final class ContactMenuItemTest extends TestCase
{
    public function testContract(): void
    {
        $item = new ContactMenuItem();

        self::assertSame('core.contact', $item->getKey());
        self::assertSame('Contact', $item->getLabel());
        self::assertSame('customer_contact', $item->getRoute());
        self::assertSame([], $item->getRouteParams());
        self::assertSame('Core', $item->getSource());
        self::assertTrue($item->isVisible());
    }
}
