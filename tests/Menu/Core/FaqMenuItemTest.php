<?php

declare(strict_types=1);

namespace App\Tests\Menu\Core;

use App\Menu\Core\FaqMenuItem;
use PHPUnit\Framework\TestCase;

final class FaqMenuItemTest extends TestCase
{
    public function testContract(): void
    {
        $item = new FaqMenuItem();

        self::assertSame('core.faq', $item->getKey());
        self::assertSame('FAQ', $item->getLabel());
        self::assertSame('customer_faq', $item->getRoute());
        self::assertSame([], $item->getRouteParams());
        self::assertSame('Core', $item->getSource());
        self::assertTrue($item->isVisible());
    }
}
