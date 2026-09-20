<?php

declare(strict_types=1);

namespace App\Tests\Service\Inventory;

use App\Entity\Warehouse;
use App\Entity\OrderInventoryReservation;
use App\Service\Inventory\OrderInventoryBucketResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderInventoryBucketResolverTest extends TestCase
{
    #[DataProvider('statusProvider')]
    public function testBucketForStatus(string $status, ?string $expectedBucket): void
    {
        self::assertSame($expectedBucket, OrderInventoryBucketResolver::bucketForStatus($status));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function statusProvider(): iterable
    {
        // #539 stage 3: an order holds sales hold on its UNINVOICED quantity, for every status from
        // Approved onward. Invoiced and Closed name the bucket too — they simply have nothing left
        // uninvoiced to put in it, which is a fact about the quantity and not about the status.
        yield 'Approved' => ['Approved', OrderInventoryReservation::BUCKET_SALES_HOLD];
        yield 'Partially Invoiced' => ['Partially Invoiced', OrderInventoryReservation::BUCKET_SALES_HOLD];
        yield 'Invoiced' => ['Invoiced', OrderInventoryReservation::BUCKET_SALES_HOLD];
        yield 'Closed' => ['Closed', OrderInventoryReservation::BUCKET_SALES_HOLD];
        yield 'lowercase approved' => ['approved', OrderInventoryReservation::BUCKET_SALES_HOLD];
        yield 'padded whitespace' => ['  Approved  ', OrderInventoryReservation::BUCKET_SALES_HOLD];
        yield 'lowercase partially invoiced' => ['partially invoiced', OrderInventoryReservation::BUCKET_SALES_HOLD];
        yield 'lowercase closed' => ['  closed ', OrderInventoryReservation::BUCKET_SALES_HOLD];

        // The two that hold nothing: one has not been accepted, the other has been withdrawn.
        yield 'Draft holds nothing' => ['Draft', null];
        yield 'Void holds nothing' => ['Void', null];

        // Retired statuses must hold no stock, so a stray row can never quietly reserve inventory.
        // Pending, Processing, Completed and Cancelled are the INVOICE's statuses from stage 2 on;
        // an ORDER row still carrying one is a pre-migration stray and holds nothing.
        yield 'retired Pending holds nothing' => ['Pending', null];
        yield 'retired Processing holds nothing' => ['Processing', null];
        yield 'retired Completed holds nothing' => ['Completed', null];
        yield 'retired Cancelled holds nothing' => ['Cancelled', null];
        yield 'Canceled (US spelling) holds nothing' => ['Canceled', null];
        yield 'retired Accepted Quotes holds nothing' => ['Accepted Quotes', null];
        yield 'retired Awaiting Payment holds nothing' => ['Awaiting Payment', null];
        yield 'unknown status holds nothing' => ['Some Weird Legacy Status', null];
        yield 'blank status holds nothing' => ['', null];
    }

    /**
     * The list InventoryRecalcCommand pre-filters its order query with has to be exactly the set
     * bucketForStatus() answers for, or the recalc would zero the sales hold of every order it
     * failed to fetch — and it would do so silently, since a missing order looks identical to one
     * holding nothing.
     */
    public function testSalesHoldStatusesAgreeWithBucketForStatus(): void
    {
        $statuses = OrderInventoryBucketResolver::salesHoldStatuses();

        self::assertSame(['approved', 'partially invoiced', 'invoiced', 'closed'], $statuses);

        foreach ($statuses as $status) {
            self::assertSame(
                OrderInventoryReservation::BUCKET_SALES_HOLD,
                OrderInventoryBucketResolver::bucketForStatus($status),
                sprintf('%s is in the pre-filter list but resolves to no bucket', $status),
            );
        }
    }

    public function testResolveLineWarehousePrefersLineLocationOverOrderHeader(): void
    {
        $west = (new Warehouse())->setName('West');
        $east = (new Warehouse())->setName('East');
        $map = ['west' => $west, 'east' => $east];

        $resolved = OrderInventoryBucketResolver::resolveLineWarehouse('West', 'East', $map);

        self::assertSame($west, $resolved);
    }

    public function testResolveLineWarehouseFallsBackToOrderHeaderWhenLineBlank(): void
    {
        $east = (new Warehouse())->setName('East');
        $map = ['east' => $east];

        self::assertSame($east, OrderInventoryBucketResolver::resolveLineWarehouse('', 'East', $map));
        self::assertSame($east, OrderInventoryBucketResolver::resolveLineWarehouse(null, 'East', $map));
    }

    public function testResolveLineWarehouseIsCaseInsensitive(): void
    {
        $west = (new Warehouse())->setName('West');
        $map = ['west' => $west];

        self::assertSame($west, OrderInventoryBucketResolver::resolveLineWarehouse('  WEST  ', null, $map));
    }

    public function testResolveLineWarehouseReturnsNullWhenUnresolvable(): void
    {
        self::assertNull(OrderInventoryBucketResolver::resolveLineWarehouse('Nowhere', null, []));
        self::assertNull(OrderInventoryBucketResolver::resolveLineWarehouse(null, null, []));
        self::assertNull(OrderInventoryBucketResolver::resolveLineWarehouse('', '', ['west' => (new Warehouse())->setName('West')]));
    }
}
