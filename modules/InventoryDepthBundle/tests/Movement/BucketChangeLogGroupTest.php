<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Movement;

use App\Entity\FulfillmentRegion;
use App\Entity\InventoryBucketChangeLog;
use App\Entity\ProductCore;
use App\Entity\ProductInventory;
use App\Entity\Warehouse;
use App\Service\Inventory\InventoryOperationContext;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use InventoryDepthBundle\Entity\WarehouseLocation;

/**
 * `inventory_bucket_change_log.group_id`: which physical operation moved a bucket, and the two-state
 * fact that NULL is (#582).
 *
 * StockMovementService was one of the two services that logged nothing at all before this — it
 * wrote `received`, `transfer_out` and `quarantine` and no call site remembered to record any of
 * them. These tests assert the opposite end of that: not merely that it logs now, but that its rows
 * name the movement group, so "what moved this stock" is a join rather than a guess.
 *
 * The rows are read straight out of the table with no service in between, deliberately. A helper
 * that reads them through something is a helper that can agree with a broken listener.
 */
final class BucketChangeLogGroupTest extends DoctrineIntegrationTestCase
{
    private StockMovementService $movements;
    private Warehouse $warehouse;
    private ProductCore $product;
    private WarehouseLocation $bin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->movements = self::getContainer()->get(StockMovementService::class);

        $region = (new FulfillmentRegion())->setName('West');
        $this->em->persist($region);
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($region, 'BC', 'CA');

        $this->product = (new ProductCore())
            ->setSku('GROUPED-1')
            ->setName('Grouped Product')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL);
        $this->em->persist($this->product);

        $this->bin = (new WarehouseLocation())->setWarehouse($this->warehouse)->setCode('A-01')->setSortKey(10);
        $this->em->persist($this->bin);

        $this->em->flush();
    }

    /** @return list<InventoryBucketChangeLog> */
    private function entries(): array
    {
        return $this->em->getRepository(InventoryBucketChangeLog::class)->findBy([], ['id' => 'ASC']);
    }

    private function key(string $status = InventoryDetail::STATUS_AVAILABLE): DetailKey
    {
        return new DetailKey($this->warehouse, $this->bin, null, null, $status);
    }

    public function testAReceiptRecordsItsReceivedBucketAgainstTheGroupThatMovedTheStock(): void
    {
        $group = $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'op-receipt-1')
                ->receive($this->product, $this->key(), 30)
        );

        $entries = $this->entries();
        self::assertCount(1, $entries, 'a receipt raises `received`, and that is exactly one bucket change — the bucket StockMovementService never used to log');
        self::assertSame(InventoryBucketChangeLog::BUCKET_RECEIVED, $entries[0]->getBucket());
        self::assertSame('0.0000', $entries[0]->getPreviousQuantity());
        self::assertSame('30.0000', $entries[0]->getNewQuantity());
        self::assertSame(
            $group->getId(),
            $entries[0]->getGroupId(),
            'group_id NOT NULL is what says a physical operation caused this, and it points at the operation that did',
        );
    }

    public function testTheActionNamesTheKindOfMovementWithoutJoiningToTheGroup(): void
    {
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'op-receipt-2')
                ->receive($this->product, $this->key(), 10)
        );

        self::assertSame(
            'movement_receipt',
            $this->entries()[0]->getAction(),
            'a receipt and a transfer are already distinguishable in the log itself — `action` stays useful even where group_id does the same job',
        );
    }

    public function testStockGoingOutOfTheSellableTotalIsLoggedTooAndNotOnlyStockComingIn(): void
    {
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'op-receipt-3')
                ->receive($this->product, $this->key(), 20)
        );

        $damaged = $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_STATUS_CHANGE, 'op-damage-1')
                ->move($this->product, $this->key(), $this->key(InventoryDetail::STATUS_DAMAGED), 6)
        );

        $entries = $this->entries();
        self::assertCount(2, $entries, 'the receipt and the damage are two bucket changes, by two operations');

        // Two DIFFERENT buckets since #581. `received` records what arrived and keeps recording it;
        // the six damaged units come off availability through `write_off`, which is the bucket whose
        // ledger is the detail rows. An earlier draft of this test expected `received: 20 → 14`,
        // because on the branch it was written against `write_off` did not exist and `received` was
        // the only term that could absorb a loss.
        self::assertSame(InventoryBucketChangeLog::BUCKET_RECEIVED, $entries[0]->getBucket());
        self::assertSame(
            InventoryBucketChangeLog::BUCKET_WRITE_OFF,
            $entries[1]->getBucket(),
            'a write-off bucket that no listener knew about is exactly the silent gap #582 exists to prevent',
        );
        self::assertSame('0.0000', $entries[1]->getPreviousQuantity());
        self::assertSame('6.0000', $entries[1]->getNewQuantity(), 'damaged stock leaves the sellable total through `write_off`');
        self::assertSame('movement_status_change', $entries[1]->getAction());
        self::assertSame($damaged->getId(), $entries[1]->getGroupId(), 'the second row names the SECOND group, not the first');
    }

    public function testABucketChangeWithNoMovementBehindItKeepsANullGroup(): void
    {
        // Same table, same flush machinery, no physical operation. The two states have to be
        // distinguishable or `group_id IS NULL` stops separating promises from movements.
        $row = (new ProductInventory())->setProduct($this->product)->setWarehouse($this->warehouse)->setQuantity(5);
        $this->em->persist($row);
        $this->em->flush();

        self::getContainer()->get(InventoryOperationContext::class)->run('order_reconciled', function () use ($row): void {
            $row->setSalesHoldQuantity(3);
            $this->em->flush();
        });

        $entries = $this->entries();
        self::assertCount(1, $entries);
        self::assertNull($entries[0]->getGroupId(), 'a sales hold moves no stock, so it is NULL by construction rather than by omission');
        self::assertSame('order_reconciled', $entries[0]->getAction(), 'and `action` is the only thing left to identify it, which is why that column stays');
    }

    public function testTheLoggedQuantitiesAreTheRecomputeResultAndNotTheOperationsOwnIncrement(): void
    {
        // The distinction the column comment insists on. `received` accumulates and `write_off` is
        // recomputed from the detail rows, so the pair of numbers on a row is the bucket's before
        // and after — subtracting them is the reader's job, and it will not equal this operation's
        // line quantities wherever anything else touched the same bucket.
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'op-receipt-4')
                ->receive($this->product, $this->key(), 25)
        );
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'op-receipt-5')
                ->receive($this->product, $this->key(), 15)
        );

        $entries = $this->entries();
        self::assertCount(2, $entries);
        self::assertSame(['0.0000', '25.0000'], [$entries[0]->getPreviousQuantity(), $entries[0]->getNewQuantity()]);
        self::assertSame(
            ['25.0000', '40.0000'],
            [$entries[1]->getPreviousQuantity(), $entries[1]->getNewQuantity()],
            'the second receipt of 15 records 25 -> 40, the bucket total, not the 15 it contributed',
        );
    }
}
