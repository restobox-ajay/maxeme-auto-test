<?php

declare(strict_types=1);

namespace InventoryDepthBundle\Tests\Shipment;

use App\Entity\FulfillmentRegion;
use App\Entity\ProductCore;
use App\Entity\TrackingPolicy;
use App\Entity\Warehouse;
use App\Service\WarehouseFulfillmentRegionService;
use App\Tests\DoctrineIntegrationTestCase;
use InventoryDepthBundle\Entity\InventoryDetail;
use InventoryDepthBundle\Entity\InventoryLot;
use InventoryDepthBundle\Entity\InventoryMovementGroup;
use InventoryDepthBundle\Movement\DetailKey;
use InventoryDepthBundle\Movement\MovementRequest;
use InventoryDepthBundle\Movement\StockMovementService;
use InventoryDepthBundle\Shipment\ShipmentAllocationPlanner;

/**
 * `ShipmentAllocationPlanner` — the step-2 form's prefill, not an authority: `ShipmentServiceTest`
 * is what proves the real draw at `ship()` time behaves correctly; this only proves the suggestion
 * is the right shape to show an admin before they submit.
 */
final class ShipmentAllocationPlannerTest extends DoctrineIntegrationTestCase
{
    private ShipmentAllocationPlanner $planner;
    private StockMovementService $movements;
    private Warehouse $warehouse;
    private ProductCore $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->planner = self::getContainer()->get(ShipmentAllocationPlanner::class);
        $this->movements = self::getContainer()->get(StockMovementService::class);

        $region = (new FulfillmentRegion())->setName('Planner Region');
        $this->em->persist($region);
        $this->warehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($region, 'BC', 'CA');

        $policy = (new TrackingPolicy())->setName('Lot Planner')->setMode(TrackingPolicy::MODE_LOT)->setTrackOut(true);
        $this->em->persist($policy);

        $this->product = (new ProductCore())
            ->setSku('PLAN-LOT-1')
            ->setName('Lot Tracked Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL)
            ->setTrackingPolicy($policy);
        $this->em->persist($this->product);
        $this->em->flush();
    }

    public function testPlansAcrossTwoLotsInFefoOrder(): void
    {
        $older = $this->lotWithStock('BATCH-OLD', '2027-01-01', 3, $this->warehouse);
        $newer = $this->lotWithStock('BATCH-NEW', '2027-06-01', 10, $this->warehouse);

        // The outstanding quantity and the suggestions are all decimal strings, same shape the
        // form renders — the planner stopped counting physical units when `QuantityType` became a
        // decimal type.
        $plan = $this->planner->planLotAllocations($this->product, $this->warehouse, '5');

        self::assertCount(2, $plan);
        self::assertSame($older->getId(), $plan[0]['lotId']);
        self::assertSame('3.0000', $plan[0]['suggested']);
        self::assertSame($newer->getId(), $plan[1]['lotId']);
        self::assertSame('2.0000', $plan[1]['suggested']);
    }

    /**
     * Every candidate lot gets a row, even a zero-suggested one — so the form has one editable box
     * per lot and never needs a client-side "add another lot" control.
     */
    public function testEveryCandidateLotGetsARowEvenAtZeroSuggested(): void
    {
        $this->lotWithStock('BATCH-A', '2027-01-01', 10, $this->warehouse);
        $this->lotWithStock('BATCH-B', '2027-06-01', 10, $this->warehouse);

        $plan = $this->planner->planLotAllocations($this->product, $this->warehouse, '4');

        self::assertCount(2, $plan);
        self::assertSame('4.0000', $plan[0]['suggested']);
        self::assertSame('10.0000', $plan[0]['available']);
        self::assertSame('0.0000', $plan[1]['suggested'], 'the second lot is a candidate row, just not needed by the suggestion');
        self::assertSame('10.0000', $plan[1]['available']);
    }

    public function testDoesNotDrawStockSittingInAnotherWarehouse(): void
    {
        $region = (new FulfillmentRegion())->setName('Elsewhere');
        $this->em->persist($region);
        $otherWarehouse = self::getContainer()->get(WarehouseFulfillmentRegionService::class)
            ->createWarehouseForRegion($region, 'AB', 'CA');

        $this->lotWithStock('BATCH-ELSEWHERE', '2027-01-01', 10, $otherWarehouse);

        $plan = $this->planner->planLotAllocations($this->product, $this->warehouse, '5');

        self::assertSame([], $plan);
    }

    public function testAvailableSerialsListsOnlyWhatIsStillAvailable(): void
    {
        $serialPolicy = (new TrackingPolicy())->setName('Serial Planner')->setMode(TrackingPolicy::MODE_SERIAL)->setTrackOut(true);
        $this->em->persist($serialPolicy);

        $serialProduct = (new ProductCore())
            ->setSku('PLAN-SERIAL-1')
            ->setName('Serialised Widget')
            ->setSyncSource(ProductCore::SYNC_SOURCE_MANUAL)
            ->setInventoryMode(ProductCore::INVENTORY_MODE_DIMENSIONAL)
            ->setTrackingPolicy($serialPolicy);
        $this->em->persist($serialProduct);
        $this->em->flush();

        foreach (['SN-1', 'SN-2'] as $serial) {
            $this->movements->apply(
                MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'seed-' . $serial)
                    ->receive($serialProduct, new DetailKey($this->warehouse, null, null, $serial, InventoryDetail::STATUS_AVAILABLE), 1),
            );
        }
        // A third unit that already shipped — must not appear in the picker.
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'seed-SN-3')
                ->receive($serialProduct, new DetailKey($this->warehouse, null, null, 'SN-3', InventoryDetail::STATUS_AVAILABLE), 1),
        );
        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_SHIP, 'ship-SN-3', null, null, 'SHIPPED-OUT')
                ->move($serialProduct, new DetailKey($this->warehouse, null, null, 'SN-3', InventoryDetail::STATUS_AVAILABLE), new DetailKey($this->warehouse, null, null, 'SN-3', InventoryDetail::STATUS_SOLD), 1),
        );
        $this->em->flush();

        $serials = $this->planner->availableSerials($serialProduct, $this->warehouse);

        self::assertSame(['SN-1', 'SN-2'], $serials);
    }

    private function lotWithStock(string $code, string $expiry, int $quantity, Warehouse $warehouse): InventoryLot
    {
        $lot = (new InventoryLot())->setProduct($this->product)->setCode($code)->setExpiry(new \DateTimeImmutable($expiry));
        $this->em->persist($lot);
        $this->em->flush();

        $this->movements->apply(
            MovementRequest::of(InventoryMovementGroup::TYPE_RECEIPT, 'seed-' . $code)
                ->receive($this->product, new DetailKey($warehouse, null, $lot, null, InventoryDetail::STATUS_AVAILABLE), $quantity),
        );
        $this->em->flush();

        return $lot;
    }
}
