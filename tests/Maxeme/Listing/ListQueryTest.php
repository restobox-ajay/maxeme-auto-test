<?php

declare(strict_types=1);

namespace App\Tests\Maxeme\Listing;

use App\Maxeme\Enum\ClientProfileTab;
use App\Maxeme\Listing\ListQuery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ListQueryTest extends TestCase
{
    public function testUnknownValuesFallBackToSafeDefaults(): void
    {
        $query = ListQuery::fromRequest(new Request(['sort' => 'password; DROP', 'dir' => 'sideways', 'page' => '-3', 'limit' => '9999']), ['firstName', 'lastName']);

        self::assertSame('firstName', $query->sort);
        self::assertSame('asc', $query->dir);
        self::assertSame(1, $query->page);
        self::assertSame(20, $query->limit, 'app.js PER_PAGE_OPTS[0]');
    }

    public function testReadsAValidQuery(): void
    {
        $query = ListQuery::fromRequest(new Request(['q' => ' singh ', 'sort' => 'lastName', 'dir' => 'DESC', 'page' => '3', 'limit' => '100']), ['firstName', 'lastName']);

        self::assertSame('singh', $query->search);
        self::assertSame(['lastName', 'desc', 100, 'firstName'], [$query->sort, $query->dir, $query->limit, $query->defaultSort]);
        self::assertSame(200, $query->offset());
    }

    public function testProfileTabAcceptsTheLegacyViewParameter(): void
    {
        self::assertSame(ClientProfileTab::Vehicles, ClientProfileTab::fromRequest(new Request(['view' => 'vehicle'])));
        self::assertSame(ClientProfileTab::Vehicles, ClientProfileTab::fromRequest(new Request(['tab' => 'vehicles'])));
        self::assertSame(ClientProfileTab::Info, ClientProfileTab::fromRequest(new Request(['tab' => 'nonsense'])));
    }
}
