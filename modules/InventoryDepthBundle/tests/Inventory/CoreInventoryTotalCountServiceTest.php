<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Inventory;

use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\Warehouse;
use App\Service\Inventory\CoreInventoryTotalCountService;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Inventory\InventoryModeSwitcher;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use InventoryDepthBundle\Repository\InventoryDetailRepository;

/**
 * A count set through `CoreInventoryTotalCountService` is real, withdrawable stock the moment it
 * lands — the fix for the gap `TransferOrderServiceTest` and `AutomaticSourcePicker` both used to
 * refuse: a product whose quantity arrived through an import, the inline grid box, or the product
 * form had no detail row behind it at all, and a transfer or adjustment checking for one found
 * nothing.
 */
final class CoreInventoryTotalCountServiceTest extends DoctrineIntegrationTestCase
{
    private CoreInventoryTotalCountService $counts;
    private InventoryDetailRepository $details;
    private Warehouse $warehouse;
    private ProductCore $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->counts = self::getContainer()->get(CoreInventoryTotalCountService::class);
        $this->details = self::getContainer()->get(InventoryDetailRepository::class);

        $region = (new FulfillmentRegion())->setName('Total Count Region');
        $this->em->persist($region);
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($region, 'BC', 'CA');

        $this->product = (new ProductCore())->setSku('COUNT-1')->setName('Counted Thing')->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL);
        $this->em->persist($this->product);
        $this->em->flush();
    }

    private function unspecifiedAvailable(): string
    {
        $row = $this->details->findExisting($this->product, $this->warehouse, null, null, null, InventoryDetail::STATUS_AVAILABLE);

        return $row instanceof InventoryDetail ? $row->getQuantity() : '0.0000';
    }

    public function testASetCountWithNoPriorHistoryIsImmediatelyWithdrawable(): void
    {
        $row = $this->counts->setCount($this->product, $this->warehouse, 25);

        self::assertSame('25.0000', $row->getQuantity());
        self::assertSame('25.0000', $this->unspecifiedAvailable());

        // The whole point: a real movement can now draw on it, which is exactly what used to
        // refuse "holds 0 unit(s)" for a product set this way and never otherwise touched.
        $group = self::getContainer()->get(StockMovementService::class)->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_ADJUSTMENT, 'op-count-withdraw')
                ->move(
                    $this->product,
                    new DetailKey($this->warehouse),
                    new DetailKey($this->warehouse, null, null, null, InventoryDetail::STATUS_QUARANTINE),
                    10,
                )
        );

        self::assertSame(InventoryMovementGroup::TYPE_ADJUSTMENT, $group->getType());
        self::assertSame('15.0000', $this->unspecifiedAvailable());
    }

    public function testARepeatedSetCountReconcilesRatherThanDoubling(): void
    {
        $this->counts->setCount($this->product, $this->warehouse, 25);
        $this->counts->setCount($this->product, $this->warehouse, 40);

        self::assertSame('40.0000', $this->unspecifiedAvailable(), 'the second count replaces the first, it does not add to it');
    }

    public function testACountLowerThanTheLastOneIsAcceptedAsACorrectionNotClampedAtZero(): void
    {
        $this->counts->setCount($this->product, $this->warehouse, 20);
        self::assertSame('20.0000', $this->unspecifiedAvailable());

        $row = $this->counts->setCount($this->product, $this->warehouse, 5);

        self::assertSame('5.0000', $row->getQuantity());
        self::assertSame('5.0000', $this->unspecifiedAvailable());
    }

    public function testADimensionalProductIsRefusedRatherThanOverwritten(): void
    {
        self::getContainer()->get(InventoryModeSwitcher::class)->toDimensional($this->product, 'test@example.test');

        $this->expectException(\LogicException::class);
        $this->counts->setCount($this->product, $this->warehouse, 99);
    }

    public function testClearingAReconciliationBucketFeedsTheReconciliation(): void
    {
        self::getContainer()->get(StockMovementService::class)->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'op-count-seed')
                ->receive($this->product, new DetailKey($this->warehouse), 6)
        );

        $row = $this->counts->setCount($this->product, $this->warehouse, 30, clearBuckets: ['received']);

        self::assertSame('30.0000', $row->getQuantity());
        self::assertSame('0.0000', $row->getReceivedQuantity());
        self::assertSame('30.0000', $this->unspecifiedAvailable(), 'the cleared received balance is reflected in the reconciled total');
    }

    public function testClearingMultipleBucketsAtOnceAppliesEachIndependently(): void
    {
        $inventory = $this->em->getRepository(\App\Entity\ProductInventory::class)->findOneBy([
            'product' => $this->product,
            'warehouse' => $this->warehouse,
        ]) ?? (new \App\Entity\ProductInventory())->setProduct($this->product)->setWarehouse($this->warehouse);
        $inventory->setQuarantineQuantity(4)->setReservedQuantity(9);
        $this->em->persist($inventory);
        $this->em->flush();

        $row = $this->counts->setCount($this->product, $this->warehouse, 50, clearBuckets: ['quarantine', 'reserved']);

        self::assertSame('0.0000', $row->getQuarantineQuantity());
        self::assertSame('0.0000', $row->getReservedQuantity());
    }

    public function testClearingANonReconciliationBucketLeavesTheReconciliationUnaffected(): void
    {
        $this->counts->setCount($this->product, $this->warehouse, 20);
        self::assertSame('20.0000', $this->unspecifiedAvailable());

        // Reserved plays no part in heldAvailableTotal() — clearing it must not perturb the row
        // any differently than a no-op reconciliation would.
        $row = $this->counts->setCount($this->product, $this->warehouse, 20, clearBuckets: ['reserved']);

        self::assertSame('0.0000', $row->getReservedQuantity());
        self::assertSame('20.0000', $this->unspecifiedAvailable());
    }

    public function testAnUnknownBucketNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->counts->setCount($this->product, $this->warehouse, 10, clearBuckets: ['notARealBucket']);
    }
}
